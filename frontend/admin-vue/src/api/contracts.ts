export type ApiEnvelope<T> = { data: T; meta: ApiMeta };
export type ApiMeta = { contract: string; request_id?: string; contract_version?: string; site_id?: number; language_code?: string; pagination?: Pagination };
export type Pagination = { total: number; limit: number; offset: number; has_more: boolean };
export type ApiErrorPayload = { error: { code: string; message: string; details?: Record<string, unknown>; fields?: Record<string, string[]>; request_id?: string }; meta?: ApiMeta };

export type AdminLanguage = { code: string; name: string; url_prefix: string; is_default: boolean; is_enabled: boolean; is_current: boolean };
export type AdminUiLanguage = { code: 'fr'|'en'; name: string; is_current: boolean };
export type AdminSite = { id: number; site_key: string; name: string; host: string; base_path: string; request_base_path?: string; default_language_code: string; is_active?: boolean; admin_path?: string; public_path?: string; public_url?: string; is_current?: boolean };
export type AdminEndpoint = { key: string; method: 'GET'|'POST'|'PATCH'|'PUT'|'DELETE'; path: string; module_key?: string; scope?: string };
export type AdminModuleNavigationEntry = { key?: string; label: string; navLabel?: string; route: string; section?: string; permission?: string; anyPermission?: string[]; hint?: string; icon?: string; sort_order?: number; module_key?: string; source?: string };
export type AdminModuleBlueprint = { blueprint_key?: string; key?: string; resource?: string; resource_type?: string; label?: string; description?: string; admin?: Record<string, unknown> };
export type AdminModuleSummary = { key: string; name: string; version: string; description?: string; installed: boolean; enabled: boolean; features: Record<string, number|boolean|string>; dependencies?: string[]; permissions?: string[]; blueprints?: AdminModuleBlueprint[]; navigation?: AdminModuleNavigationEntry[]; endpoints?: AdminEndpoint[]; alerts?: string[] };
export type AdminModulesContext = { installed: AdminModuleSummary[]; active: AdminModuleSummary[]; features: Record<string, Record<string, unknown>>; permissions: string[]; navigation: AdminModuleNavigationEntry[]; endpoints: AdminEndpoint[]; contracts: Array<Record<string, unknown>>; dependency_alerts: Array<{ module_key: string; message: string }> };
export type AdminContext = {
  api: { contract_version: string; contract_header: string; csrf: { header: string; required_for: string[] } };
  site: AdminSite;
  available_sites?: AdminSite[];
  /** Content languages available for the current site. */
  content_languages: AdminLanguage[];
  /** Explicit content language currently edited. */
  current_content_language_code: string;
  /** Backward-compatible aliases kept during the admin API v1 transition. */
  languages?: AdminLanguage[];
  current_language_code?: string;
  /** Admin interface language, deliberately separate from content language. */
  ui: { language_code: 'fr'|'en'; available_languages: AdminUiLanguage[] };
  user: { id: number; email: string; name: string };
  permissions: string[];
  capabilities: Record<string, boolean>;
  site_roles: Array<Record<string, unknown>>;
  authorized_site_ids?: number[];
  csrf_token: string;
  features: Record<string, boolean>;
  endpoints: AdminEndpoint[];
  modules?: AdminModulesContext;
};


export type AdminProfile = {
  id: number;
  email: string;
  first_name: string;
  last_name: string;
  name: string;
  locale: string;
  is_active: boolean;
  last_login_at?: string | null;
  created_at?: string;
  updated_at?: string;
};
export type AdminProfilePayload = {
  profile: AdminProfile;
  schema?: Record<string, unknown>;
  actions?: Record<string, { method: string; path: string }>;
};

export type ContentTypeSummary = {
  type_key: string;
  name?: string;
  singular_label?: string;
  plural_label?: string;
  default_status?: string;
  capabilities?: Record<string, boolean>;
  is_active?: boolean;
};

export type EntryListItem = {
  id: number;
  site_id?: number;
  content_type_key: string;
  entry_key?: string;
  title: string;
  status: string;
  language_publication_status?: string;
  block_editorial_statuses?: { total: number; draft: number; review: number; ready: number; published: number; archived: number };
  full_path?: string | null;
  public_href?: string | null;
  created_at?: string;
  updated_at?: string;
  published_at?: string | null;
  system_kind?: 'shop';
  system_badge?: string;
  system_operational_status?: 'inactive'|'activating'|'active'|'error';
  editor_route?: string;
};

export type RevisionSummary = { id: number; revision_number?: number; language_code?: string; workflow_status?: string; created_at?: string; updated_at?: string; published_at?: string | null; revision_label?: string; summary?: string; change_notes?: string };

export type EntryShow = {
  entry: { id: number; site_id: number; content_type_key: string; entry_key: string; status: string; workflow_state?: string; created_at?: string; updated_at?: string; published_at?: string | null; permissions?: Record<string, boolean> };
  localization: { language_code: string; title: string; slug: string; blocks?: EditorialBlock[] };
  fields: Record<string, unknown>;
  seo: { meta_title: string; meta_description: string; meta_robots: string; canonical_url?: string; og_image_media_id?: number; og_image_src?: string; twitter_image_media_id?: number; twitter_image_src?: string };
  publication?: Record<string, unknown> | null;
  working_revision?: RevisionSummary | null;
  published_revision?: RevisionSummary | null;
  revisions?: RevisionSummary[];
  routes?: Array<{ id: number; language_code: string; full_path: string; status: string; is_primary: boolean; is_canonical: boolean }>;
  taxonomies?: EntryTaxonomyAssignment[];
  locks?: Array<Record<string, unknown>>;
  permissions?: Record<string, boolean>;
};


export type EntryTaxonomyAssignment = {
  taxonomy_id?: number;
  taxonomy_key: string;
  term_id: number;
  term_key?: string;
  name?: string;
  slug?: string;
  full_path?: string;
  sort_order?: number;
};

export type TaxonomyTermOption = {
  id: number;
  taxonomy_id?: number;
  parent_id?: number | null;
  term_key: string;
  name: string;
  slug: string;
  full_path?: string;
  sort_order: number;
  localizations?: Record<string, { name?: string; slug?: string; full_path?: string; description?: string }>;
};

export type TaxonomyPolicyItem = {
  id: number;
  site_id?: number;
  taxonomy_key: string;
  name: string;
  description?: string;
  is_hierarchical?: boolean;
  is_localized?: boolean;
  seo_enabled?: boolean;
  archive_enabled?: boolean;
  is_required: boolean;
  max_terms: number | null;
  terms: TaxonomyTermOption[];
};

export type TaxonomyPolicy = Record<string, unknown> & {
  taxonomies?: TaxonomyPolicyItem[];
};

export type EditorialBlock = { id: string; type: 'markdown'|'richtext'|'html_safe'|'html_raw'|'iframe'|'embed'|'image'|'video'|'audio'|'hero'|'gallery'|'buttons'|'card'|'columns'|'form'|'plan'|'articles'|'commerce_product'|'commerce_product_variants'|'commerce_product_list'|'storytelling'; enabled: boolean; editorial_status?: 'draft'|'review'|'ready'|'published'|'archived'; label?: string; css_class?: string; anchor?: string; data: Record<string, unknown>; sort_order?: number };

export type SaveDraftPayload = { taxonomy_terms?: Record<string, number[]> };

export type BlueprintSummary = { blueprint_key: string; resource_type: string; site_id?: number | null; version?: number; version_label?: string | null; is_active?: boolean };
export type EditorFieldType = 'text'|'textarea'|'richtext'|'markdown'|'number'|'boolean'|'date'|'datetime'|'slug'|'media'|'relation'|'select'|'multiselect'|'json'|'group'|'repeater'|string;

export type NativeFieldType = { handle: string; display: string; storage: string; component: string; implemented: boolean; localizable: boolean };
export type NativeFieldDefinition = { handle: string; type: string; display: string; instructions?: string; required?: boolean; default?: unknown; localizable?: boolean; listable?: boolean; sortable?: boolean; visibility?: 'visible'|'readonly'|'computed'|'hidden'; width?: number; validate?: Record<string, unknown>; config?: Record<string, unknown> };
export type NativeBlueprintSection = { key: string; display: string; fields: NativeFieldDefinition[] };
export type NativeBlockBlueprint = { key: EditorialBlock['type']; title: string; type: 'block'; icon?: string; schema_version: number; sections: NativeBlueprintSection[]; defaults?: Record<string, unknown> };
export type BlockBlueprintIndex = { schema_version: number; blueprints: NativeBlockBlueprint[]; fieldsets: Record<string, unknown>; field_types: NativeFieldType[] };


export type SaveDraftResult = { entry_id: number; revision_id: number; content_type_key: string; language_code: string; created: boolean; status: string; next_actions: { show: { method: string; path: string }; publish: { method: string; path: string; revision_id: number } } };
export type PublishResult = { entry_id: number; language_code: string; published_revision_id: number; published_at: string; status: string; workflow_state: string; critical_projections: { mode: string; status: string; items: string[] }; secondary_events: string[] };
export type EntryLifecycleResult = { entry_id: number; site_id?: number; status: 'archived'|'deleted'|string; language_code?: string | null; affected_languages?: string[]; removed_routes?: string[]; redirect_to?: string | null; http_code?: number | null; archived_at?: string; deleted_at?: string };
export type PreviewResult = { aggregate: Record<string, unknown>; preview_url: string };
export type RevisionRestoreResult = { entry_id: number; language_code: string; restored_from_revision_id: number; working_revision: RevisionSummary; preview_url?: string };
export type RevisionPruneResult = { entry_id: number; language_code: string; deleted_revisions: number; kept_revisions: number; published_revision_id: number; published_revision_number: number; kept_revision_ids?: number[]; deleted_revision_ids?: number[]; revisions?: RevisionSummary[] };

export type EditorSchema = {
  blueprint?: BlueprintSummary;
  content_type?: ContentTypeSummary;
  fields?: SchemaField[];
  system_context?: { fields?: SchemaField[] };
  editor_tabs?: SchemaTab[];
  seo_policy?: Record<string, unknown>;
  taxonomy_policy?: TaxonomyPolicy;
  workflow_policy?: { enabled: boolean; default_state?: string; states?: string[]; publish_requires_revision_id?: boolean; publish_is_language_scoped?: boolean; actions?: Record<string, unknown> };
  routing_policy?: Record<string, unknown>;
  template_binding?: Record<string, unknown>;
  ui_schema?: Record<string, unknown>;
  validation?: { required?: string[]; field_rules?: Record<string, unknown>; [key: string]: unknown };
  translation_policy?: Record<string, unknown>;
  permissions_policy?: Record<string, unknown>;
  headless?: Record<string, unknown>;
  compatibility?: Record<string, unknown>;
  // Backward-compatible fallback for older frozen JSON examples.
  type_key?: string;
  label?: string;
  tabs?: SchemaTab[];
  seo?: { enabled: boolean; fields?: SchemaField[] };
  workflow?: { enabled: boolean; states?: string[] };
};

export type SchemaTab = { key: string; label: string; fields: SchemaField[] | string[]; sort_order?: number };
export type SchemaField = {
  key?: string;
  field_key?: string;
  label: string;
  type?: EditorFieldType;
  field_type?: EditorFieldType;
  required?: boolean;
  is_required?: boolean;
  localized?: boolean;
  is_localized?: boolean;
  help?: string;
  help_text?: string;
  placeholder?: string;
  default_value?: unknown;
  options?: Array<{ value: string|number|boolean; label: string }>;
  fields?: SchemaField[];
  children?: SchemaField[];
  tab_key?: string;
  sort_order?: number;
  width?: number;
  config?: Record<string, unknown>;
  field_scope?: 'editorial'|'system_editable'|'system_context'|string;
  scope?: 'editorial'|'system_editable'|'system_context'|string;
  value_source?: 'content'|'context'|'workflow'|'relation'|'computed'|string;
  source?: 'content'|'context'|'workflow'|'relation'|'computed'|string;
  ui_visibility?: 'form'|'summary'|'hidden'|'readonly'|'native'|string;
  editable?: boolean;
  is_editable?: boolean;
  visibility?: 'visible'|'readonly'|'computed'|'hidden'|'form'|'summary'|'native'|string;
  validation?: Record<string, unknown>;
  ui?: Record<string, unknown>;
};

export type MenuSummary = { id?: number; menu_key?: string; key?: string; name?: string; menu_location?: string; is_active?: boolean };
export type MediaVariant = { id?: number; variant_key?: string; public_url?: string; path?: string; width?: number; height?: number; format?: string; mime_type?: string; size_bytes?: number; generation_status?: string; created_at?: string };
export type ResponsiveMediaSets = { content?: MediaVariant[]; hero?: MediaVariant[]; open_graph?: MediaVariant[] };
export type MediaLocalization = { language_code?: string; title?: string | null; alt_text?: string | null; caption?: string | null };
export type MediaUsage = { id?: number; resource_type?: string; resource_id?: number; field_key?: string; language_code?: string; usage_context?: string; source_revision_id?: number; alt_policy?: string; alt_text_snapshot?: string | null; entry_key?: string; entry_title?: string; entry_path?: string; created_at?: string; updated_at?: string };
export type MediaAsset = { id: number; filename?: string; original_filename?: string; mime_type?: string; media_type?: string; width?: number; height?: number; size_bytes?: number; public_url?: string; thumbnail_url?: string; path?: string; public_path?: string; folder_name?: string; alt_text?: string; caption?: string; title?: string; usage_count?: number; variants_status?: string; metadata_status?: string; variants?: MediaVariant[]; responsive_sets?: ResponsiveMediaSets; usages?: MediaUsage[]; created_at?: string };
export type MediaHygieneReport = { language_code: string; summary: Record<string, number>; checks: Record<string, MediaAsset[]> };
export type MediaSettings = { max_upload_mb?: number; allowed_mime_types?: string[]; auto_generate_variants?: boolean; require_alt_text?: boolean; default_folder_key?: string; default_social_image_media_id?: number | null; social_image_policy?: Record<string, unknown> };
export type TaxonomySummary = { id?: number; taxonomy_key?: string; key?: string; name?: string; label?: string; description?: string; is_hierarchical?: boolean | number | string; is_localized?: boolean | number | string; seo_enabled?: boolean | number | string; archive_enabled?: boolean | number | string; is_active?: boolean | number | string; sort_order?: number };


export type ConfigurationField = {
  key: string;
  label: string;
  type: 'text'|'textarea'|'number'|'boolean'|'select'|'language'|'media'|'array'|'site_asset'|'color';
  required: boolean;
  validation?: { min?: number; max?: number; pattern?: string; description?: string; system_blueprint_handle?: string; interface?: string; asset_kind?: 'logo'|'favicon'; accept?: string; preview_query_parameter?: string; options?: Array<{ value: string|number|boolean; label: string }>; appearance_defaults?: Record<string, Record<string, string>> };
};
export type ConfigurationGroup = { key: string; label: string; description: string; permission: string; fields: ConfigurationField[] };

export type MultisiteSite = {
  id: number;
  site_key: string;
  name: string;
  default_language_code: string;
  is_active: boolean;
  host: string;
  base_path: string;
  scheme: 'http'|'https'|string;
  enforce_https: boolean;
  public_url: string;
  admin_path: string;
  content_count: number;
  language_count: number;
  created_at?: string;
  updated_at?: string;
};
export type MultisitePayload = {
  main_site: MultisiteSite;
  subsites: MultisiteSite[];
  defaults: { default_language_code: string; scheme: string; enforce_https: boolean };
  actions: Record<string, { method: string; path: string }>;
};

export type ConfigurationPayload = {
  schema_version: string;
  groups: ConfigurationGroup[];
  values: Record<string, Record<string, unknown>>;
  actions: Record<string, { method: string; path: string }>;
};


export type FormFieldTranslation = { language_code: string; label: string; placeholder?: string|null; help_text?: string|null; options?: Array<{ label: string; value: string }> };
export type FormField = { id?: number; field_key: string; field_type: 'text'|'textarea'|'email'|'tel'|'url'|'number'|'select'|'radio'|'checkbox'|'checkboxes'|'hidden'|'date'|'consent'; sort_order?: number; is_required?: boolean; is_active?: boolean; width?: 'full'|'half'|'third'; default_value?: string|null; validation?: Record<string, unknown>; settings?: Record<string, unknown>; translations: FormFieldTranslation[] };
export type FormTranslation = { language_code: string; name: string; description_text?: string|null; submit_label?: string; success_message?: string };
export type FormDefinition = { id?: number; site_id?: number; form_key: string; name?: string; status: 'draft'|'published'|'archived'; is_active: boolean; store_submissions?: boolean; notification_enabled?: boolean; notification_recipients?: string[]; notification_subject?: string|null; honeypot_field?: string; min_submit_seconds?: number; rate_limit_max_attempts?: number; rate_limit_window_seconds?: number; translations: FormTranslation[]; fields: FormField[]; field_count?: number; submission_count?: number; created_at?: string; updated_at?: string };
export type FormSubmission = { id: number; form_id: number; language_code?: string; submission_status: string; created_at: string; payload: Record<string, unknown>; spam_score: number; spam_reasons: string[] };
