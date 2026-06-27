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
use App\Modules\Business\Repositories\BusinessConsentRepository;
use App\Modules\Business\Repositories\BusinessContactRepository;
use App\Modules\Business\Repositories\BusinessMemoRepository;
use App\Modules\Business\Repositories\BusinessMessagingRepository;
use App\Modules\Business\Repositories\BusinessMailingRepository;
use App\Modules\Business\Repositories\BusinessTagRepository;
use App\Modules\Business\Services\BusinessConsentService;
use App\Modules\Business\Services\BusinessCsvService;
use App\Modules\Business\Services\BusinessCrmService;
use App\Modules\Business\Services\BusinessDatabaseConnection;
use App\Modules\Business\Services\BusinessMemoSharingService;
use App\Modules\Business\Services\BusinessMessagingOutboxService;
use App\Modules\Business\Services\BusinessMessagingProviderManager;
use App\Modules\Business\Services\BusinessMailingService;

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

    public function businessCompanies(): BusinessCompanyRepository
    {
        return $this->once('business_companies', fn() => new BusinessCompanyRepository($this->businessDatabaseConnection()->database()));
    }

    public function businessContacts(): BusinessContactRepository
    {
        return $this->once('business_contacts', fn() => new BusinessContactRepository($this->businessDatabaseConnection()->database()));
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
