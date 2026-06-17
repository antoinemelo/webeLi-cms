import { defineStore } from 'pinia';
import { AdminApiError, adminApi, apiErrorMessage } from '@/api/client';
import type { EditorialBlock, EntryLifecycleResult, EntryListItem, EntryShow, Pagination, PreviewResult, PublishResult, RevisionPruneResult, RevisionRestoreResult, SaveDraftResult } from '@/api/contracts';

export type EntryFilters = { type?: string; status?: string; q?: string; limit?: number; offset?: number };
export type DraftPayload = {
  site_id?: number;
  language_code: string;
  content_type_key: string;
  entry_key?: string;
  title: string;
  slug: string;
  fields: Record<string, unknown>;
  blocks?: EditorialBlock[];
  meta_title?: string;
  meta_description?: string;
  meta_robots?: string;
  change_notes?: string;
  expected_working_revision_id?: number;
  taxonomy_terms?: Record<string, number[]>;
};

export const useContentEntriesStore = defineStore('contentEntries', {
  state: () => ({
    rows: [] as EntryListItem[],
    pagination: null as Pagination | null,
    current: null as EntryShow | null,
    loading: false,
    saving: false,
    publishing: false,
    previewing: false,
    restoring: false,
    pruningRevisions: false,
    archiving: false,
    deleting: false,
    error: '',
    message: '',
    fieldErrors: {} as Record<string, string[]>
  }),
  actions: {
    clearFeedback() { this.error = ''; this.message = ''; this.fieldErrors = {}; },
    async list(filters: EntryFilters, siteId?: number, languageCode = 'fr') {
      this.loading = true;
      this.clearFeedback();
      try {
        const response = await adminApi.get<EntryListItem[]>('/entries', {
          site_id: siteId,
          language_code: languageCode,
          type: filters.type,
          status: filters.status,
          q: filters.q,
          limit: filters.limit ?? 50,
          offset: filters.offset ?? 0
        });
        this.rows = response.data;
        this.pagination = response.meta.pagination ?? null;
      } catch (error) {
        this.error = apiErrorMessage(error, 'Chargement des contenus impossible.');
      } finally {
        this.loading = false;
      }
    },
    async load(id: string | number, siteId?: number, languageCode = 'fr', options: { clearFeedback?: boolean; background?: boolean } = {}) {
      if (!options.background) this.loading = true;
      if (options.clearFeedback !== false) this.clearFeedback();
      try {
        const response = await adminApi.get<EntryShow>(`/entries/${id}`, { site_id: siteId, language_code: languageCode });
        this.current = response.data;
        return response.data;
      } catch (error) {
        this.error = apiErrorMessage(error, 'Chargement du contenu impossible.');
        throw error;
      } finally {
        if (!options.background) this.loading = false;
      }
    },
    async save(id: string | number | undefined, payload: DraftPayload, options: { silent?: boolean } = {}) {
      this.saving = true;
      this.clearFeedback();
      try {
        const response = id
          ? await adminApi.patch<SaveDraftResult>(`/entries/${id}`, payload)
          : await adminApi.post<SaveDraftResult>('/entries', payload);
        if (!options.silent) this.message = 'Brouillon enregistré.';
        return response.data;
      } catch (error) {
        this.fieldErrors = extractFieldErrors(error);
        this.error = apiErrorMessage(error, 'Enregistrement du brouillon impossible.');
        throw error;
      } finally {
        this.saving = false;
      }
    },
    async preview(id: string | number, siteId: number | undefined, languageCode: string, revisionId?: number) {
      this.previewing = true;
      this.clearFeedback();
      try {
        const response = revisionId
          ? await adminApi.get<PreviewResult>(`/entries/${id}/revisions/${revisionId}/preview`, { site_id: siteId, language_code: languageCode })
          : await adminApi.get<PreviewResult>(`/entries/${id}/preview`, { site_id: siteId, language_code: languageCode });
        return response.data.preview_url;
      } catch (error) {
        this.fieldErrors = extractFieldErrors(error);
        this.error = apiErrorMessage(error, 'Prévisualisation impossible.');
        throw error;
      } finally {
        this.previewing = false;
      }
    },
    async restoreRevision(id: string | number, revisionId: number, siteId: number | undefined, languageCode: string) {
      this.restoring = true;
      this.clearFeedback();
      try {
        const response = await adminApi.post<RevisionRestoreResult>(`/entries/${id}/revisions/${revisionId}/restore`, { site_id: siteId, language_code: languageCode });
        this.message = 'Ancienne version restaurée comme nouveau brouillon.';
        return response.data;
      } catch (error) {
        this.error = apiErrorMessage(error, 'Restauration impossible.');
        throw error;
      } finally {
        this.restoring = false;
      }
    },
    async pruneRevisionsBeforePublished(id: string | number, siteId: number | undefined, languageCode: string) {
      this.pruningRevisions = true;
      this.clearFeedback();
      try {
        const response = await adminApi.delete<RevisionPruneResult>(`/entries/${id}/revisions/prune-before-published`, { site_id: siteId, language_code: languageCode });
        if (this.current && Number(this.current.entry?.id || 0) === Number(id)) {
          if (Array.isArray(response.data.revisions)) {
            this.current.revisions = response.data.revisions;
          } else if (Array.isArray(response.data.kept_revision_ids)) {
            const kept = new Set(response.data.kept_revision_ids.map((revisionId) => Number(revisionId)));
            this.current.revisions = (this.current.revisions || []).filter((revision) => kept.has(Number(revision.id || 0)));
          }
        }
        this.message = `${response.data.deleted_revisions} ancienne(s) version(s) effacée(s).`;
        return response.data;
      } catch (error) {
        this.error = apiErrorMessage(error, 'Nettoyage des anciennes versions impossible.');
        throw error;
      } finally {
        this.pruningRevisions = false;
      }
    },

    async archive(id: string | number, payload: { site_id?: number; language_code?: string; redirect_to?: string; http_code?: number }) {
      this.archiving = true;
      this.clearFeedback();
      try {
        const response = await adminApi.post<EntryLifecycleResult>(`/entries/${id}/archive`, payload);
        this.message = response.data.redirect_to ? 'Contenu archivé et redirection créée.' : 'Contenu archivé. Anciennes URL marquées comme retirées.';
        return response.data;
      } catch (error) {
        this.error = apiErrorMessage(error, 'Archivage impossible.');
        throw error;
      } finally {
        this.archiving = false;
      }
    },
    async destroy(id: string | number, payload: { site_id?: number; language_code?: string; confirm: 'DELETE'; redirect_to?: string; http_code?: number }) {
      this.deleting = true;
      this.clearFeedback();
      try {
        const response = await adminApi.delete<EntryLifecycleResult>(`/entries/${id}`, payload);
        this.message = response.data.redirect_to ? 'Contenu supprimé et redirection créée.' : 'Contenu supprimé. Anciennes URL marquées comme retirées.';
        return response.data;
      } catch (error) {
        this.error = apiErrorMessage(error, 'Suppression impossible.');
        throw error;
      } finally {
        this.deleting = false;
      }
    },
    async publish(id: string | number, payload: { site_id?: number; language_code: string; revision_id: number; expected_working_revision_id: number }) {
      this.publishing = true;
      this.clearFeedback();
      try {
        const response = await adminApi.post<PublishResult>(`/entries/${id}/publish`, payload);
        this.message = 'Publication effectuée. Les projections critiques sont prêtes.';
        return response.data;
      } catch (error) {
        this.fieldErrors = extractFieldErrors(error);
        this.error = apiErrorMessage(error, 'Publication impossible.');
        throw error;
      } finally {
        this.publishing = false;
      }
    }
  }
});

function extractFieldErrors(error: unknown): Record<string, string[]> {
  if (!(error instanceof AdminApiError)) return {};
  const normalized: Record<string, string[]> = {};
  for (const [key, messages] of Object.entries(error.fields || {})) {
    normalized[key] = Array.isArray(messages) ? messages.map(String) : [String(messages)];
    const cleaned = key.replace(/^data\./, '').replace(/^fields\./, '');
    if (cleaned !== key) normalized[cleaned] = normalized[key];
  }
  return normalized;
}
