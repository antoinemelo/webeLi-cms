<?php

declare(strict_types=1);

namespace App\Core;

use App\Application\Admin\AdminAuthController;
use App\Application\Admin\AdminSpaController;
use App\Application\Api\Admin\AiAssistantApiController;
use App\Application\Api\Admin\AdminContextApiController;
use App\Application\Api\Admin\BlockBlueprintApiController;
use App\Application\Api\Admin\CapabilityApiController;
use App\Application\Api\Admin\BlueprintApiController;
use App\Application\Api\Admin\BusinessCatalogApiController;
use App\Application\Api\Admin\BusinessCrmApiController;
use App\Application\Api\Admin\BusinessMessagingApiController;
use App\Application\Api\Admin\BusinessMailingApiController;
use App\Application\Api\Admin\BusinessPimApiController;
use App\Application\Api\Admin\ContentEntryApiController;
use App\Application\Api\Admin\ContentRevisionApiController;
use App\Application\Api\Admin\ConfigurationApiController;
use App\Application\Api\Admin\CookieConsentApiController;
use App\Application\Api\Admin\DocsApiController;
use App\Application\Api\Admin\ContentTypeApiController;
use App\Application\Api\Admin\MediaApiController;
use App\Application\Api\Admin\MaintenanceApiController;
use App\Application\Api\Admin\ModuleAdminApiController;
use App\Application\Api\Admin\FormApiController;
use App\Application\Api\Admin\IamAdminApiController;
use App\Application\Api\Admin\MenuApiController;
use App\Application\Api\Admin\MultisiteApiController;
use App\Application\Api\Admin\PreviewApiController;
use App\Application\Api\Admin\ProfileApiController;
use App\Application\Api\Admin\SeoAuditApiController;
use App\Application\Api\Admin\ImportsExportsApiController;
use App\Application\Api\Admin\SecurityAdminApiController;
use App\Application\Api\Admin\SaleAdminApiController;
use App\Application\Api\Admin\TaxonomyApiController;
use App\Application\Api\Admin\VisualEditingApiController;
use App\Application\Api\PublicHeadlessController;
use App\Application\Api\ModuleHeadlessSchemaController;
use App\Application\Frontend\UpdateManifestController;
use App\Application\Maintenance\DependencyInventoryService;
use App\Application\Maintenance\VersionInventoryService;
use App\Application\PublicApi\PublicApiKernel;
use App\Application\PublicApi\PublicContentApiHandler;
use App\Application\PublicApi\PublicSearchApiHandler;
use App\Application\PublicApi\PublicTaxonomyApiHandler;
use App\Application\PublicApi\PublicFormApiHandler;
use App\Application\PublicApi\PublicCookieConsentApiHandler;
use App\Application\PublicApi\PublicCatalogApiHandler;
use App\Application\PublicApi\PosCatalogApiHandler;
use App\Application\PublicApi\PublicSaleApiHandler;
use App\Application\PublicApi\PublicCustomerAccountApiHandler;
use App\Application\Configuration\ConfigurationRepository;
use App\Application\Configuration\MultisiteRepository;
use App\Application\Frontend\HomeController;
use App\Application\Frontend\BusinessMemoShareController;
use App\Application\Frontend\BusinessUnsubscribeController;
use App\Application\Frontend\PublicApiDocsController;
use App\Application\Frontend\RouteResolutionController;
use App\Application\Frontend\PublicSaleCheckoutController;
use App\Application\Frontend\PublicStorefrontCartController;
use App\Application\Frontend\PublicCustomerAccountController;
use App\Security\AdminApiRequestGuard;
use App\Security\PublicApiCorsGuard;
use App\Security\PublicApiRateLimitGuard;
use App\Security\PublicApiTokenGuard;
use App\Security\SessionManager;
use App\Security\SecurityHeaders;

final class App
{
    private Database $coreDb;
    private Database $iamDb;
    private ?Database $formsDb = null;
    private ?Database $cookiesDb = null;
    private Request $request;
    private Logger $logger;

    public function __construct(private readonly array $config)
    {
        date_default_timezone_set((string) $config['app']['timezone']);
        $this->request = Request::capture($config);
        $this->logger = new Logger(base_path('storage/logs/app.log'));

        if ($this->shouldStartSession()) {
            SessionManager::start($config);
        }

        $this->coreDb = new Database($config['databases']['core']['path']);
        $this->iamDb = new Database($config['databases']['iam']['path']);
        if (isset($config['databases']['forms']['path'])) {
            $this->formsDb = new Database($config['databases']['forms']['path']);
        }
        if (isset($config['databases']['cookies']['path'])) {
            $this->cookiesDb = new Database($config['databases']['cookies']['path']);
        }

        if ($this->autoMaintenanceEnabled() || $this->storageBootstrapRequired()) {
            Installer::ensure($this->coreDb, $this->iamDb, $this->logger, $this->formsDb, $this->cookiesDb);
        }
    }

    public function handle(): Response
    {
        Response::resetRequestId($this->request->server['HTTP_X_REQUEST_ID'] ?? null);
        $GLOBALS['CMS_CURRENT_REQUEST'] = $this->request;
        $services = new ServiceFactory($this->coreDb, $this->iamDb, $this->formsDb, $this->cookiesDb, $this->config, $this->logger);
        $this->request = $services->sites()->withResolvedSiteContext($this->request);
        $_SERVER = array_replace($_SERVER, $this->request->server);

        try {
            if ($this->autoMaintenanceEnabled()) {
                $services->modules()->syncManifest();
                $services->modules()->applyMigrations();
                $services->modules()->syncContentTypes();
            }

            if ($this->isAdminApiRequest()) {
                (new AdminApiRequestGuard($this->request, $services->auth(), $this->config))->enforce();
            }

            if ($this->isPublicApiRequest()) {
                $corsGuard = new PublicApiCorsGuard($this->request, $services->coreDatabase(), $this->config);
                $corsResponse = $corsGuard->enforce();
                if ($corsResponse !== null) {
                    return $this->secureResponse($corsResponse);
                }

                $tokenAuthResponse = (new PublicApiTokenGuard($this->request, $services->auth()->database(), $this->config))->enforce();
                if ($tokenAuthResponse !== null) {
                    return $this->secureResponse($tokenAuthResponse);
                }

                $rateLimitResponse = (new PublicApiRateLimitGuard($this->request, $services->auth()->database(), $this->config))->enforce();
                if ($rateLimitResponse !== null) {
                    return $this->secureResponse($rateLimitResponse);
                }
            }

            $routes = $this->routes($services);
            $match = (new Router())->match($this->request->method, $this->request->path, $routes);
            if (!$match) {
                return $this->secureResponse($this->isApiRequest()
                    ? Response::error(ErrorCode::ROUTE_NOT_FOUND, 'La route API demandée est introuvable.', ErrorCode::httpStatus(ErrorCode::ROUTE_NOT_FOUND))
                    : Response::html('<h1>404</h1><p>Page introuvable.</p>', 404));
            }

            [$class, $method] = explode('@', $match['handler'], 2);

            $controller = $this->makeController($class, $services);
            $result = $controller->{$method}(...array_values($match['params']));

            return $this->secureResponse($this->normalizeControllerResult($result));
        } catch (\Throwable $e) {
            if ($this->isApiRequest()) {
                if ($e instanceof ApiException) {
                    return $this->secureResponse($e->toResponse());
                }
                if ($e->getMessage() === 'AUTH_REQUIRED') {
                    return $this->secureResponse(Response::error(ErrorCode::AUTH_REQUIRED, ErrorCode::message(ErrorCode::AUTH_REQUIRED), ErrorCode::httpStatus(ErrorCode::AUTH_REQUIRED)));
                }
                if ($e->getMessage() === 'AUTHZ_FORBIDDEN') {
                    return $this->secureResponse(Response::error(ErrorCode::AUTHZ_FORBIDDEN, ErrorCode::message(ErrorCode::AUTHZ_FORBIDDEN), ErrorCode::httpStatus(ErrorCode::AUTHZ_FORBIDDEN)));
                }
                if ($e instanceof \App\Application\Content\ContentValidationException) {
                    return $this->secureResponse(Response::validation($e->fields(), self::cleanValidationMessage($e->getMessage())));
                }
                if ($e instanceof \InvalidArgumentException) {
                    return $this->secureResponse(Response::validation(['payload' => [self::cleanValidationMessage($e->getMessage())]]));
                }

                $this->logger->error('runtime.exception', ['request_id' => Response::requestId(), 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
                return $this->secureResponse(Response::error(ErrorCode::INTERNAL_SERVER_ERROR, ErrorCode::message(ErrorCode::INTERNAL_SERVER_ERROR), ErrorCode::httpStatus(ErrorCode::INTERNAL_SERVER_ERROR)));
            }

            $this->logger->error('runtime.exception', ['request_id' => Response::requestId(), 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            throw $e;
        }
    }

    /** @return list<array{0:string,1:string,2:string}> */
    private function routes(ServiceFactory $services): array
    {
        if ($this->isAdminRequest()) {
            return array_merge(
                require base_path('backend/routes/api.php'),
                $this->shouldLoadModuleRoutes('api') ? $services->moduleRoutes()->routes('api') : [],
                require base_path('backend/routes/admin.php'),
                $this->shouldLoadModuleRoutes('admin') ? $services->moduleRoutes()->routes('admin') : [],
            );
        }

        if ($this->isPublicApiRequest()) {
            return array_merge(
                require base_path('backend/routes/api.php'),
                $this->shouldLoadModuleRoutes('api') ? $services->moduleRoutes()->routes('api') : [],
                $this->shouldLoadModuleRoutes('api') ? $services->moduleRoutes()->routes('headless') : [],
            );
        }

        return array_merge(
            $this->shouldLoadModuleRoutes('web') ? $services->moduleRoutes()->routes('web') : [],
            require base_path('backend/routes/web.php'),
        );
    }

    private function normalizeControllerResult(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result)) {
            return Response::json($result);
        }
        return Response::html((string) $result);
    }

    private function secureResponse(Response $response): Response
    {
        if ($this->isPublicApiRequest()) {
            $response = (new PublicApiCorsGuard($this->request, $this->coreDb, $this->config))->withCorsHeaders($response);
        }

        return (new SecurityHeaders((array) ($this->config['security'] ?? [])))->secure($response, $this->request);
    }

    private static function cleanValidationMessage(string $message): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n", '- '], ' ', $message)) ?? $message);
    }

    private function shouldStartSession(): bool
    {
        if ((bool) ($this->config['app']['start_session_for_public'] ?? false)) {
            return true;
        }
        return $this->isAdminRequest();
    }

    private function autoMaintenanceEnabled(): bool
    {
        return (bool) ($this->config['app']['auto_maintenance'] ?? false);
    }

    private function storageBootstrapRequired(): bool
    {
        return !$this->coreDb->tableExists('sites')
            || !$this->coreDb->tableExists('public_content_snapshots')
            || !$this->coreDb->tableExists('routes')
            || !$this->iamDb->tableExists('iam_users')
            || ($this->formsDb !== null && !$this->formsDb->tableExists('forms'))
            || ($this->cookiesDb !== null && !$this->cookiesDb->tableExists('cookie_banner_settings'));
    }

    private function shouldLoadModuleRoutes(string $scope): bool
    {
        return match ($scope) {
            'admin' => (bool) ($this->config['app']['admin_module_routes'] ?? true),
            'api' => $this->isAdminRequest()
                ? (bool) ($this->config['app']['admin_module_routes'] ?? true)
                : (bool) ($this->config['app']['public_api_module_routes'] ?? false),
            'web' => (bool) ($this->config['app']['public_module_routes'] ?? false),
            default => false,
        };
    }

    private function isApiRequest(): bool
    {
        return str_starts_with($this->request->path, '/api/') || str_starts_with($this->request->path, '/admin/api');
    }

    private function isPublicApiRequest(): bool
    {
        return str_starts_with($this->request->path, '/api/');
    }

    private function isPublicApiV1Request(): bool
    {
        return str_starts_with($this->request->path, '/api/v1/');
    }

    private function isAdminApiRequest(): bool
    {
        return str_starts_with($this->request->path, '/admin/api');
    }

    private function isAdminRequest(): bool
    {
        $path = '/' . trim($this->request->path, '/');
        $path = $path === '/' ? '/' : rtrim($path, '/');

        return $path === '/admin'
            || str_starts_with($path, '/admin/app')
            || $path === '/admin/login'
            || $path === '/admin/forgot-password'
            || $path === '/admin/reset-password'
            || $path === '/admin/logout'
            || str_starts_with($path, '/admin/api')
            || str_starts_with($path, '/admin/visual-preview')
            // Before SiteRepository resolves the current site, site-scoped admin
            // URLs still look like /site-a/admin/app. Sessions must already be
            // available at that point, otherwise the multisite back-office cannot
            // authenticate under a site base path.
            || (bool) preg_match('#/(admin)(?:$|/)#', $path);
    }

    private function makeController(string $class, ServiceFactory $services): object
    {
        return match ($class) {
            AdminAuthController::class => new AdminAuthController($this->config, $this->request, $services->auth(), $services->passwordReset(), $services->mailer()),
            AdminSpaController::class => new AdminSpaController($this->config, $services->auth()),
            PublicHeadlessController::class => new PublicHeadlessController(
                new PublicApiKernel($this->request, $services->coreDatabase(), $services->publicContent(), $services->publicRouteReads(), $services->sites(), $services->taxonomies(), $services->menus(), $services->resolvePublicRoute()),
                new PublicContentApiHandler($this->request, $services->publicContent(), $services->publicRouteReads(), $services->sites(), $services->coreDatabase(), $services->productContentLinks()),
                new PublicTaxonomyApiHandler($this->request, $services->taxonomies(), $services->sites()),
                new PublicSearchApiHandler($this->request, $services->publicSearch(), $services->sites()),
                new PublicFormApiHandler($this->request, $services->forms(), $services->sites()),
                new PublicCookieConsentApiHandler($this->request, $services->cookies(), $services->sites()),
                new PublicCatalogApiHandler($this->request, $services->sites(), $services->businessPublicCatalog(), $services->businessCatalogPricing(), $services->businessProductBundles(), $services->storefrontProjections()),
                new PosCatalogApiHandler($this->request, $services->sites(), $services->businessPosCatalog(), $services->businessCatalogPricing(), $services->businessProductBundles()),
                new PublicSaleApiHandler($this->request, $services->sites(), $services->saleDatabaseConnection(), $services->saleChannels(), $services->saleCarts(), $services->saleOrders(), $services->saleCartService(), $services->saleCheckout(), $services->saleGuestCheckout(), $services->saleCustomerAccounts(), $services->saleFulfillment(), $services->salesChannelResolver()),
                new PublicCustomerAccountApiHandler($this->request, $services->sites(), $services->saleCustomerAccounts()),
            ),
            UpdateManifestController::class => new UpdateManifestController(new VersionInventoryService($services->coreDatabase(), $this->config['updates'] ?? [], $this->config['modules'] ?? [], $this->config['databases'] ?? [])),
            ModuleHeadlessSchemaController::class => new ModuleHeadlessSchemaController($this->request, $services->sites(), $services->moduleBlueprintGovernance()),
            AdminContextApiController::class => new AdminContextApiController($this->config, $this->request, $services->coreDatabase(), $services->sites(), $services->auth(), $services->authorization(), $services->moduleLifecycle(), $services->moduleNavigation(), $services->moduleContracts()),
            AiAssistantApiController::class => new AiAssistantApiController(
                $this->request,
                $services->sites(),
                $services->auth(),
                $services->authorization(),
                $services->coreDatabase(),
                $services->aiModuleStatus(),
                $services->aiSettings(),
                $services->aiProviderManager(),
                $services->aiPrompts(),
                $services->aiSuggestions(),
                $services->aiTasks(),
                $services->aiUsageLogger(),
                $services->aiBudget(),
                $services->aiActionRegistry(),
            ),
            BusinessCrmApiController::class => new BusinessCrmApiController(
                $this->request,
                $services->sites(),
                $services->auth(),
                $services->authorization(),
                $services->businessActivity(),
                $services->businessCrm(),
                $services->businessCompanies(),
                $services->businessContacts(),
                $services->businessDashboard(),
                $services->businessRelationRead(),
                $services->businessSearch(),
                $services->businessTags(),
                $services->businessMemos(),
                $services->businessConsents(),
                $services->businessConsentService(),
                $services->businessMemoSharing(),
                $services->businessCsv(),
                $services->businessRelationSummary(),
                $services->iamAdmin(),
            ),
            BusinessCatalogApiController::class => new BusinessCatalogApiController(
                $this->request,
                $services->sites(),
                $services->auth(),
                $services->authorization(),
                $services->businessCatalogBrands(),
                $services->businessCatalogCategories(),
                $services->businessCatalogProducts(),
                $services->businessCatalogVariants(),
                $services->businessCatalogOptions(),
                $services->businessCatalogDiscounts(),
                $services->businessCatalogProductsService(),
                $services->businessCatalogVariantsService(),
                $services->businessCatalogDiscountsService(),
                $services->businessCatalogStockService(),
                $services->businessCatalogCsv(),
                $services->businessCatalogPdf(),
                $services->businessCatalogPricing(),
                $services->businessProductCompleteness(),
            ),
            BusinessPimApiController::class => new BusinessPimApiController(
                $this->request,
                $services->sites(),
                $services->auth(),
                $services->authorization(),
                $services->businessPimAdmin(),
                $services->businessProductAssets(),
                $services->businessProductBundles(),
                $services->businessCatalogSellables(),
                $services->productContentLinks(),
                $services->businessPriceLists(),
                $services->businessCommercialRelations(),
                $services->storefrontProjectionBuilder(),
            ),
            BusinessMessagingApiController::class => new BusinessMessagingApiController(
                $this->request,
                $services->sites(),
                $services->auth(),
                $services->authorization(),
                $services->businessMessages(),
                $services->businessMessagingOutbox(),
                $services->businessMessagingProviders(),
                $services->businessContacts(),
                $services->businessActivity(),
            ),
            BusinessMailingApiController::class => new BusinessMailingApiController(
                $this->request,
                $services->sites(),
                $services->auth(),
                $services->authorization(),
                $services->businessMailingRepository(),
                $services->businessMailing(),
                $services->businessContacts(),
            ),
            SaleAdminApiController::class => new SaleAdminApiController(
                $this->request,
                $services->sites(),
                $services->auth(),
                $services->authorization(),
                $services->saleDatabaseConnection(),
                $services->saleChannels(),
                $services->saleCarts(),
                $services->saleOrders(),
                $services->salePayments(),
                $services->saleInventory(),
                $services->saleCatalogSnapshots(),
                $services->saleCatalogExport(),
                $services->saleCartService(),
                $services->saleCheckout(),
                $services->salePaymentService(),
                $services->saleOrderService(),
                $services->saleEvents(),
                $services->saleImportExportReports(),
                $services->saleIdempotency(),
                $services->mailer(),
                $services->saleReceiptService(),
                $services->saleReturnService(),
                $services->saleOrderTimeline(),
                $services->saleCustomerAccounts(),
                $services->saleFulfillment(),
                $services->salesChannelResolver(),
                $services->salesChannelIntegrity(),
                $services->saleInventoryReconciliation(),
            ),
            ModuleAdminApiController::class => new ModuleAdminApiController($this->request, $services->sites(), $services->auth(), $services->authorization(), $services->moduleLifecycle(), $services->moduleBlueprintGovernance()),
            CapabilityApiController::class => new CapabilityApiController($this->request, $services->sites(), $services->auth(), $services->authorization(), $services->capabilities(), $services->capabilityExecutor()),
            BlockBlueprintApiController::class => new BlockBlueprintApiController($this->request, $services->sites(), $services->auth(), $services->authorization(), $services->blueprints()),
            BlueprintApiController::class => new BlueprintApiController($this->request, $services->auth(), $services->sites(), $services->authorization(), $services->blueprints(), $services->getBlueprintEditorSchema()),
            ConfigurationApiController::class => new ConfigurationApiController($this->request, $services->sites(), $services->auth(), $services->authorization(), new ConfigurationRepository($services->coreDatabase())),
            MultisiteApiController::class => new MultisiteApiController($this->request, $services->sites(), $services->auth(), $services->authorization(), new MultisiteRepository($services->coreDatabase())),
            ContentTypeApiController::class => new ContentTypeApiController($this->request, $services->auth(), $services->sites(), $services->authorization(), $services->listContentTypes(), $services->getEditorSchema()),
            ContentEntryApiController::class => new ContentEntryApiController(
                $this->request,
                $services->editorialContent(),
                $services->sites(),
                $services->auth(),
                $services->authorization(),
                $services->saveContentDraft(),
                $services->publishContentEntry(),
                $services->unpublishContentEntry(),
                $services->archiveDeleteContentEntry(),
                $services->projections(),
                $services->blockEditingLocks(),
            ),
            ContentRevisionApiController::class => new ContentRevisionApiController(
                $this->request,
                $services->editorialContent(),
                $services->sites(),
                $services->auth(),
                $services->authorization(),
                $services->contentRevisions(),
                $services->contentEntries(),
                $services->contentLocalizations(),
                $services->coreDatabase(),
                $services->generatePreviewUrl(),
            ),
            PreviewApiController::class => new PreviewApiController($this->request, $services->editorialContent(), $services->sites(), $services->auth(), $services->authorization(), $services->generatePreviewUrl()),
            VisualEditingApiController::class => new VisualEditingApiController($this->request, $services->editorialContent(), $services->publicContent(), $services->sites(), $services->auth(), $services->authorization(), $services->visualEditingMapBuilder(), $services->saveVisualField(), $services->generatePreviewUrl(), $services->blockEditingLocks()),
            ProfileApiController::class => new ProfileApiController($this->request, $services->auth()),
            TaxonomyApiController::class => new TaxonomyApiController($this->request, $services->sites(), $services->taxonomies(), $services->auth(), $services->authorization()),
            MenuApiController::class => new MenuApiController($this->request, $services->menus(), $services->sites(), $services->auth(), $services->authorization()),
            MediaApiController::class => new MediaApiController($this->request, $services->coreDatabase(), $services->sites(), $services->auth(), $services->authorization(), $services->logger()),
            DocsApiController::class => new DocsApiController($this->request, $services->auth()),
            MaintenanceApiController::class => new MaintenanceApiController($this->request, $services->coreDatabase(), $services->auth()->database(), $services->sites(), $services->auth(), $services->authorization(), $services->publishedProjectionPipeline(), new VersionInventoryService($services->coreDatabase(), $this->config['updates'] ?? [], $this->config['modules'] ?? [], $this->config['databases'] ?? []), new DependencyInventoryService($this->config['app'] ?? [], $this->config['updates'] ?? [])),
            FormApiController::class => new FormApiController($this->request, $services->forms(), $services->sites(), $services->auth(), $services->authorization()),
            CookieConsentApiController::class => new CookieConsentApiController($this->request, $services->cookies(), $services->sites(), $services->auth(), $services->authorization()),
            SeoAuditApiController::class => new SeoAuditApiController($this->request, $services->coreDatabase(), $services->sites(), $services->auth(), $services->authorization(), $services->seoAudit()),
            ImportsExportsApiController::class => new ImportsExportsApiController($this->request, $this->config, $services->coreDatabase(), $services->formsDatabase(), $services->sites(), $services->auth(), $services->authorization(), $services->resolvePublicRoute(), $services->outbox(), $services->publishContentEntry()),
            SecurityAdminApiController::class => new SecurityAdminApiController($this->request, $services->coreDatabase(), $services->auth()->database(), $services->auth(), $services->authorization()),
            IamAdminApiController::class => new IamAdminApiController($this->request, $services->auth(), $services->authorization(), $services->iamAdmin()),
            PublicApiDocsController::class => new PublicApiDocsController(),
            PublicSaleCheckoutController::class => new PublicSaleCheckoutController($this->request),
            PublicStorefrontCartController::class => new PublicStorefrontCartController(),
            PublicCustomerAccountController::class => new PublicCustomerAccountController(),
            BusinessMemoShareController::class => new BusinessMemoShareController($services->businessMemos(), $services->logger()),
            BusinessUnsubscribeController::class => new BusinessUnsubscribeController($services->businessMailingRepository(), $services->logger()),
            HomeController::class,
            RouteResolutionController::class => new $class(
                $this->config,
                $this->request,
                $services->editorialContent(),
                $services->publicRouteReads(),
                $services->sites(),
                $services->resolvePublicRoute(),
            ),
            default => class_exists($class) ? new $class() : throw new \RuntimeException('Controller not wired: ' . $class),
        };
    }
}
