<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import type { MediaAsset, MediaHygieneReport, MediaLocalization, MediaSettings, MediaUsage, MediaVariant } from '@/api/contracts';
import PageHeader from '@/components/ui/PageHeader.vue';
import DataTable from '@/components/ui/DataTable.vue';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import { useAdminContextStore } from '@/stores/adminContext';

const context = useAdminContextStore();
const rows = ref<MediaAsset[]>([]);
const loading = ref(false);
const uploading = ref(false);
const saving = ref(false);
const error = ref('');
const q = ref('');
const typeFilter = ref<'all'|'image'|'video'|'audio'|'document'>('all');
const onlyWithoutAlt = ref(false);
const selected = ref<MediaAsset | null>(null);
const lang = computed(() => context.contentLanguageCode || 'fr');
const edit = ref({ alt_text: '', caption: '', title: '', copyright_text: '', license_type: '', source_url: '' });
const fileInput = ref<HTMLInputElement | null>(null);
const selectedUploadName = ref('');
const uploadFolderKey = ref('');
const mediaSettings = ref<MediaSettings | null>(null);
const hygiene = ref<MediaHygieneReport | null>(null);
const hygieneLoading = ref(false);
const detailLoading = ref(false);
const variants = ref<MediaVariant[]>([]);
const usages = ref<MediaUsage[]>([]);
const localizations = ref<MediaLocalization[]>([]);
const metadataDirty = ref(false);
let detailRequestSeq = 0;

const filteredRows = computed(() => rows.value.filter((asset) => {
  const haystack = [asset.original_filename, asset.filename, asset.mime_type, asset.media_type, asset.folder_name, asset.alt_text, asset.caption, asset.title].filter(Boolean).join(' ').toLowerCase();
  const matchesSearch = !q.value.trim() || haystack.includes(q.value.trim().toLowerCase());
  const matchesAlt = !onlyWithoutAlt.value || (String(asset.media_type || '').toLowerCase() === 'image' && !String(asset.alt_text ?? '').trim());
  const matchesType = typeFilter.value === 'all' || String(asset.media_type || '').toLowerCase() === typeFilter.value;
  return matchesSearch && matchesAlt && matchesType;
}));

function normalize(data: unknown): MediaAsset[] {
  if (Array.isArray(data)) return data as MediaAsset[];
  if (data && typeof data === 'object' && Array.isArray((data as { assets?: unknown[] }).assets)) return (data as { assets: MediaAsset[] }).assets;
  return [];
}

function localizationFor(languageCode: string): MediaLocalization | null {
  return localizations.value.find((item) => item.language_code === languageCode) ?? null;
}

function localizedValue(value: unknown): string {
  return value == null ? '' : String(value);
}

function applyEditFromAsset(asset: MediaAsset, languageCode: string, localizationList: MediaLocalization[] = [], allowAssetFallback = false): void {
  const loc = localizationList.find((item) => item.language_code === languageCode);
  edit.value = {
    title: localizedValue(loc?.title ?? (allowAssetFallback ? asset.title : '')),
    alt_text: localizedValue(loc?.alt_text ?? (allowAssetFallback ? asset.alt_text : '')),
    caption: localizedValue(loc?.caption ?? (allowAssetFallback ? asset.caption : '')),
    copyright_text: String((asset as any).copyright_text ?? ''),
    license_type: String((asset as any).license_type ?? ''),
    source_url: String((asset as any).source_url ?? '')
  };
  metadataDirty.value = false;
}


function languageName(languageCode: string): string {
  const code = String(languageCode || '').toLowerCase();
  const names: Record<string, string> = { fr: 'français', en: 'anglais', de: 'allemand', it: 'italien', es: 'espagnol' };
  return names[code] || code.toUpperCase();
}

function pluralizeUsage(count: number): string {
  return count > 1 ? 'usages' : 'usage';
}

const activeLanguageUsageCount = computed(() => usages.value.length);
const selectedMetaSummary = computed(() => {
  if (!selected.value) return '';
  const dimensions = selected.value.width && selected.value.height ? `${selected.value.width}×${selected.value.height}` : 'dimensions inconnues';
  const count = activeLanguageUsageCount.value;
  return `${selected.value.mime_type || 'type inconnu'} · ${dimensions} · ${count} ${pluralizeUsage(count)} en ${languageName(lang.value)}`;
});

function filenameWithoutExtension(value: unknown): string {
  const filename = String(value || '').trim();
  if (!filename) return '—';
  const withoutQuery = filename.split(/[?#]/)[0] || filename;
  const base = withoutQuery.split('/').pop() || withoutQuery;
  return base.replace(/\.[^.]+$/, '') || base;
}

function mediaTypeLabel(value: unknown): string {
  const type = String(value || '').toLowerCase();
  const labels: Record<string, string> = { image: 'Image', video: 'Vidéo', audio: 'Audio', document: 'Document', binary: 'Fichier' };
  return labels[type] || (type ? type : '—');
}

function usageCount(asset: MediaAsset | null = selected.value): number {
  if (!asset) return 0;
  const direct = Number(asset.usage_count ?? 0);
  return Math.max(Number.isFinite(direct) ? direct : 0, usages.value.length);
}

const selectedIsUsed = computed(() => usageCount() > 0);
const deleteDisabledReason = computed(() => selectedIsUsed.value ? 'Ce média est utilisé dans le site. La suppression passera en attente tant que les usages existent.' : '');
const hygieneSummary = computed(() => hygiene.value?.summary ?? {});
const allowedAccept = computed(() => Array.isArray(mediaSettings.value?.allowed_mime_types) && mediaSettings.value!.allowed_mime_types!.length ? mediaSettings.value!.allowed_mime_types!.join(',') : 'image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,audio/mpeg,audio/mp4,audio/ogg,application/pdf');

async function load() {
  loading.value = true; error.value = '';
  try {
    const query: Record<string, string|number> = { limit: 160, lang: lang.value };
    if (typeFilter.value !== 'all') query.type = typeFilter.value;
    if (q.value.trim()) query.q = q.value.trim();
    const [r, settingsResponse] = await Promise.all([
      adminApi.get<unknown>('/media', query),
      mediaSettings.value ? Promise.resolve({ data: mediaSettings.value }) : adminApi.get<MediaSettings>('/media/settings')
    ]);
    rows.value = normalize(r.data);
    mediaSettings.value = settingsResponse.data;
  } catch(e) { error.value = apiErrorMessage(e, 'Médias indisponibles.'); }
  finally { loading.value = false; }
}
function openAsset(asset: Record<string, unknown>) { const url = String(asset.public_url || ''); if (url) window.open(url, '_blank', 'noopener'); }
async function selectAsset(asset: MediaAsset) {
  selected.value = asset;
  variants.value = Array.isArray(asset.variants) ? asset.variants : [];
  usages.value = Array.isArray(asset.usages) ? asset.usages : [];
  localizations.value = [];
  applyEditFromAsset(asset, lang.value, [], true);
  await loadAssetDetail(asset.id);
}
async function loadAssetDetail(id: number) {
  const requestSeq = ++detailRequestSeq;
  const languageCode = lang.value;
  detailLoading.value = true;
  try {
    const r = await adminApi.get<{ asset?: MediaAsset; localizations?: MediaLocalization[]; variants?: MediaVariant[]; usages?: MediaUsage[] }>(`/media/${id}`, { lang: languageCode });
    if (requestSeq !== detailRequestSeq || languageCode !== lang.value) return;
    const asset = r.data.asset;
    const localizationList = Array.isArray(r.data.localizations) ? r.data.localizations : [];
    localizations.value = localizationList;
    variants.value = Array.isArray(r.data.variants) ? r.data.variants : [];
    usages.value = Array.isArray(r.data.usages) ? r.data.usages : [];
    if (asset) {
      const loc = localizationList.find((item) => item.language_code === languageCode);
      selected.value = {
        ...asset,
        alt_text: loc?.alt_text ?? '',
        caption: loc?.caption ?? '',
        title: loc?.title ?? '',
        usage_count: usageCount(asset)
      } as MediaAsset;
      applyEditFromAsset(asset, languageCode, localizationList, false);
    } else {
      edit.value = { ...edit.value, title: '', alt_text: '', caption: '' };
      metadataDirty.value = false;
    }
  } catch (e) {
    if (requestSeq === detailRequestSeq) error.value = apiErrorMessage(e, 'Détail média indisponible.');
  } finally { if (requestSeq === detailRequestSeq) detailLoading.value = false; }
}
function markDirty() { metadataDirty.value = true; }
function formatBytes(value: unknown) {
  const bytes = Number(value || 0);
  if (!Number.isFinite(bytes) || bytes <= 0) return '—';
  if (bytes < 1024) return `${bytes} o`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} Ko`;
  return `${(bytes / 1024 / 1024).toFixed(1)} Mo`;
}
function usageLabel(usage: MediaUsage) {
  const parts = [usage.usage_context || 'usage', usage.field_key || '', usage.language_code || ''].filter(Boolean);
  const title = (usage as any).entry_title || (usage as any).entry_path || (usage as any).entry_key;
  const target = title ? String(title) : (usage.resource_type && usage.resource_id ? `${usage.resource_type} #${usage.resource_id}` : '');
  return `${parts.join(' · ')}${target ? ' — ' + target : ''}`;
}
async function upload(event: Event) {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;
  selectedUploadName.value = file.name;
  uploading.value = true; error.value = '';
  const form = new FormData();
  form.append('file', file);
  form.append('folder_key', uploadFolderKey.value.trim() || mediaSettings.value?.default_folder_key || 'general');
  try { await adminApi.upload('/media', form); await Promise.all([load(), loadHygiene()]); }
  catch(e) { error.value = apiErrorMessage(e, 'Upload impossible.'); }
  finally { uploading.value = false; selectedUploadName.value = ''; if (fileInput.value) fileInput.value.value = ''; }
}
async function saveMetadata() {
  if (!selected.value) return;
  saving.value = true; error.value = '';
  try {
    const languageCode = lang.value;
    await adminApi.patch(`/media/${selected.value.id}`, {
      copyright_text: edit.value.copyright_text,
      license_type: edit.value.license_type,
      source_url: edit.value.source_url,
      localizations: { [languageCode]: { alt_text: edit.value.alt_text, caption: edit.value.caption, title: edit.value.title } }
    });
    metadataDirty.value = false;
    const selectedId = selected.value.id;
    await load();
    await loadAssetDetail(selectedId);
  } catch(e) { error.value = apiErrorMessage(e, 'Enregistrement impossible.'); }
  finally { saving.value = false; }
}
async function deleteSafe(force = false) {
  if (!selected.value || selectedIsUsed.value) return;
  saving.value = true; error.value = '';
  try { await adminApi.delete(`/media/${selected.value.id}`, { force }); selected.value = null; await Promise.all([load(), loadHygiene()]); }
  catch(e) { error.value = apiErrorMessage(e, 'Suppression impossible.'); }
  finally { saving.value = false; }
}
async function refreshAll() {
  await Promise.all([load(), loadHygiene()]);
}

async function loadHygiene() {
  hygieneLoading.value = true;
  try {
    const r = await adminApi.get<MediaHygieneReport>('/media/hygiene', { lang: lang.value, limit: 40 });
    hygiene.value = r.data;
  } catch (e) {
    error.value = apiErrorMessage(e, 'Rapport d’hygiène indisponible.');
  } finally { hygieneLoading.value = false; }
}
watch(lang, async () => {
  const selectedId = selected.value?.id;
  if (selectedId) await loadAssetDetail(selectedId);
  await Promise.all([load(), loadHygiene()]);
});
window.addEventListener('amcms:before-content-language-switch', (event) => {
  if (metadataDirty.value && selected.value && !window.confirm('Les métadonnées du média sélectionné ne sont pas enregistrées. Changer de langue sans enregistrer ?')) {
    event.preventDefault();
  }
});
onMounted(refreshAll);
</script>
<template>
  <PageHeader title="Médias" intro="Médiathèque native : upload, aperçu, métadonnées SEO, alt text, légende, usages, variantes et suppression contrôlée.">
    <template #actions><button class="btn" :disabled="loading || hygieneLoading" @click="refreshAll">Rafraîchir</button></template>
  </PageHeader>
  <div class="toolbar card media-library-toolbar" style="margin-bottom:1rem">
    <input class="input media-library-toolbar__search" v-model="q" placeholder="Rechercher un fichier" @keyup.enter="load" />
    <select class="select media-library-toolbar__type" v-model="typeFilter" @change="load"><option value="all">Tous</option><option value="image">Images</option><option value="video">Vidéos</option><option value="audio">Audios</option><option value="document">Documents</option></select>
    <span class="language-status" :title="`Métadonnées affichées en ${lang.toUpperCase()}. La langue se change avec le menu en haut à droite.`">Métadonnées {{ lang.toUpperCase() }}</span>
    <input class="input media-library-toolbar__folder" v-model="uploadFolderKey" :placeholder="`Dossier upload (${mediaSettings?.default_folder_key || 'general'})`" />
    <label class="checkbox-inline"><input v-model="onlyWithoutAlt" type="checkbox"> Images sans alt</label>
    <label class="file-button" :class="{ 'is-busy': uploading }">
      <input ref="fileInput" class="file-button__input" type="file" :accept="allowedAccept" :disabled="uploading" @change="upload">
      <span class="file-button__icon" aria-hidden="true">＋</span>
      <span>{{ uploading ? 'Téléversement…' : 'Choisir un fichier' }}</span>
    </label>
    <span v-if="selectedUploadName" class="file-button__name">{{ selectedUploadName }}</span>
  </div>
  <ApiFeedback :error="error" />
  <section class="hygiene-grid" aria-label="Hygiène médias">
    <article class="card hygiene-card"><span>Total prêts</span><strong>{{ hygieneSummary.total_ready ?? '—' }}</strong></article>
    <article class="card hygiene-card"><span>Inutilisés</span><strong>{{ hygieneSummary.unused ?? '—' }}</strong></article>
    <article class="card hygiene-card"><span>Images sans alt {{ lang.toUpperCase() }}</span><strong>{{ hygieneSummary.images_without_alt ?? '—' }}</strong></article>
    <article class="card hygiene-card"><span>Variantes en erreur</span><strong>{{ hygieneSummary.variant_failures ?? '—' }}</strong></article>
    <article class="card hygiene-card"><span>Métadonnées incomplètes</span><strong>{{ hygieneSummary.metadata_incomplete ?? '—' }}</strong></article>
    <article class="card hygiene-card"><span>Suppression en attente</span><strong>{{ hygieneSummary.delete_pending ?? '—' }}</strong></article>
  </section>
  <div class="grid grid-2">
    <section>
      <p v-if="loading" class="card muted">Chargement…</p>
      <DataTable v-else :columns="[{key:'preview',label:'Aperçu'},{key:'original_filename',label:'Fichier'},{key:'media_type',label:'Type'},{key:'usage_count',label:'Usages'}]" :rows="filteredRows as unknown as Record<string, unknown>[]">
        <template #cell-preview="{ row }"><button v-if="row.public_url" class="media-thumb" @click="openAsset(row)"><img v-if="String(row.mime_type || '').startsWith('image/')" :src="String(row.thumbnail_url || row.public_url)" alt=""><span v-else>↗</span></button><span v-else class="muted">—</span></template>
        <template #cell-original_filename="{ row }"><button type="button" class="linklike" @click="selectAsset(row as unknown as MediaAsset)">{{ filenameWithoutExtension(row.original_filename || row.filename) }}</button><br><small class="muted">{{ formatBytes(row.size_bytes) }}</small></template>
        <template #cell-media_type="{ row }"><span>{{ mediaTypeLabel(row.media_type) }}</span><br><small class="muted">{{ row.variants_status || '—' }} · {{ row.metadata_status || '—' }}</small></template>
      </DataTable>
    </section>
    <aside class="card" v-if="selected">
      <p class="eyebrow">Métadonnées</p>
      <h2>{{ selected.original_filename || selected.filename }}</h2>
      <img v-if="String(selected.mime_type || '').startsWith('image/')" :src="String(selected.public_url)" alt="" style="max-width:100%;border-radius:.75rem;margin-bottom:1rem">
      <p class="muted">{{ selectedMetaSummary }}</p>
      <p v-if="detailLoading" class="muted">Chargement du détail média…</p>
      <div class="card soft" style="margin:1rem 0">
        <p class="eyebrow">Variantes générées</p>
        <div v-if="variants.length" class="variant-list">
          <a v-for="variant in variants" :key="variant.id || variant.variant_key" class="variant-pill" :href="variant.public_url" target="_blank" rel="noopener">
            <strong>{{ variant.variant_key }}</strong>
            <span>{{ variant.width || '—' }}×{{ variant.height || '—' }}</span>
            <span>{{ variant.format || variant.mime_type || '—' }}</span>
            <span>{{ formatBytes(variant.size_bytes) }}</span>
          </a>
        </div>
        <p v-else class="muted">Aucune variante générée pour ce média.</p>
      </div>
      <div class="card soft" style="margin:1rem 0">
        <p class="eyebrow">Utilisation dans le site</p>
        <ul v-if="usages.length" class="usage-list">
          <li v-for="usage in usages" :key="usage.id">
            <strong>{{ usageLabel(usage) }}</strong>
            <small class="muted">Révision {{ usage.source_revision_id || '—' }} · alt: {{ usage.alt_policy || '—' }}<span v-if="usage.alt_text_snapshot"> · “{{ usage.alt_text_snapshot }}”</span></small>
          </li>
        </ul>
        <p v-else class="muted">Aucun usage enregistré pour ce média.</p>
      </div>
      <p class="language-meta-note">Les champs ci-dessous concernent uniquement la langue de contenu active.</p>
      <p v-if="!localizationFor(lang)" class="language-meta-note is-empty">Aucune métadonnée n’existe encore pour {{ lang.toUpperCase() }}. Les champs sont prêts à être complétés pour cette langue.</p>
      <label class="field">Titre<input class="input" v-model="edit.title" @input="markDirty"></label>
      <label class="field">Texte alternatif<input class="input" v-model="edit.alt_text" @input="markDirty" maxlength="180" placeholder="Décrire l’image si elle porte du sens"></label>
      <label class="field">Légende<textarea class="input" rows="3" v-model="edit.caption" @input="markDirty" maxlength="300"></textarea></label>
      <label class="field">Copyright<input class="input" v-model="edit.copyright_text" @input="markDirty"></label>
      <label class="field">Licence<input class="input" v-model="edit.license_type" @input="markDirty"></label>
      <label class="field">Source URL<input class="input" v-model="edit.source_url" @input="markDirty"></label>
      <p v-if="selectedIsUsed" class="delete-lock-note">Ce média est utilisé : une demande de suppression est marquée en attente côté serveur, sans casser le front.</p>
      <div class="toolbar"><button class="btn primary" :disabled="saving" @click="saveMetadata">Enregistrer {{ lang.toUpperCase() }}</button><button class="btn ghost" :disabled="saving" :title="deleteDisabledReason" @click="deleteSafe(false)">Suppression sûre</button></div>
    </aside>
    <aside class="card muted" v-else>Sélectionnez un média pour éditer son alt text, sa légende, sa source, sa licence et contrôler ses usages.</aside>
  </div>
</template>

<style scoped>
.hygiene-grid { display:grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap:.75rem; margin-bottom:1rem; }
.hygiene-card { padding:.9rem 1rem; display:grid; gap:.25rem; }
.hygiene-card span { color:var(--muted, #64748b); font-size:.82rem; font-weight:700; }
.hygiene-card strong { font-size:1.35rem; }
.media-library-toolbar__folder { max-width:15rem; }
@media (max-width: 980px) { .hygiene-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.language-status { display:inline-flex; align-items:center; min-height:2.45rem; padding:0 .9rem; border:1px solid var(--border, #d9dee8); border-radius:.85rem; background:rgba(248,250,252,.86); color:var(--muted, #64748b); font-size:.9rem; font-weight:700; white-space:nowrap; }
.language-meta-note { margin:.5rem 0 1rem; padding:.75rem .9rem; border:1px solid var(--border, #d9dee8); border-radius:.85rem; background:rgba(248,250,252,.72); color:var(--muted, #64748b); }
.language-meta-note.is-empty { background:#fff7ed; border-color:#fed7aa; color:#9a3412; }
.delete-lock-note { margin:.75rem 0; padding:.75rem .9rem; border:1px solid #fecaca; border-radius:.85rem; background:#fff1f2; color:#991b1b; font-weight:700; }
.variant-list { display:grid; gap:.5rem; }
.variant-pill { display:grid; grid-template-columns: 1.2fr .9fr .7fr .7fr; gap:.5rem; align-items:center; padding:.65rem .75rem; border:1px solid var(--border, #d9dee8); border-radius:.85rem; text-decoration:none; color:inherit; background:rgba(255,255,255,.65); }
.variant-pill:hover { border-color:var(--accent, #4668ff); }
.usage-list { list-style:none; padding:0; margin:0; display:grid; gap:.65rem; }
.usage-list li { padding:.65rem .75rem; border:1px solid var(--border, #d9dee8); border-radius:.85rem; background:rgba(255,255,255,.65); }
.usage-list small { display:block; margin-top:.15rem; }
.card.soft { background:rgba(248,250,252,.7); }
</style>
