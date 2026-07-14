<?php

declare(strict_types=1);

namespace App\Core;

use App\Application\Content\GeneratePreviewUrl;
use App\Application\Content\Read\EditorialContentReadRepository;
use App\Application\Content\Read\PublicContentReadRepository;
use App\Application\Routing\PublicRouteReadRepository;
use App\Application\Search\PublicSearchReadRepository;
use App\Application\Content\PublishContentEntry;
use App\Application\Content\RebuildEntryProjection;
use App\Application\Content\Projection\RedirectProjector;
use App\Application\Content\Projection\RouteProjector;
use App\Application\Content\Projection\SearchProjector;
use App\Application\Content\Projection\SeoProjector;
use App\Application\Content\Projection\TombstoneProjector;
use App\Application\Content\SaveContentDraft;
use App\Application\Content\BlockDocumentNormalizer;
use App\Application\Media\SyncMediaUsagesForRevision;
use App\Application\Content\UnpublishContentEntry;
use App\Application\Content\ArchiveDeleteContentEntry;
use App\Application\Content\ValidateEntryPayload;
use App\Application\Business\ProductContentLinkService;
use App\Application\Business\StorefrontProjectionRepository;
use App\Application\Business\StorefrontProjectionService;
use App\Application\Capability\ActionRunRepository;
use App\Application\Consistency\CrossDatabaseOperationJournal;
use App\Application\Capability\BlueprintActionContextService;
use App\Application\Capability\CapabilityExecutor;
use App\Application\Capability\CapabilityRegistry;
use App\Application\VisualEditing\BlockEditingLockRepository;
use App\Application\VisualEditing\SaveVisualField;
use App\Application\VisualEditing\VisualEditingMapBuilder;
use App\Application\Frontend\ResolvePublicRoute;
use App\Application\Forms\FormRepository;
use App\Application\Cookies\CookieConsentRepository;
use App\Application\Iam\UserReferenceValidator;
use App\Application\Iam\IamAdminRepository;
use App\Application\Iam\PasswordResetService;
use App\Application\Publication\ProjectionBuilder;
use App\Application\Publication\PublishedProjectionPipeline;
use App\Application\Publication\PublishedProjectionStore;
use App\Application\Seo\RunSeoAudit;
use App\Application\Support\ContentPathBuilder;
use App\Application\Taxonomy\AssignTaxonomyTerms;
use App\Application\Schema\CreateContentType;
use App\Application\Schema\GetEditorSchema;
use App\Application\Schema\ListContentTypes;
use App\Application\Schema\UpdateContentType;
use App\Application\Schema\ValidateContentSchema;
use App\Application\Blueprint\GetBlueprintEditorSchema;
use App\Application\Blueprint\BlueprintSchemaCanonicalizer;
use App\Application\Blueprint\CanonicalBlueprintService;
use App\Repository\AuthRepository;
use App\Repository\MenuRepository;
use App\Repository\SiteRepository;
use App\Repository\SystemJobRepository;
use App\Repository\TaxonomyRepository;
use App\Infrastructure\Persistence\Sql\SqlContentEntryRepository;
use App\Infrastructure\Persistence\Sql\SqlEditorialContentReadRepository;
use App\Infrastructure\Persistence\Sql\SqlPublicContentReadRepository;
use App\Infrastructure\Persistence\Sql\SqlPublicRouteReadRepository;
use App\Infrastructure\Persistence\Sql\SqlPublicSearchReadRepository;
use App\Infrastructure\Persistence\Sql\SqlContentFieldValueProjectionRepository;
use App\Infrastructure\Persistence\Sql\SqlEntryPayloadValidationRepository;
use App\Infrastructure\Persistence\Sql\SqlContentLocalizationRepository;
use App\Infrastructure\Persistence\Sql\SqlContentRevisionRepository;
use App\Infrastructure\Persistence\Sql\SqlContentTypeRepository;
use App\Infrastructure\Persistence\Sql\SqlBlueprintRepository;
use App\Infrastructure\Persistence\Sql\SqlFieldDefinitionRepository;
use App\Infrastructure\Persistence\Sql\SqlPublicRouteRepository;
use App\Infrastructure\Persistence\Sql\SqlOutboxEventRepository;
use App\Infrastructure\Persistence\Sql\SqlWebhookRepository;
use App\Infrastructure\Persistence\Sql\SqlProjectionRepository;
use App\Infrastructure\Persistence\Sql\SqlPublishedProjectionStore;
use App\Infrastructure\Persistence\Sql\SqlRedirectRepository;
use App\Infrastructure\Persistence\Sql\SqlSearchDocumentRepository;
use App\Infrastructure\Persistence\Sql\SqlSeoAuditIssueRepository;
use App\Infrastructure\Persistence\Sql\SqlSeoMetadataRepository;
use App\Infrastructure\Persistence\Sql\SqlTaxonomyAssignmentRepository;
use App\Infrastructure\Persistence\Sql\SqlTombstoneRepository;
use App\Infrastructure\Persistence\Sql\SqlTransactionManager;
use App\Infrastructure\Persistence\Sql\SqlCmsContentSource;
use App\Service\OutboxService;
use App\Service\Webhook\WebhookDispatcher;
use App\Service\ProjectionService;
use App\Module\HookDispatcher;
use App\Module\ModuleRegistry;
use App\Module\ModuleRouteLoader;
use App\Module\ModuleLifecycleService;
use App\Module\ModuleDatabaseManager;
use App\Module\ModulePermissionRegistrar;
use App\Module\ModuleBlueprintRegistrar;
use App\Module\ModuleNavigationRegistry;
use App\Module\ModuleContractRegistry;
use App\Module\ModuleBlueprintGovernanceService;
use App\Mail\MailerInterface;
use App\Mail\PhpMailMailer;
use App\Security\Authorization;
use App\Security\EditorialBlockSecurityPolicy;
use App\Modules\AiAssistant\Services\AiActionRegistry;
use App\Modules\AiAssistant\Services\AiDatabaseConnection;
use App\Modules\AiAssistant\Services\AiBudgetService;
use App\Modules\AiAssistant\Services\AiModuleStatusService;
use App\Modules\AiAssistant\Services\AiPromptService;
use App\Modules\AiAssistant\Services\AiProviderManager;
use App\Modules\AiAssistant\Services\AiSettingsService;
use App\Modules\AiAssistant\Services\AiSuggestionService;
use App\Modules\AiAssistant\Services\AiTaskService;
use App\Modules\AiAssistant\Services\AiUsageLogger;
use App\Modules\Business\Repositories\BusinessCompanyRepository;
use App\Modules\Business\Repositories\BusinessCatalogPricingRepository;
use App\Modules\Business\Repositories\BusinessActivityRepository;
use App\Modules\Business\Repositories\BusinessConsentRepository;
use App\Modules\Business\Repositories\BusinessContactRepository;
use App\Modules\Business\Repositories\BusinessDashboardRepository;
use App\Modules\Business\Repositories\BusinessMemoRepository;
use App\Modules\Business\Repositories\BusinessMessagingRepository;
use App\Modules\Business\Repositories\BusinessMailingRepository;
use App\Modules\Business\Repositories\BusinessRelationRepository;
use App\Modules\Business\Repositories\BusinessRelationReadRepository;
use App\Modules\Business\Repositories\BusinessSearchRepository;
use App\Modules\Business\Repositories\BusinessTagRepository;
use App\Modules\Business\Repositories\CatalogBrandRepository;
use App\Modules\Business\Repositories\CatalogCategoryRepository;
use App\Modules\Business\Repositories\CatalogDiscountRepository;
use App\Modules\Business\Repositories\CatalogOptionRepository;
use App\Modules\Business\Repositories\CatalogProductRepository;
use App\Modules\Business\Repositories\CatalogStockRepository;
use App\Modules\Business\Repositories\CatalogVariantRepository;
use App\Modules\Business\Repositories\PosCatalogRepository;
use App\Modules\Business\Repositories\PublicCatalogRepository;
use App\Modules\Business\Repositories\ProductContentSourceRepository;
use App\Modules\Business\Catalog\CatalogPricingService;
use App\Modules\Business\Services\BusinessConsentService;
use App\Modules\Business\Services\BusinessCatalogSellableReadService;
use App\Modules\Business\Services\BusinessCrmRelationSnapshotService;
use App\Modules\Business\Services\BusinessCsvService;
use App\Modules\Business\Services\BusinessCrmService;
use App\Modules\Business\Services\BusinessDatabaseConnection;
use App\Modules\Business\Services\BusinessMemoSharingService;
use App\Modules\Business\Services\BusinessRelationSummaryService;
use App\Modules\Business\Services\SaleCrmActivityProjectionService;
use App\Modules\Business\Services\BusinessMessagingOutboxService;
use App\Modules\Business\Services\BusinessMessagingProviderManager;
use App\Modules\Business\Services\BusinessMailingService;
use App\Modules\Business\Services\BusinessPimAdminService;
use App\Modules\Business\Services\BusinessProductAssetService;
use App\Modules\Business\Services\BusinessProductBundleService;
use App\Modules\Business\Services\BusinessProductCompletenessService;
use App\Modules\Business\Services\CatalogCsvService;
use App\Modules\Business\Services\CatalogPriceListService;
use App\Modules\Business\Services\CatalogCommercialRelationService;
use App\Modules\Business\Services\CatalogPdfService;
use App\Modules\Business\Services\CatalogDiscountService;
use App\Modules\Business\Services\CatalogProductService;
use App\Modules\Business\Services\CatalogStockService;
use App\Modules\Business\Services\CatalogVariantService;
use App\Modules\Sale\Adapters\BusinessCustomerSnapshotAdapter;
use App\Modules\Sale\Adapters\BusinessSellableCatalogAdapter;
use App\Modules\Sale\Adapters\NullCustomerSnapshotAdapter;
use App\Modules\Sale\Adapters\UnavailableSellableCatalogAdapter;
use App\Modules\Sale\Contracts\CmsAccountBridge;
use App\Modules\Sale\Contracts\CrmActivitySink;
use App\Modules\Sale\Contracts\CustomerIdentityBridge;
use App\Modules\Sale\Contracts\CustomerSnapshotPort;
use App\Modules\Sale\Contracts\SellableCatalogPort;
use App\Modules\Sale\Services\SaleDatabaseConnection;
use App\Modules\Sale\Services\SaleCatalogExportService;
use App\Modules\Sale\Services\SaleCatalogSnapshotService;
use App\Modules\Sale\Services\SaleCustomerSnapshotService;
use App\Modules\Sale\Services\SaleCustomerAccountService;
use App\Modules\Sale\Pricing\SalePricingService;
use App\Modules\Sale\Repositories\SaleCartRepository;
use App\Modules\Sale\Repositories\SaleChannelRepository;
use App\Modules\Sale\Repositories\SaleEventRepository;
use App\Modules\Sale\Repositories\SaleIdempotencyRepository;
use App\Modules\Sale\Repositories\SaleInventoryRepository;
use App\Modules\Sale\Repositories\SaleOrderRepository;
use App\Modules\Sale\Repositories\SalePaymentRepository;
use App\Modules\Sale\Repositories\SalePosRepository;
use App\Modules\Sale\Repositories\SaleReceiptRepository;
use App\Modules\Sale\Services\SaleCartService;
use App\Modules\Sale\Services\SaleCheckoutService;
use App\Modules\Sale\Services\SaleGuestCheckoutService;
use App\Modules\Sale\Services\SaleFulfillmentService;
use App\Modules\Sale\Services\SaleEventService;
use App\Modules\Sale\Services\SaleIdempotencyService;
use App\Modules\Sale\Services\SaleImportExportReportService;
use App\Modules\Sale\Services\SaleInventoryService;
use App\Modules\Sale\Services\SaleInventoryReconciliationService;
use App\Modules\Sale\Services\SaleStockMovementService;
use App\Modules\Sale\Services\SaleStockReservationService;
use App\Modules\Sale\Services\SaleOrderService;
use App\Modules\Sale\Services\SaleStateMachineService;
use App\Modules\Sale\Services\SalePaymentService;
use App\Modules\Sale\Services\SalePaymentMethodService;
use App\Modules\Sale\Services\SaleOnlinePaymentService;
use App\Modules\Sale\Services\SalePosService;
use App\Modules\Sale\Services\SaleReceiptService;
use App\Modules\Sale\Services\SaleReturnService;
use App\Modules\Sale\Services\SaleOrderTimelineService;
use App\Modules\Sale\Services\SalesChannelIntegrityService;
use App\Modules\Sale\Services\SalesChannelResolverService;
use App\Modules\Sale\Payments\PaymentProviderRegistry;

final class ServiceFactory
{
    /** @var array<string, object> */
    private array $instances = [];

    private readonly Database $coreDb;
    private readonly Database $iamDb;
    private readonly ?Database $formsDb;
    private readonly ?Database $cookiesDb;
    private readonly array $config;
    private readonly Logger $logger;

    /**
     * Supports both constructor signatures during patch rollout:
     * - new ServiceFactory($coreDb, $iamDb, $formsDb, $cookiesDb, $config, $logger)
     * - legacy: new ServiceFactory($coreDb, $iamDb, $formsDb, $config, $logger)
     */
    public function __construct(
        Database $coreDb,
        Database $iamDb,
        ?Database $formsDb,
        mixed $cookiesDbOrConfig,
        array|Logger|null $configOrLogger = null,
        ?Logger $logger = null,
    ) {
        $this->coreDb = $coreDb;
        $this->iamDb = $iamDb;
        $this->formsDb = $formsDb;

        if ($cookiesDbOrConfig instanceof Database || $cookiesDbOrConfig === null) {
            $this->cookiesDb = $cookiesDbOrConfig;
            $this->config = is_array($configOrLogger) ? $configOrLogger : [];
            if (!$logger instanceof Logger) {
                throw new \InvalidArgumentException('ServiceFactory requires a Logger instance.');
            }
            $this->logger = $logger;
            return;
        }

        if (is_array($cookiesDbOrConfig) && $configOrLogger instanceof Logger) {
            $this->cookiesDb = null;
            $this->config = $cookiesDbOrConfig;
            $this->logger = $configOrLogger;
            return;
        }

        throw new \InvalidArgumentException('Invalid ServiceFactory constructor arguments.');
    }

    public function coreDatabase(): Database
    {
        return $this->coreDb;
    }

    public function formsDatabase(): Database
    {
        return $this->formsDb ?? $this->coreDb;
    }

    public function iamDatabase(): Database
    {
        return $this->iamDb;
    }

    public function cookiesDatabase(): Database
    {
        return $this->cookiesDb ?? $this->coreDb;
    }

    public function cookies(): CookieConsentRepository
    {
        return $this->once('cookies', fn() => new CookieConsentRepository($this->cookiesDatabase()));
    }

    public function forms(): FormRepository
    {
        return $this->once('forms', fn() => new FormRepository($this->formsDatabase()));
    }

    public function logger(): Logger
    {
        return $this->logger;
    }

    public function sites(): SiteRepository
    {
        return $this->once('sites', fn() => new SiteRepository($this->coreDb, $this->config));
    }

    public function publicRouteReads(): PublicRouteReadRepository
    {
        return $this->once('public_routes_read', fn() => new SqlPublicRouteReadRepository($this->coreDb));
    }

    public function editorialContent(): EditorialContentReadRepository
    {
        return $this->once('editorial_content_read', fn() => new SqlEditorialContentReadRepository($this->coreDb, $this->config, $this->publicRouteReads()));
    }

    public function publicContent(): PublicContentReadRepository
    {
        return $this->once('public_content_read', fn() => new SqlPublicContentReadRepository($this->coreDb, $this->editorialContent(), $this->publicRouteReads()));
    }

    public function publicSearch(): PublicSearchReadRepository
    {
        return $this->once('public_search_read', fn() => new SqlPublicSearchReadRepository($this->coreDb));
    }

    public function taxonomies(): TaxonomyRepository
    {
        return $this->once('taxonomies', fn() => new TaxonomyRepository($this->coreDb, $this->publicContent()));
    }

    public function menus(): MenuRepository
    {
        return $this->once('menus', fn() => new MenuRepository($this->coreDb));
    }

    public function transactions(): SqlTransactionManager
    {
        return $this->once('transactions', fn() => new SqlTransactionManager($this->coreDb));
    }

    public function fieldDefinitions(): SqlFieldDefinitionRepository
    {
        return $this->once('schema_fields', fn() => new SqlFieldDefinitionRepository($this->coreDb));
    }

    public function contentTypes(): SqlContentTypeRepository
    {
        return $this->once('schema_content_types', fn() => new SqlContentTypeRepository($this->coreDb, $this->fieldDefinitions()));
    }

    public function contentEntries(): SqlContentEntryRepository
    {
        return $this->once('content_entries', fn() => new SqlContentEntryRepository($this->coreDb));
    }

    public function entryPayloadValidationRepository(): SqlEntryPayloadValidationRepository
    {
        return $this->once('entry_payload_validation_repository', fn() => new SqlEntryPayloadValidationRepository($this->coreDb));
    }

    public function validateEntryPayload(): ValidateEntryPayload
    {
        return $this->once('validate_entry_payload', fn() => new ValidateEntryPayload($this->contentTypes(), $this->entryPayloadValidationRepository(), $this->contentPathBuilder()));
    }
    public function contentRevisions(): SqlContentRevisionRepository
    {
        return $this->once('content_revisions', fn() => new SqlContentRevisionRepository($this->coreDb));
    }

    public function contentLocalizations(): SqlContentLocalizationRepository
    {
        return $this->once('content_localizations', fn() => new SqlContentLocalizationRepository($this->coreDb));
    }

    public function contentFieldValueProjections(): SqlContentFieldValueProjectionRepository
    {
        return $this->once('content_field_value_projections', fn() => new SqlContentFieldValueProjectionRepository($this->coreDb));
    }

    public function publicRoutes(): SqlPublicRouteRepository
    {
        return $this->once('public_routes', fn() => new SqlPublicRouteRepository($this->coreDb));
    }

    public function redirects(): SqlRedirectRepository
    {
        return $this->once('redirects', fn() => new SqlRedirectRepository($this->coreDb));
    }

    public function tombstones(): SqlTombstoneRepository
    {
        return $this->once('tombstones', fn() => new SqlTombstoneRepository($this->coreDb));
    }

    public function searchDocuments(): SqlSearchDocumentRepository
    {
        return $this->once('search_documents', fn() => new SqlSearchDocumentRepository($this->coreDb));
    }

    public function seoMetadata(): SqlSeoMetadataRepository
    {
        return $this->once('seo_metadata', fn() => new SqlSeoMetadataRepository($this->coreDb));
    }

    public function seoIssues(): SqlSeoAuditIssueRepository
    {
        return $this->once('seo_issues', fn() => new SqlSeoAuditIssueRepository($this->coreDb));
    }

    public function taxonomyAssignments(): SqlTaxonomyAssignmentRepository
    {
        return $this->once('taxonomy_assignments', fn() => new SqlTaxonomyAssignmentRepository($this->coreDb));
    }

    public function validateContentSchema(): ValidateContentSchema
    {
        return $this->once('validate_content_schema', fn() => new ValidateContentSchema());
    }

    public function createContentType(): CreateContentType
    {
        return $this->once('create_content_type', fn() => new CreateContentType($this->contentTypes(), $this->validateContentSchema()));
    }

    public function updateContentType(): UpdateContentType
    {
        return $this->once('update_content_type', fn() => new UpdateContentType($this->contentTypes(), $this->validateContentSchema()));
    }

    public function listContentTypes(): ListContentTypes
    {
        return $this->once('list_content_types', fn() => new ListContentTypes($this->contentTypes()));
    }

    public function blueprintCanonicalizer(): BlueprintSchemaCanonicalizer
    {
        return $this->once('blueprint_canonicalizer', fn() => new BlueprintSchemaCanonicalizer());
    }

    public function blueprints(): SqlBlueprintRepository
    {
        return $this->once('blueprints', fn() => new SqlBlueprintRepository($this->coreDb, $this->blueprintCanonicalizer()));
    }

    public function canonicalBlueprints(): CanonicalBlueprintService
    {
        return $this->once('canonical_blueprints', fn() => new CanonicalBlueprintService($this->blueprints(), $this->blueprintCanonicalizer()));
    }

    public function getBlueprintEditorSchema(): GetBlueprintEditorSchema
    {
        return $this->once('get_blueprint_editor_schema', fn() => new GetBlueprintEditorSchema($this->blueprints(), $this->coreDb));
    }

    public function getEditorSchema(): GetEditorSchema
    {
        return $this->once('get_editor_schema', fn() => new GetEditorSchema($this->contentTypes(), $this->coreDb, $this->blueprints()));
    }

    public function auth(): AuthRepository
    {
        return $this->once('auth', fn() => new AuthRepository($this->iamDb));
    }

    public function mailer(): MailerInterface
    {
        return $this->once('mailer', fn() => new PhpMailMailer($this->config));
    }

    public function passwordReset(): PasswordResetService
    {
        return $this->once('password_reset', fn() => new PasswordResetService(
            $this->auth(),
            $this->mailer(),
            $this->config,
            $GLOBALS['CMS_CURRENT_REQUEST'] ?? null,
        ));
    }

    public function authorization(): Authorization
    {
        return $this->once('authorization', fn() => new Authorization($this->auth()));
    }

    public function iamAdmin(): IamAdminRepository
    {
        return $this->once('iam_admin', fn() => new IamAdminRepository($this->iamDb, $this->coreDb));
    }

    public function userReferenceValidator(): UserReferenceValidator
    {
        return $this->once('user_reference_validator', fn() => new UserReferenceValidator($this->iamDb));
    }

    public function outboxEvents(): SqlOutboxEventRepository
    {
        return $this->once('outbox_events', fn() => new SqlOutboxEventRepository($this->coreDb));
    }

    public function outbox(): OutboxService
    {
        return $this->once('outbox', fn() => new OutboxService($this->outboxEvents()));
    }

    public function webhookRepository(): SqlWebhookRepository
    {
        return $this->once('webhook_repository', fn() => new SqlWebhookRepository($this->coreDb));
    }

    public function webhookDispatcher(): WebhookDispatcher
    {
        return $this->once('webhook_dispatcher', fn() => new WebhookDispatcher($this->outbox(), $this->webhookRepository(), $this->logger));
    }

    public function systemJobs(): SystemJobRepository
    {
        return $this->once('system_jobs', fn() => new SystemJobRepository($this->coreDb));
    }

    public function contentPathBuilder(): ContentPathBuilder
    {
        return $this->once('content_path_builder', fn() => new ContentPathBuilder());
    }

    public function modules(): ModuleRegistry
    {
        return $this->once('module_registry', fn() => new ModuleRegistry($this->coreDb, $this->config, $this->logger));
    }

    public function crossDatabaseOperations(): CrossDatabaseOperationJournal
    {
        return $this->once('cross_database_operations', fn() => new CrossDatabaseOperationJournal($this->coreDb));
    }

    public function moduleDatabases(): ModuleDatabaseManager
    {
        return $this->once('module_database_manager', fn() => new ModuleDatabaseManager($this->coreDb, $this->config, $this->logger, $this->crossDatabaseOperations()));
    }

    public function modulePermissions(): ModulePermissionRegistrar
    {
        return $this->once('module_permission_registrar', fn() => new ModulePermissionRegistrar($this->coreDb, $this->iamDb, $this->logger));
    }

    public function moduleBlueprints(): ModuleBlueprintRegistrar
    {
        return $this->once('module_blueprint_registrar', fn() => new ModuleBlueprintRegistrar($this->coreDb, $this->logger));
    }

    public function moduleNavigation(): ModuleNavigationRegistry
    {
        return $this->once('module_navigation_registry', fn() => new ModuleNavigationRegistry($this->modules()));
    }

    public function moduleContracts(): ModuleContractRegistry
    {
        return $this->once('module_contract_registry', fn() => new ModuleContractRegistry($this->modules()));
    }

    public function moduleBlueprintGovernance(): ModuleBlueprintGovernanceService
    {
        return $this->once('module_blueprint_governance', fn() => new ModuleBlueprintGovernanceService(
            $this->coreDb,
            $this->iamDb,
            $this->modules(),
            $this->moduleDatabases(),
            $this->logger,
        ));
    }

    public function moduleLifecycle(): ModuleLifecycleService
    {
        return $this->once('module_lifecycle', fn() => new ModuleLifecycleService(
            $this->coreDb,
            $this->modules(),
            $this->moduleDatabases(),
            $this->modulePermissions(),
            $this->moduleBlueprints(),
            $this->moduleBlueprintGovernance(),
            $this->logger,
        ));
    }

    public function hooks(): HookDispatcher
    {
        return $this->once('hook_dispatcher', fn() => new HookDispatcher($this->modules(), $this->logger));
    }

    public function actionRuns(): ActionRunRepository
    {
        return $this->once('action_runs', fn() => new ActionRunRepository($this->coreDb));
    }

    public function blueprintActionContext(): BlueprintActionContextService
    {
        return $this->once('blueprint_action_context', fn() => new BlueprintActionContextService($this->coreDb));
    }

    public function capabilities(): CapabilityRegistry
    {
        return $this->once('capability_registry', fn() => new CapabilityRegistry($this->modules(), $this->blueprintActionContext()));
    }

    public function capabilityExecutor(): CapabilityExecutor
    {
        return $this->once('capability_executor', fn() => new CapabilityExecutor($this->capabilities(), $this->actionRuns(), $this->auth()));
    }

    public function moduleRoutes(): ModuleRouteLoader
    {
        return $this->once('module_route_loader', fn() => new ModuleRouteLoader($this->modules(), $this->logger));
    }


    public function aiDatabaseConnection(): AiDatabaseConnection
    {
        $path = (string) ($this->config['databases']['ai']['path'] ?? base_path('storage/database/ai.sqlite'));
        return $this->once('ai_database_connection', fn() => new AiDatabaseConnection($path));
    }

    public function businessDatabaseConnection(): BusinessDatabaseConnection
    {
        $path = (string) ($this->config['databases']['business']['path'] ?? base_path('storage/database/business.sqlite'));
        return $this->once('business_database_connection', fn() => new BusinessDatabaseConnection($path));
    }

    public function saleDatabaseConnection(): SaleDatabaseConnection
    {
        $path = (string) ($this->config['databases']['sale']['path'] ?? base_path('storage/database/sale.sqlite'));
        return $this->once('sale_database_connection', fn() => new SaleDatabaseConnection($path));
    }

    public function saleCatalogSnapshots(): SaleCatalogSnapshotService
    {
        return $this->once('sale_catalog_snapshots', fn() => new SaleCatalogSnapshotService($this->saleDatabaseConnection(), $this->saleSellableCatalogPort()));
    }

    public function saleCatalogExport(): SaleCatalogExportService
    {
        return $this->once('sale_catalog_export', fn() => new SaleCatalogExportService(
            $this->saleChannels(),
            $this->saleCatalogSnapshots(),
            $this->salePricing()
        ));
    }

    public function saleCustomerSnapshots(): SaleCustomerSnapshotService
    {
        return $this->once('sale_customer_snapshots', fn() => new SaleCustomerSnapshotService($this->saleDatabaseConnection(), $this->saleCustomerSnapshotPort()));
    }

    public function saleSellableCatalogPort(): SellableCatalogPort
    {
        return $this->once('sale_sellable_catalog_port', function (): SellableCatalogPort {
            if ($this->modules()->get('business') === null || !$this->modules()->isEnabled('business')) {
                return new UnavailableSellableCatalogAdapter();
            }
            return new BusinessSellableCatalogAdapter($this->businessCatalogSellables());
        });
    }

    public function saleCustomerSnapshotPort(): CustomerSnapshotPort
    {
        return $this->once('sale_customer_snapshot_port', function (): CustomerSnapshotPort {
            if ($this->modules()->get('business') === null || !$this->modules()->isEnabled('business')) {
                return new NullCustomerSnapshotAdapter();
            }
            return new BusinessCustomerSnapshotAdapter($this->businessCrmRelationSnapshots());
        });
    }

    public function saleCrmActivitySink(): CrmActivitySink
    {
        return $this->once('sale_crm_activity_sink', fn() => $this->saleCrmActivities());
    }

    /** @deprecated Use saleCustomerIdentityBridge(). */
    public function saleCmsAccountBridge(): CmsAccountBridge
    {
        return $this->saleCustomerAccounts();
    }

    public function saleCustomerIdentityBridge(): CustomerIdentityBridge
    {
        return $this->saleCustomerAccounts();
    }

    public function saleCustomerAccounts(): SaleCustomerAccountService
    {
        return $this->once('sale_customer_accounts', fn() => new SaleCustomerAccountService(
            $this->iamDatabase(),
            $this->saleDatabaseConnection(),
            $this->businessCompanies(),
            $this->businessContacts(),
            $this->businessConsents(),
            $this->saleReturnService(),
            $this->businessDatabaseConnection()->database(),
            $this->saleEvents(),
        ));
    }

    public function saleChannels(): SaleChannelRepository
    {
        return $this->once('sale_channels', fn() => new SaleChannelRepository($this->saleDatabaseConnection()));
    }

    public function salesChannelResolver(): SalesChannelResolverService
    {
        return $this->once('sales_channel_resolver', fn() => new SalesChannelResolverService(
            $this->saleChannels(), $this->saleDatabaseConnection(), $this->coreDatabase()
        ));
    }

    public function salesChannelIntegrity(): SalesChannelIntegrityService
    {
        return $this->once('sales_channel_integrity', fn() => new SalesChannelIntegrityService(
            $this->saleDatabaseConnection(), $this->coreDatabase(), $this->businessDatabaseConnection()
        ));
    }

    public function saleCarts(): SaleCartRepository
    {
        return $this->once('sale_carts', fn() => new SaleCartRepository($this->saleDatabaseConnection(), $this->salePricing()));
    }

    public function saleOrders(): SaleOrderRepository
    {
        return $this->once('sale_orders', fn() => new SaleOrderRepository($this->saleDatabaseConnection()));
    }

    public function salePayments(): SalePaymentRepository
    {
        return $this->once('sale_payments', fn() => new SalePaymentRepository($this->saleDatabaseConnection()));
    }

    public function saleReceiptsRepository(): SaleReceiptRepository
    {
        return $this->once('sale_receipts_repository', fn() => new SaleReceiptRepository($this->saleDatabaseConnection()));
    }

    public function salePosRepository(): SalePosRepository
    {
        return $this->once('sale_pos_repository', fn() => new SalePosRepository($this->saleDatabaseConnection()));
    }

    public function saleInventoryRepository(): SaleInventoryRepository
    {
        return $this->once('sale_inventory_repository', fn() => new SaleInventoryRepository($this->saleDatabaseConnection()));
    }

    public function saleEventsRepository(): SaleEventRepository
    {
        return $this->once('sale_events_repository', fn() => new SaleEventRepository($this->saleDatabaseConnection()));
    }

    public function saleIdempotencyRepository(): SaleIdempotencyRepository
    {
        return $this->once('sale_idempotency_repository', fn() => new SaleIdempotencyRepository($this->saleDatabaseConnection()));
    }

    public function salePricing(): SalePricingService
    {
        return $this->once('sale_pricing', fn() => new SalePricingService());
    }

    public function saleEvents(): SaleEventService
    {
        return $this->once('sale_events', fn() => new SaleEventService($this->saleEventsRepository()));
    }

    public function saleIdempotency(): SaleIdempotencyService
    {
        return $this->once('sale_idempotency', fn() => new SaleIdempotencyService($this->saleIdempotencyRepository()));
    }

    public function saleInventory(): SaleInventoryService
    {
        return $this->once('sale_inventory', fn() => new SaleInventoryService(
            $this->saleInventoryRepository(),
            new SaleStockReservationService($this->saleInventoryRepository()),
            new SaleStockMovementService($this->saleInventoryRepository()),
            $this->saleEvents(),
            $this->businessDatabaseConnection()
        ));
    }

    public function saleInventoryReconciliation(): SaleInventoryReconciliationService
    {
        return $this->once('sale_inventory_reconciliation', fn() => new SaleInventoryReconciliationService(
            $this->saleDatabaseConnection(),
            $this->businessDatabaseConnection(),
            $this->saleInventory(),
        ));
    }

    public function saleImportExportReports(): SaleImportExportReportService
    {
        return $this->once('sale_import_export_reports', fn() => new SaleImportExportReportService(
            $this->saleDatabaseConnection(),
            $this->saleInventory()
        ));
    }

    public function saleCartService(): SaleCartService
    {
        return $this->once('sale_cart_service', fn() => new SaleCartService(
            $this->saleCarts(),
            $this->saleChannels(),
            $this->saleCatalogSnapshots(),
            $this->salePricing(),
            $this->saleInventory(),
            $this->saleEvents(),
            $this->saleIdempotency()
        ));
    }

    public function saleCheckout(): SaleCheckoutService
    {
        return $this->once('sale_checkout', fn() => new SaleCheckoutService(
            $this->saleDatabaseConnection(),
            $this->saleCarts(),
            $this->saleOrders(),
            $this->saleInventory(),
            $this->saleEvents(),
            $this->saleIdempotency(),
            $this->saleStateMachines()
        ));
    }

    public function saleGuestCheckout(): SaleGuestCheckoutService
    {
        return $this->once('sale_guest_checkout', fn() => new SaleGuestCheckoutService(
            $this->saleDatabaseConnection(),
            $this->saleCarts(),
            $this->saleChannels(),
            $this->saleCatalogSnapshots(),
            $this->salePricing(),
            $this->saleInventory(),
            $this->saleStateMachines(),
            $this->saleFulfillment(),
            $this->salePaymentMethods(),
            $this->saleEvents()
        ));
    }

    public function saleFulfillment(): SaleFulfillmentService
    {
        return $this->once('sale_fulfillment', fn() => new SaleFulfillmentService(
            $this->saleDatabaseConnection(),
            $this->saleInventory(),
            $this->saleStateMachines(),
        ));
    }

    public function salePaymentMethods(): SalePaymentMethodService
    {
        return $this->once('sale_payment_methods', fn() => new SalePaymentMethodService(
            $this->saleDatabaseConnection(),
            new PaymentProviderRegistry(null, $this->saleDatabaseConnection()->database(), null, (string) ($this->config['app']['env'] ?? 'production'), (array)($this->config['app']['payments'] ?? []))
        ));
    }

    public function salePaymentService(): SalePaymentService
    {
        return $this->once('sale_payment_service', fn() => new SalePaymentService(
            $this->salePayments(),
            $this->saleOrders(),
            $this->saleEvents(),
            $this->saleIdempotency(),
            new PaymentProviderRegistry(null, $this->saleDatabaseConnection()->database(), null, (string) ($this->config['app']['env'] ?? 'production'), (array)($this->config['app']['payments'] ?? [])),
            $this->saleStateMachines(),
            $this->saleInventory()
        ));
    }

    public function saleOnlinePayments(): SaleOnlinePaymentService
    {
        return $this->once('sale_online_payments', fn() => new SaleOnlinePaymentService(
            $this->saleDatabaseConnection(),
            $this->salePayments(),
            $this->saleOrders(),
            $this->saleInventory(),
            $this->saleStateMachines(),
            new PaymentProviderRegistry(null, $this->saleDatabaseConnection()->database(), null, (string) ($this->config['app']['env'] ?? 'production'), (array)($this->config['app']['payments'] ?? [])),
            $this->logger()
        ));
    }

    public function saleOrderService(): SaleOrderService
    {
        return $this->once('sale_order_service', fn() => new SaleOrderService($this->saleOrders(), $this->saleEvents(), $this->saleInventory(), $this->saleStateMachines()));
    }

    public function saleStateMachines(): SaleStateMachineService
    {
        return $this->once('sale_state_machines', fn() => new SaleStateMachineService(
            $this->saleDatabaseConnection()->database() ?? throw new \RuntimeException('sale.database_unavailable'),
            $this->saleEvents()
        ));
    }

    public function saleReceiptService(): SaleReceiptService
    {
        return $this->once('sale_receipt_service', fn() => new SaleReceiptService($this->saleOrders(), $this->salePayments(), $this->saleReceiptsRepository()));
    }

    public function saleReturnService(): SaleReturnService
    {
        return $this->once('sale_return_service', fn() => new SaleReturnService($this->saleDatabaseConnection(), $this->saleStateMachines(), $this->saleInventory(), $this->saleEvents()));
    }

    public function saleOrderTimeline(): SaleOrderTimelineService
    {
        return $this->once('sale_order_timeline', fn() => new SaleOrderTimelineService($this->saleDatabaseConnection()));
    }

    public function salePosService(): SalePosService
    {
        return $this->once('sale_pos_service', fn() => new SalePosService($this->saleCartService(), $this->saleCheckout(), $this->salePosRepository()));
    }

    public function businessCompanies(): BusinessCompanyRepository
    {
        return $this->once('business_companies', fn() => new BusinessCompanyRepository($this->businessDatabaseConnection()->database()));
    }

    public function businessActivity(): BusinessActivityRepository
    {
        return $this->once('business_activity', fn() => new BusinessActivityRepository($this->businessDatabaseConnection()->database()));
    }

    public function businessContacts(): BusinessContactRepository
    {
        return $this->once('business_contacts', fn() => new BusinessContactRepository($this->businessDatabaseConnection()->database()));
    }

    public function businessDashboard(): BusinessDashboardRepository
    {
        return $this->once('business_dashboard', fn() => new BusinessDashboardRepository($this->businessDatabaseConnection()->database()));
    }

    public function businessRelations(): BusinessRelationRepository
    {
        return $this->once('business_relations', fn() => new BusinessRelationRepository($this->businessDatabaseConnection()->database()));
    }

    public function businessRelationRead(): BusinessRelationReadRepository
    {
        return $this->once('business_relation_read', fn() => new BusinessRelationReadRepository($this->businessRelations(), $this->businessMemos()));
    }

    public function businessSearch(): BusinessSearchRepository
    {
        return $this->once('business_search', fn() => new BusinessSearchRepository($this->businessDatabaseConnection()->database()));
    }

    public function businessCrmRelationSnapshots(): BusinessCrmRelationSnapshotService
    {
        return $this->once('business_crm_relation_snapshots', fn() => new BusinessCrmRelationSnapshotService($this->businessCompanies(), $this->businessContacts()));
    }

    public function businessTags(): BusinessTagRepository
    {
        return $this->once('business_tags', fn() => new BusinessTagRepository($this->businessDatabaseConnection()->database()));
    }

    public function businessMemos(): BusinessMemoRepository
    {
        return $this->once('business_memos', fn() => new BusinessMemoRepository($this->businessDatabaseConnection()->database()));
    }

    public function businessConsents(): BusinessConsentRepository
    {
        return $this->once('business_consents', fn() => new BusinessConsentRepository($this->businessDatabaseConnection()->database()));
    }

    public function businessMessages(): BusinessMessagingRepository
    {
        return $this->once('business_messages', fn() => new BusinessMessagingRepository($this->businessDatabaseConnection()->database()));
    }

    public function businessMailingRepository(): BusinessMailingRepository
    {
        return $this->once('business_mailing_repository', fn() => new BusinessMailingRepository($this->businessDatabaseConnection()->database()));
    }

    public function businessCrm(): BusinessCrmService
    {
        return $this->once('business_crm', fn() => new BusinessCrmService($this->businessCompanies(), $this->businessContacts(), $this->businessTags()));
    }

    public function businessCsv(): BusinessCsvService
    {
        return $this->once('business_csv', fn() => new BusinessCsvService($this->businessCompanies(), $this->businessContacts(), $this->businessTags(), $this->businessConsents()));
    }

    public function businessMemoSharing(): BusinessMemoSharingService
    {
        return $this->once('business_memo_sharing', fn() => new BusinessMemoSharingService($this->businessMemos()));
    }

    public function businessRelationSummary(): BusinessRelationSummaryService
    {
        return $this->once('business_relation_summary', fn() => new BusinessRelationSummaryService($this->businessRelationRead(), $this->businessActivity()));
    }

    public function saleCrmActivities(): SaleCrmActivityProjectionService
    {
        return $this->once('sale_crm_activities', fn() => new SaleCrmActivityProjectionService(
            $this->businessDatabaseConnection()->database() ?? throw new \RuntimeException('business.database_unavailable'),
            $this->saleDatabaseConnection()
        ));
    }

    public function businessConsentService(): BusinessConsentService
    {
        return $this->once('business_consent_service', fn() => new BusinessConsentService($this->businessConsents()));
    }

    public function businessMessagingOutbox(): BusinessMessagingOutboxService
    {
        return $this->once('business_messaging_outbox', fn() => new BusinessMessagingOutboxService($this->businessConsents(), $this->businessMessages()));
    }

    public function businessMessagingProviders(): BusinessMessagingProviderManager
    {
        return $this->once('business_messaging_providers', fn() => new BusinessMessagingProviderManager($this->businessMessages(), $this->mailer(), $this->logger));
    }

    public function businessMailing(): BusinessMailingService
    {
        return $this->once('business_mailing', fn() => new BusinessMailingService($this->businessMailingRepository(), $this->businessMessages()));
    }

    public function businessCatalogBrands(): CatalogBrandRepository
    {
        return $this->once('business_catalog_brands', fn() => new CatalogBrandRepository($this->businessDatabaseConnection()->database()));
    }

    public function businessCatalogCategories(): CatalogCategoryRepository
    {
        return $this->once('business_catalog_categories', fn() => new CatalogCategoryRepository($this->businessDatabaseConnection()->database()));
    }

    public function businessCatalogProducts(): CatalogProductRepository
    {
        return $this->once('business_catalog_products', fn() => new CatalogProductRepository($this->businessDatabaseConnection()->database()));
    }

    public function businessCatalogVariants(): CatalogVariantRepository
    {
        return $this->once('business_catalog_variants', fn() => new CatalogVariantRepository($this->businessDatabaseConnection()->database()));
    }

    public function businessCatalogOptions(): CatalogOptionRepository
    {
        return $this->once('business_catalog_options', fn() => new CatalogOptionRepository($this->businessDatabaseConnection()->database()));
    }

    public function businessCatalogDiscounts(): CatalogDiscountRepository
    {
        return $this->once('business_catalog_discounts', fn() => new CatalogDiscountRepository($this->businessDatabaseConnection()->database()));
    }

    public function businessCatalogStockRepository(): CatalogStockRepository
    {
        return $this->once('business_catalog_stock_repository', fn() => new CatalogStockRepository($this->businessDatabaseConnection()->database()));
    }

    public function businessCatalogPricingRepository(): BusinessCatalogPricingRepository
    {
        return $this->once('business_catalog_pricing_repository', fn() => new BusinessCatalogPricingRepository($this->businessDatabaseConnection()->database()));
    }

    public function businessCatalogSellables(): BusinessCatalogSellableReadService
    {
        return $this->once('business_catalog_sellables', fn() => new BusinessCatalogSellableReadService($this->businessCatalogPricingRepository(), $this->businessCatalogPricing(), $this->businessPosCatalog(), null, $this->businessProductBundles()));
    }

    public function businessProductAssets(): BusinessProductAssetService
    {
        return $this->once('business_product_assets', fn() => new BusinessProductAssetService($this->businessDatabaseConnection()->database()));
    }

    public function businessProductBundles(): BusinessProductBundleService
    {
        return $this->once('business_product_bundles', fn() => new BusinessProductBundleService($this->businessDatabaseConnection()->database()));
    }

    public function businessPriceLists(): CatalogPriceListService
    {
        return $this->once('business_price_lists', fn() => new CatalogPriceListService($this->businessDatabaseConnection()->database()));
    }

    public function businessCommercialRelations(): CatalogCommercialRelationService
    {
        return $this->once('business_commercial_relations', fn() => new CatalogCommercialRelationService($this->businessDatabaseConnection()->database()));
    }

    public function businessProductCompleteness(): BusinessProductCompletenessService
    {
        return $this->once('business_product_completeness', fn() => new BusinessProductCompletenessService($this->businessDatabaseConnection()->database(), $this->coreDb));
    }

    public function businessPimAdmin(): BusinessPimAdminService
    {
        return $this->once('business_pim_admin', fn() => new BusinessPimAdminService($this->businessDatabaseConnection()->database(), $this->businessProductCompleteness()));
    }

    public function productContentLinks(): ProductContentLinkService
    {
        return $this->once('product_content_links', fn() => new ProductContentLinkService(
            $this->coreDb,
            new ProductContentSourceRepository($this->businessDatabaseConnection()->database()),
            new SqlCmsContentSource($this->coreDb),
        ));
    }

    public function storefrontProjections(): StorefrontProjectionRepository
    {
        return $this->once('storefront_projections', fn() => new StorefrontProjectionRepository($this->coreDb));
    }

    public function storefrontProjectionBuilder(): StorefrontProjectionService
    {
        return $this->once('storefront_projection_builder', fn() => new StorefrontProjectionService(
            $this->coreDb,
            $this->businessDatabaseConnection()->database(),
            new ProductContentSourceRepository($this->businessDatabaseConnection()->database()),
            $this->businessPublicCatalog(),
            $this->businessCatalogSellables(),
        ));
    }

    public function businessPublicCatalog(): PublicCatalogRepository
    {
        return $this->once('business_public_catalog', fn() => new PublicCatalogRepository($this->businessDatabaseConnection()->database()));
    }

    public function businessPosCatalog(): PosCatalogRepository
    {
        return $this->once('business_pos_catalog', fn() => new PosCatalogRepository($this->businessDatabaseConnection()->database()));
    }

    public function businessCatalogProductsService(): CatalogProductService
    {
        return $this->once('business_catalog_products_service', fn() => new CatalogProductService(
            $this->businessCatalogProducts(),
            new \App\Modules\Business\Catalog\BusinessCatalogValidator(),
            $this->productContentLinks(),
        ));
    }

    public function businessCatalogVariantsService(): CatalogVariantService
    {
        return $this->once('business_catalog_variants_service', fn() => new CatalogVariantService($this->businessCatalogVariants()));
    }

    public function businessCatalogDiscountsService(): CatalogDiscountService
    {
        return $this->once('business_catalog_discounts_service', fn() => new CatalogDiscountService($this->businessCatalogDiscounts()));
    }

    public function businessCatalogStockService(): CatalogStockService
    {
        return $this->once('business_catalog_stock_service', fn() => new CatalogStockService($this->businessCatalogStockRepository()));
    }

    public function businessCatalogCsv(): CatalogCsvService
    {
        return $this->once('business_catalog_csv', fn() => new CatalogCsvService(
            $this->businessDatabaseConnection()->database(),
            $this->businessCatalogBrands(),
            $this->businessCatalogCategories(),
            $this->businessCatalogProducts(),
            $this->businessCatalogVariants(),
            $this->businessCatalogOptions(),
            $this->businessCatalogPricing()
        ));
    }

    public function businessCatalogPdf(): CatalogPdfService
    {
        return $this->once('business_catalog_pdf', fn() => new CatalogPdfService(
            $this->businessCatalogProducts(),
            $this->businessCatalogVariants()
        ));
    }

    public function businessCatalogPricing(): CatalogPricingService
    {
        return $this->once('business_catalog_pricing', fn() => new CatalogPricingService($this->businessCatalogPricingRepository()));
    }

    public function aiSettings(): AiSettingsService
    {
        return $this->once('ai_settings', fn() => new AiSettingsService($this->aiDatabaseConnection()->database()));
    }

    public function aiActionRegistry(): AiActionRegistry
    {
        return $this->once('ai_action_registry', fn() => new AiActionRegistry($this->aiSettings(), $this->aiBudget()));
    }

    public function aiProviderManager(): AiProviderManager
    {
        return $this->once('ai_provider_manager', fn() => new AiProviderManager($this->aiSettings(), $this->aiPrompts(), $this->aiBudget()));
    }

    public function aiPrompts(): AiPromptService
    {
        return $this->once('ai_prompts', fn() => new AiPromptService($this->aiDatabaseConnection()->database()));
    }

    public function aiSuggestions(): AiSuggestionService
    {
        return $this->once('ai_suggestions', fn() => new AiSuggestionService($this->aiDatabaseConnection()->database()));
    }

    public function aiUsageLogger(): AiUsageLogger
    {
        return $this->once('ai_usage_logger', fn() => new AiUsageLogger($this->aiDatabaseConnection()->database()));
    }

    public function aiTasks(): AiTaskService
    {
        return $this->once('ai_tasks', fn() => new AiTaskService($this->aiDatabaseConnection()->database()));
    }

    public function aiBudget(): AiBudgetService
    {
        return $this->once('ai_budget', fn() => new AiBudgetService($this->aiDatabaseConnection()->database()));
    }

    public function aiModuleStatus(): AiModuleStatusService
    {
        return $this->once('ai_module_status', fn() => new AiModuleStatusService(
            $this->modules(),
            $this->aiDatabaseConnection(),
            $this->aiSettings(),
            $this->aiProviderManager(),
        ));
    }

    public function seoAudit(): RunSeoAudit
    {
        return $this->once('seo_audit', fn() => new RunSeoAudit($this->seoIssues(), $this->config));
    }

    public function routeProjector(): RouteProjector
    {
        return $this->once('route_projector', fn() => new RouteProjector(
            $this->publicRoutes(),
            $this->contentPathBuilder(),
        ));
    }

    public function seoProjector(): SeoProjector
    {
        return $this->once('seo_projector', fn() => new SeoProjector(
            $this->config,
            $this->seoMetadata(),
            $this->seoAudit(),
        ));
    }

    public function searchProjector(): SearchProjector
    {
        return $this->once('search_projector', fn() => new SearchProjector($this->searchDocuments()));
    }

    public function redirectProjector(): RedirectProjector
    {
        return $this->once('redirect_projector', fn() => new RedirectProjector($this->redirects()));
    }

    public function tombstoneProjector(): TombstoneProjector
    {
        return $this->once('tombstone_projector', fn() => new TombstoneProjector($this->tombstones()));
    }

    public function rebuildEntryProjection(): RebuildEntryProjection
    {
        return $this->once('rebuild_entry_projection', fn() => new RebuildEntryProjection(
            $this->publishedProjectionPipeline(),
        ));
    }

    public function projectionBuilder(): ProjectionBuilder
    {
        return $this->once('projection_builder', fn() => new ProjectionBuilder(
            $this->coreDb,
            $this->contentPathBuilder(),
            $this->config,
            $this->hooks(),
        ));
    }

    public function publishedProjectionStore(): PublishedProjectionStore
    {
        return $this->once('published_projection_store', fn() => new SqlPublishedProjectionStore($this->coreDb));
    }

    public function publishedProjectionPipeline(): PublishedProjectionPipeline
    {
        return $this->once('published_projection_pipeline', fn() => new PublishedProjectionPipeline(
            $this->contentEntries(),
            $this->contentRevisions(),
            $this->projectionBuilder(),
            $this->publishedProjectionStore(),
            $this->outbox(),
            $this->logger,
        ));
    }

    public function projectionRepository(): SqlProjectionRepository    {
        return $this->once('projection_repository', fn() => new SqlProjectionRepository($this->coreDb));
    }

    public function projections(): ProjectionService
    {
        return $this->once('projections', fn() => new ProjectionService(
            $this->projectionRepository(),
            $this->rebuildEntryProjection(),
            $this->outbox(),
            $this->logger,
            $this->transactions(),
        ));
    }

    public function saveContentDraft(): SaveContentDraft
    {
        return $this->once('save_content_draft', fn() => new SaveContentDraft(
            $this->transactions(),
            $this->contentTypes(),
            $this->contentEntries(),
            $this->contentRevisions(),
            $this->contentLocalizations(),
            $this->contentFieldValueProjections(),
            $this->contentPathBuilder(),
            $this->validateEntryPayload(),
            $this->userReferenceValidator(),
            new SyncMediaUsagesForRevision($this->coreDb),
            $this->assignTaxonomyTerms(),
            new BlockDocumentNormalizer(new EditorialBlockSecurityPolicy((array) ($this->config['cms']['editorial_security'] ?? []))),
        ));
    }

    public function publishContentEntry(): PublishContentEntry
    {
        return $this->once('publish_content_entry', fn() => new PublishContentEntry(
            $this->transactions(),
            $this->contentEntries(),
            $this->contentRevisions(),
            $this->publishedProjectionPipeline(),
            $this->outbox(),
            $this->hooks(),
            $this->userReferenceValidator(),
            new BlockDocumentNormalizer(new EditorialBlockSecurityPolicy((array) ($this->config['cms']['editorial_security'] ?? []))),
        ));
    }

    public function unpublishContentEntry(): UnpublishContentEntry
    {
        return $this->once('unpublish_content_entry', fn() => new UnpublishContentEntry(
            $this->transactions(),
            $this->contentEntries(),
            $this->publishedProjectionStore(),
            $this->outbox(),
            $this->userReferenceValidator(),
        ));
    }


    public function archiveDeleteContentEntry(): ArchiveDeleteContentEntry
    {
        return $this->once('archive_delete_content_entry', fn() => new ArchiveDeleteContentEntry(
            $this->transactions(),
            $this->coreDb,
            $this->contentEntries(),
            $this->publishedProjectionStore(),
            $this->outbox(),
        ));
    }

    public function assignTaxonomyTerms(): AssignTaxonomyTerms    {
        return $this->once('assign_taxonomy_terms', fn() => new AssignTaxonomyTerms($this->taxonomyAssignments()));
    }


    public function visualEditingMapBuilder(): VisualEditingMapBuilder
    {
        return $this->once('visual_editing_map_builder', fn() => new VisualEditingMapBuilder());
    }

    public function saveVisualField(): SaveVisualField
    {
        return $this->once('save_visual_field', fn() => new SaveVisualField($this->saveContentDraft()));
    }

    public function blockEditingLocks(): BlockEditingLockRepository
    {
        return $this->once('block_editing_locks', fn() => new BlockEditingLockRepository($this->coreDb, $this->iamDb));
    }

    public function generatePreviewUrl(): GeneratePreviewUrl
    {
        return $this->once('generate_preview_url', fn() => new GeneratePreviewUrl($this->config));
    }

    public function resolvePublicRoute(): ResolvePublicRoute
    {
        return $this->once('resolve_public_route', fn() => new ResolvePublicRoute(
            $this->publicContent(),
            $this->publicSearch(),
            $this->publicRouteReads(),
            $this->sites(),
            $this->taxonomies(),
            $this->coreDb,
            $this->cookies(),
            new BlockDocumentNormalizer(new EditorialBlockSecurityPolicy((array) ($this->config['cms']['editorial_security'] ?? []))),
            $this->productContentLinks(),
            $this->storefrontProjections(),
        ));
    }

    /**
     * @template T of object
     * @param callable():T $factory
     * @return T
     */
    private function once(string $key, callable $factory): object
    {
        if (!isset($this->instances[$key])) {
            $this->instances[$key] = $factory();
        }
        return $this->instances[$key];
    }
}
