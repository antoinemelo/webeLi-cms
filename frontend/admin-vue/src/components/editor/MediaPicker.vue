<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import type { MediaAsset, MediaVariant } from '@/api/contracts';
import InfoHint from '@/components/ui/InfoHint.vue';

const props = withDefaults(defineProps<{
  mediaId?: number;
  src?: string;
  alt?: string;
  caption?: string;
  title?: string;
  acceptType?: 'image'|'video'|'audio'|'document'|'visual'|'any';
  label?: string;
  help?: string;
  required?: boolean;
  preferredSet?: 'content'|'hero'|'open_graph';
  compact?: boolean;
  disabled?: boolean;
}>(), { mediaId: 0, src: '', alt: '', caption: '', title: '', acceptType: 'any', label: 'Média', help: '', required: false, preferredSet: 'content', compact: false, disabled: false });
const emit = defineEmits<{
  'update:mediaId': [number]; 'update:src': [string]; 'update:alt': [string]; 'update:caption': [string]; 'update:title': [string];
  'select': [MediaAsset | null];
}>();

const assets = ref<MediaAsset[]>([]);
const q = ref('');
const loading = ref(false);
const uploading = ref(false);
const error = ref('');
const mode = ref<'library'|'upload'>('library');
const fileInput = ref<HTMLInputElement | null>(null);
const selectedUploadName = ref('');
const failedImages = ref<Record<string, boolean>>({});

const selected = computed(() => {
  const byId = assets.value.find((asset) => Number(asset.id) === Number(props.mediaId));
  if (byId) return byId;
  const currentSrc = normalizeUrl(props.src || '');
  if (!currentSrc) return null;
  return assets.value.find((asset) => [asset.public_url, asset.thumbnail_url, asset.path, variantUrl(asset)].some((url) => normalizeUrl(url || '') === currentSrc)) ?? null;
});
const hasCurrentExternalSource = computed(() => Boolean(props.src) && !selected.value);
const currentPreviewUrl = computed(() => selected.value ? String(selected.value.thumbnail_url || selected.value.public_url || props.src || '') : String(props.src || ''));
const selectedVariantUrl = computed(() => selected.value ? variantUrl(selected.value) : String(props.src || ''));
const currentIsImage = computed(() => selected.value ? String(selected.value.mime_type || '').startsWith('image/') : Boolean(currentPreviewUrl.value));
const selectedKindLabel = computed(() => {
  const type=String(selected.value?.media_type||'').toLowerCase();
  if(type==='video')return 'Vidéo';
  if(type==='document')return 'Document';
  if(type==='audio')return 'Audio';
  return 'Fichier';
});
const filtered = computed(() => assets.value.filter((asset) => {
  const mediaType=String(asset.media_type??'').toLowerCase();const mimeType=String(asset.mime_type??'').toLowerCase();
  const kindOk = props.acceptType === 'any' || (props.acceptType==='visual'?(mediaType==='image'||mediaType==='video'||mimeType.startsWith('image/')||mimeType.startsWith('video/')):mediaType === props.acceptType || mimeType.startsWith(`${props.acceptType}/`));
  const needle = q.value.trim().toLowerCase();
  const haystack = [asset.original_filename, asset.filename, asset.mime_type, asset.alt_text, asset.caption, asset.title, asset.folder_name].filter(Boolean).join(' ').toLowerCase();
  return kindOk && (!needle || haystack.includes(needle));
}));
const acceptAttr = computed(() => props.acceptType === 'visual'
  ? 'image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm'
  : props.acceptType === 'image'
  ? 'image/jpeg,image/png,image/webp,image/gif'
  : props.acceptType === 'video'
    ? 'video/mp4,video/webm'
    : props.acceptType === 'audio'
      ? 'audio/mpeg,audio/mp4,audio/ogg'
      : props.acceptType === 'document'
        ? 'application/pdf'
        : undefined
);

function normalizeUrl(value: string): string { return String(value || '').replace(/^https?:\/\/[^/]+/i, '').replace(/\?.*$/, '').trim(); }
async function load() {
  loading.value = true; error.value = '';
  try {
    const query: Record<string,string|number> = { limit: 160 };
    if (!['any','visual'].includes(props.acceptType)) query.type = props.acceptType;
    const response = await adminApi.get<{ assets?: MediaAsset[] } | MediaAsset[]>('/media', query);
    const data = response.data;
    assets.value = Array.isArray(data) ? data : (data.assets ?? []);
  } catch (e) { error.value = apiErrorMessage(e, 'Médiathèque indisponible.'); }
  finally { loading.value = false; }
}

function variantUrl(asset: MediaAsset): string {
  const set = asset.responsive_sets?.[props.preferredSet];
  const preferred = props.preferredSet === 'hero' ? ['hero_1280','hero_960','hero_640'] : props.preferredSet === 'open_graph' ? ['og_1200x630'] : ['content_1280','content_1024','content_768','content_480'];
  for (const key of preferred) {
    const variant = set?.find((item: MediaVariant) => item.variant_key === key);
    if (variant?.public_url) return String(variant.public_url);
  }
  return String(asset.public_url || asset.path || asset.thumbnail_url || '');
}

function dimensions(asset: MediaAsset): string {
  return asset.width && asset.height ? `${asset.width}×${asset.height}` : 'dimensions inconnues';
}
function fileLabel(asset: MediaAsset): string {
  return String(asset.original_filename || asset.filename || `média #${asset.id}`);
}
function imageFailed(url: string): boolean { return Boolean(url && failedImages.value[url]); }
function markImageFailed(url: string) { if (url) failedImages.value = { ...failedImages.value, [url]: true }; }
function variantLabel(asset: MediaAsset): string {
  const url = variantUrl(asset);
  const variant = (asset.variants || []).find((item) => item.public_url === url || item.path === url);
  return variant?.variant_key ? String(variant.variant_key) : props.preferredSet;
}
function choose(asset: MediaAsset) {
  emit('update:mediaId', Number(asset.id));
  emit('update:src', variantUrl(asset));
  if (props.acceptType === 'image' || String(asset.mime_type || '').startsWith('image/')) {
    emit('update:alt', String(asset.alt_text || ''));
  } else {
    emit('update:title', String(asset.title || asset.original_filename || asset.filename || ''));
  }
  emit('update:caption', String(asset.caption || ''));
  emit('select', asset);
}

function clearSelection() {
  emit('update:mediaId', 0);
  emit('update:src', '');
  emit('update:alt', '');
  emit('update:caption', '');
  emit('update:title', '');
  emit('select', null);
}

async function upload(event: Event) {
  const input = event.target as HTMLInputElement;
  const file = input.files?.[0];
  if (!file) return;
  selectedUploadName.value = file.name;
  uploading.value = true; error.value = '';
  const form = new FormData();
  form.append('file', file);
  form.append('folder_key', new Date().toISOString().slice(0, 7).replace('-', '/'));
  try {
    const response = await adminApi.upload<{ media_id?: number }>('/media', form);
    await load();
    const mediaId = Number((response.data as any).media_id || 0);
    const asset = assets.value.find((item) => Number(item.id) === mediaId);
    if (asset) choose(asset);
    mode.value = 'library';
  } catch (e) { error.value = apiErrorMessage(e, 'Upload impossible.'); }
  finally { uploading.value = false; selectedUploadName.value = ''; if (fileInput.value) fileInput.value.value = ''; }
}

watch(() => props.acceptType, load);
onMounted(load);
</script>

<template>
  <div class="media-picker" :class="{ 'is-required': required, 'media-picker--compact': compact }">
    <div class="media-picker__head">
      <div>
        <strong class="schema-label-with-hint"><span>{{ label }}<span v-if="required" aria-hidden="true"> *</span></span><InfoHint v-if="help" :text="help" placement="end" /></strong>
      </div>
      <div class="media-picker__actions">
        <button type="button" class="chip" :disabled="loading || disabled" @click="load">Rafraîchir</button>
        <button v-if="mediaId || src" type="button" class="chip chip--danger" :disabled="disabled" @click="clearSelection">Retirer</button>
      </div>
    </div>

    <div v-if="selected || hasCurrentExternalSource" class="media-picker__selected media-picker__selected--large">
      <div class="media-picker__selected-preview">
        <img v-if="currentPreviewUrl && currentIsImage && !imageFailed(currentPreviewUrl)" :src="currentPreviewUrl" :alt="selected?.alt_text || ''" @error="markImageFailed(currentPreviewUrl)">
        <span v-else class="media-picker__placeholder" aria-hidden="true">{{ selectedKindLabel }}</span>
      </div>
      <div class="media-picker__selected-meta">
        <strong>{{ selected ? fileLabel(selected) : 'Média déjà renseigné' }}</strong>
        <small v-if="selected">{{ selected.mime_type }} · {{ dimensions(selected) }} · variante {{ variantLabel(selected) }}</small>
        <small v-if="selected?.alt_text">Alt : {{ selected.alt_text }}</small>
        <small class="media-picker__url">URL enregistrée : {{ selectedVariantUrl }}</small>
      </div>
    </div>
    <p v-else-if="required" class="muted">Aucun média sélectionné.</p>

    <div class="media-picker__tabs" role="tablist" aria-label="Source du média">
      <button type="button" class="chip" :class="{ active: mode === 'library' }" :disabled="disabled" @click="mode = 'library'">Médiathèque</button>
      <button type="button" class="chip" :class="{ active: mode === 'upload' }" :disabled="disabled" @click="mode = 'upload'">Upload</button>
    </div>

    <div v-if="mode === 'library'" class="media-picker__tools">
      <input class="input" v-model="q" :disabled="disabled" placeholder="Rechercher par nom, alt, légende ou dossier">
      <span class="muted small">{{ filtered.length }} média{{ filtered.length > 1 ? 's' : '' }}</span>
    </div>
    <div v-else class="media-picker__upload">
      <label class="file-button file-button--wide" :class="{ 'is-busy': uploading }">
        <input ref="fileInput" class="file-button__input" type="file" :accept="acceptAttr" :disabled="uploading || disabled" @change="upload">
        <span class="file-button__icon" aria-hidden="true">＋</span>
        <span>{{ uploading ? 'Téléversement…' : 'Choisir un fichier' }}</span>
      </label>
      <p v-if="selectedUploadName" class="file-button__name">{{ selectedUploadName }}</p>
      <p class="muted">Le fichier uploadé est ajouté à la médiathèque, ses métadonnées restent éditables dans l’écran Médias.</p>
    </div>

    <p v-if="error" class="alert error">{{ error }}</p>
    <p v-if="loading || uploading" class="muted">{{ uploading ? 'Upload…' : 'Chargement…' }}</p>
    <div v-if="mode === 'library'" class="media-picker__grid" role="listbox" :aria-label="`Choisir ${label}`">
      <button v-for="asset in filtered.slice(0, compact ? 18 : 60)" :key="asset.id" type="button" class="media-picker__item" :class="Number(asset.id) === Number(mediaId) ? 'active' : ''" :disabled="disabled" @click="choose(asset)">
        <span class="media-picker__thumb-wrap">
          <img v-if="String(asset.mime_type || '').startsWith('image/') && !imageFailed(String(asset.thumbnail_url || asset.public_url))" :src="String(asset.thumbnail_url || asset.public_url)" :alt="asset.alt_text || ''" loading="lazy" @error="markImageFailed(String(asset.thumbnail_url || asset.public_url))">
          <span v-else class="media-picker__placeholder">{{ asset.media_type || asset.mime_type || 'fichier' }}</span>
        </span>
        <small class="media-picker__name" :title="fileLabel(asset)">{{ fileLabel(asset) }}</small>
        <small class="media-picker__meta">{{ dimensions(asset) }}</small>
        <small v-if="asset.folder_name" class="media-picker__meta">{{ asset.folder_name }}</small>
      </button>
    </div>
  </div>
</template>
