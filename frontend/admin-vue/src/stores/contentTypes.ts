import { defineStore } from 'pinia';
import { adminApi, apiErrorMessage } from '@/api/client';
import type { ContentTypeSummary, EditorSchema, SchemaField, SchemaTab } from '@/api/contracts';

const CORE_FIELD_KEYS = new Set(['title', 'slug', 'entry_key', 'meta_title', 'meta_description', 'meta_robots', 'canonical_url']);

function rawField(field: SchemaField): Record<string, unknown> { return field as Record<string, unknown>; }
function fieldHidden(field: SchemaField): boolean {
  const raw = rawField(field);
  const config = fieldConfig(field);
  const visibility = String(raw.ui_visibility ?? raw.visibility ?? config.ui_visibility ?? config.visibility ?? 'form');
  const scope = String(raw.field_scope ?? raw.scope ?? config.field_scope ?? config.scope ?? 'editorial');
  return Boolean(raw.is_hidden ?? raw.hidden) || visibility === 'hidden' || visibility === 'summary' || visibility === 'native' || scope === 'system_context';
}
function asArray<T>(value: unknown): T[] { return Array.isArray(value) ? value as T[] : []; }
function fieldSort(field: SchemaField): number { return Number(rawField(field).sort_order ?? field.ui?.sort_order ?? 0); }

export function fieldKey(field: SchemaField): string { return String(field.field_key ?? field.key ?? rawField(field).field_handle ?? rawField(field).handle ?? ''); }
export function fieldType(field: SchemaField): string {
  const raw = rawField(field);
  const direct = String(field.field_type ?? field.type ?? raw.fieldType ?? '').toLowerCase();
  const interfaceKey = String(raw.interface_key ?? raw.ui_interface ?? fieldConfig(field).interface_key ?? '').toLowerCase();
  if (['assets', 'asset', 'media'].includes(interfaceKey) && (direct === '' || direct === 'text' || direct === 'string' || direct === 'assets' || direct === 'asset')) return 'media';
  if (direct === 'assets' || direct === 'asset') return 'media';
  return direct || 'text';
}
export function fieldRequired(field: SchemaField): boolean { return Boolean(field.is_required ?? field.required); }
export function fieldHelp(field: SchemaField): string {
  const config = fieldConfig(field);
  return String(field.help_text ?? field.help ?? rawField(field).instructions ?? config.help_text ?? config.help ?? config.instructions ?? '').trim();
}
export function fieldLabel(field: SchemaField): string { return String(field.label ?? rawField(field).display ?? fieldKey(field)); }
export function fieldConfig(field: SchemaField): Record<string, unknown> { return (rawField(field).config && typeof rawField(field).config === 'object') ? rawField(field).config as Record<string, unknown> : {}; }
export function fieldOptions(field: SchemaField): Array<{ value: string|number|boolean; label: string }> {
  const config = fieldConfig(field);
  const options = field.options ?? asArray<{ value: string|number|boolean; label: string }>(field.validation?.options) ?? asArray<{ value: string|number|boolean; label: string }>(config.options);
  return Array.isArray(options) ? options.map((option) => ({ value: option.value, label: String(option.label ?? option.value) })) : [];
}
export function fieldWidthClass(field: SchemaField): string {
  const width = Number(rawField(field).width ?? field.ui?.width ?? fieldConfig(field).width ?? 100);
  if (width <= 33) return 'schema-field--third';
  if (width <= 50) return 'schema-field--half';
  return 'schema-field--full';
}
export function fieldChildren(field: SchemaField): SchemaField[] {
  const raw = rawField(field);
  return asArray<SchemaField>(raw.fields ?? raw.children ?? fieldConfig(field).fields).sort((a, b) => fieldSort(a) - fieldSort(b));
}

function maybeSchemaField(value: unknown): SchemaField | null {
  if (!value || typeof value !== 'object') return null;
  const raw = value as Record<string, unknown>;
  const handle = String(raw.field_key ?? raw.key ?? raw.field_handle ?? raw.handle ?? '').trim();
  if (handle === '') return null;
  return value as SchemaField;
}

function collectNestedFields(value: unknown, output: SchemaField[], seen: Set<unknown>): void {
  if (Array.isArray(value)) {
    value.forEach((item) => collectNestedFields(item, output, seen));
    return;
  }
  if (!value || typeof value !== 'object' || seen.has(value)) return;
  seen.add(value);
  const direct = maybeSchemaField(value);
  if (direct) output.push(direct);
  const raw = value as Record<string, unknown>;
  for (const key of ['fields', 'children', 'items']) {
    const list = raw[key];
    if (Array.isArray(list)) list.forEach((item) => collectNestedFields(item, output, seen));
  }
  const config = raw.config;
  if (config && typeof config === 'object') collectNestedFields(config, output, seen);
  const tabs = raw.editor_tabs ?? raw.tabs;
  if (Array.isArray(tabs)) tabs.forEach((tab) => collectNestedFields(tab, output, seen));
}

export function blueprintSchemaFields(schema: EditorSchema | null): SchemaField[] {
  if (!schema) return [];
  const output: SchemaField[] = [];
  const seen = new Set<unknown>();
  collectNestedFields(schema.fields ?? [], output, seen);
  collectNestedFields(schema.system_context?.fields ?? [], output, seen);
  collectNestedFields(schema.editor_tabs ?? [], output, seen);
  collectNestedFields(schema.tabs ?? [], output, seen);
  collectNestedFields(schema.ui_schema ?? {}, output, seen);
  collectNestedFields(schema.seo?.fields ?? [], output, seen);
  return output;
}

export function blueprintField(schema: EditorSchema | null, aliases: string | string[]): SchemaField | null {
  const wanted = new Set((Array.isArray(aliases) ? aliases : [aliases]).map((item) => String(item).trim()).filter(Boolean));
  if (wanted.size === 0) return null;
  return blueprintSchemaFields(schema).find((field) => wanted.has(fieldKey(field))) ?? null;
}

export function blueprintFieldHelp(schema: EditorSchema | null, aliases: string | string[]): string {
  const field = blueprintField(schema, aliases);
  return field ? fieldHelp(field) : '';
}

export function coreSchemaFields(schema: EditorSchema | null): SchemaField[] {
  const tabFields = asArray<SchemaTab>(schema?.editor_tabs ?? schema?.tabs)
    .flatMap((tab) => asArray<SchemaField|string>(tab.fields))
    .filter((item): item is SchemaField => typeof item === 'object' && item !== null);
  return [
    ...asArray<SchemaField>(schema?.fields),
    ...asArray<SchemaField>(schema?.system_context?.fields),
    ...tabFields,
  ];
}
export function coreFieldRequired(schema: EditorSchema | null, key: 'title' | 'slug' | 'entry_key'): boolean {
  const required = asArray<string>(schema?.validation?.required).map(String);
  if (required.includes(key)) return true;
  const field = coreSchemaFields(schema).find((item) => fieldKey(item) === key);
  return field ? fieldRequired(field) : key !== 'entry_key';
}
export function isSeoEnabled(schema: EditorSchema | null): boolean {
  const policy = schema?.seo_policy ?? schema?.seo ?? {};
  return Boolean((policy as Record<string, unknown>).enabled ?? schema?.content_type?.capabilities?.seo ?? true);
}
export function isRoutingEnabled(schema: EditorSchema | null): boolean {
  const policy = schema?.routing_policy ?? {};
  return Boolean((policy as Record<string, unknown>).enabled ?? schema?.content_type?.capabilities?.permalink ?? true);
}
export function isBlocksEnabled(schema: EditorSchema | null): boolean {
  const caps = schema?.content_type?.capabilities ?? {};
  const policy = (schema?.ui_schema ?? {}) as Record<string, unknown>;
  return Boolean((policy.blocks_enabled ?? caps.layout ?? true));
}
export function schemaWorkflow(schema: EditorSchema | null): EditorSchema['workflow_policy'] {
  return schema?.workflow_policy ?? (schema?.workflow ? { enabled: schema.workflow.enabled, states: schema.workflow.states } : { enabled: true, states: ['draft', 'review', 'published', 'archived', 'scheduled'], publish_requires_revision_id: true });
}

export function contentTypeKeyCandidates(rawType: string): string[] {
  const raw = String(rawType || '').trim().toLowerCase().replace(/^\/+|\/+$/g, '');
  const cleaned = raw.replace(/[^a-z0-9_\-/]/g, '');
  const segments = cleaned.split('/').filter(Boolean);
  const semantic = [...segments].reverse().find((segment) => !['contents', 'content', 'new', 'edit'].includes(segment) && !/^\d+$/.test(segment));
  const normalized = semantic || cleaned;
  const aliases: Record<string, string> = { pages: 'page', articles: 'article', page_archives: 'page_archive', pages_archive: 'page_archive', archives_pages: 'page_archive', article_archives: 'article_archive', articles_archive: 'article_archive', archives_articles: 'article_archive' };
  return [normalized, aliases[normalized] || '', normalized.replace(/s$/, '')].filter((candidate, index, all) => candidate !== '' && all.indexOf(candidate) === index);
}

export function schemaTypeKey(schema: EditorSchema | null, fallback = ''): string {
  return String(schema?.content_type?.type_key ?? schema?.type_key ?? schema?.blueprint?.blueprint_key ?? fallback);
}

export function schemaLabel(schema: EditorSchema | null, fallback = 'Structure'): string {
  return String(schema?.content_type?.singular_label ?? schema?.content_type?.name ?? schema?.label ?? fallback);
}

export function schemaTabs(schema: EditorSchema | null): Array<{ key: string; label: string; fields: SchemaField[] }> {
  if (!schema) return [];
  const fields = [...(schema.fields ?? [])].sort((a, b) => fieldSort(a) - fieldSort(b));
  const byKey = new Map(fields.map((field) => [fieldKey(field), field]));
  const rawTabs = [...(schema.editor_tabs ?? schema.tabs ?? [])].sort((a, b) => Number(a.sort_order ?? 0) - Number(b.sort_order ?? 0));
  const fieldAllowed = (field: SchemaField) => !CORE_FIELD_KEYS.has(fieldKey(field)) && !fieldHidden(field);
  if (rawTabs.length === 0) return [{ key: 'content', label: 'Champs du blueprint', fields: fields.filter(fieldAllowed) }];
  return rawTabs.map((tab: SchemaTab) => {
    const listed = tab.fields ?? [];
    const tabFields = listed.length > 0
      ? listed.map((item) => typeof item === 'string' ? byKey.get(item) : item).filter(Boolean) as SchemaField[]
      : fields.filter((field) => String(rawField(field).tab_key ?? 'content') === tab.key);
    return { key: tab.key, label: tab.label, fields: tabFields.filter(fieldAllowed).sort((a, b) => fieldSort(a) - fieldSort(b)) };
  }).filter((tab) => tab.fields.length > 0);
}

export const useContentTypesStore = defineStore('contentTypes', {
  state: () => ({
    types: [] as ContentTypeSummary[],
    schemas: {} as Record<string, EditorSchema>,
    loading: false,
    error: ''
  }),
  actions: {
    async loadTypes() {
      if (this.types.length > 0) return;
      this.loading = true;
      this.error = '';
      try {
        const response = await adminApi.get<ContentTypeSummary[]>('/content-types');
        this.types = response.data;
      } catch (error) {
        this.error = apiErrorMessage(error, 'Types de contenus indisponibles.');
      } finally {
        this.loading = false;
      }
    },
    async loadSchema(typeKey: string, siteId?: number) {
      const candidates = contentTypeKeyCandidates(typeKey);
      const siteCacheKey = (candidate: string) => `${candidate}::site:${siteId || 'global'}`;
      const cachedKey = candidates.find((candidate) => this.schemas[siteCacheKey(candidate)]);
      if (cachedKey) return this.schemas[siteCacheKey(cachedKey)];
      this.loading = true;
      this.error = '';
      let lastError: unknown = null;
      try {
        for (const candidate of candidates) {
          try {
            const response = await adminApi.get<EditorSchema>(`/blueprints/${candidate}/editor-schema`, { site_id: siteId });
            const resolvedKey = schemaTypeKey(response.data, candidate);
            this.schemas[siteCacheKey(resolvedKey)] = response.data;
            this.schemas[siteCacheKey(candidate)] = response.data;
            return response.data;
          } catch (error) { lastError = error; }
        }
        for (const candidate of candidates) {
          try {
            const response = await adminApi.get<EditorSchema>(`/content-types/${candidate}/editor-schema`, { site_id: siteId });
            const resolvedKey = schemaTypeKey(response.data, candidate);
            this.schemas[siteCacheKey(resolvedKey)] = response.data;
            this.schemas[siteCacheKey(candidate)] = response.data;
            return response.data;
          } catch (error) { lastError = error; }
        }
        throw lastError;
      } catch (error) {
        this.error = apiErrorMessage(error, 'Schéma éditeur indisponible.');
        throw error;
      } finally {
        this.loading = false;
      }
    }
  }
});
