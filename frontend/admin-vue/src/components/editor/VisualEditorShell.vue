<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import MediaPicker from '@/components/editor/MediaPicker.vue';
import InfoHint from '@/components/ui/InfoHint.vue';
import type { EditorialBlock, EntryShow, MediaAsset, MediaVariant } from '@/api/contracts';

type VisualField = {
  key: string;
  kind: 'entry' | 'seo' | 'field' | 'block';
  block_id?: string | null;
  block_type?: string;
  block_index?: number | null;
  field_path: string;
  label: string;
  editor: string;
  value: unknown;
  editable: boolean;
};
type VisualBlock = { id: string; type: string; label: string; enabled: boolean; editorial_status: string; visual_status?: string; is_modified_since_publish?: boolean; block_index?: number | null };
type VisualBlockLock = { block_id: string; language_code: string; locked_by_iam_user_id: number; locked_by_label: string; is_current_user: boolean; lock_token: string; expires_at: string; ttl_seconds?: number };
type TranslationItem = { language_code: string; label: string; status: 'complete' | 'partial' | 'missing' | string; missing: string[]; has_draft: boolean; working_revision_id: number; published_revision_id: number; completed_at?: string | null };
type SelectionAnchor = Pick<VisualField, 'kind' | 'field_path'> & { key?: string; block_id?: string | null; block_type?: string; block_index?: number | null; semantic_key?: string };
type ButtonDraft = { label: string; url: string; style: string; target: '_self' | '_blank' | string };

const props = defineProps<{
  entryId: string | number;
  siteId?: number;
  languageCode: string;
  workingRevisionId?: number | null;
  entry: EntryShow | null;
  blocks: EditorialBlock[];
  viewport?: 'desktop' | 'tablet' | 'mobile';
}>();
const emit = defineEmits<{
  'field-saved': [payload: { working_revision_id: number }];
  'open-structure': [payload: { blockId?: string | null }];
  'navigate-entry': [payload: { entryId: number; contentTypeKey: string }];
  'update:viewport': [value: 'desktop' | 'tablet' | 'mobile'];
  feedback: [payload: { kind: 'success' | 'error'; message: string }];
}>();

const iframe = ref<HTMLIFrameElement | null>(null);
const map = ref<{ fields: VisualField[]; blocks?: VisualBlock[]; working_revision_id?: number } | null>(null);
const translations = ref<TranslationItem[]>([]);
const selected = ref<VisualField | null>(null);
const selectionAnchor = ref<SelectionAnchor | null>(null);
const previewUrl = ref('');
const blockLocks = ref<VisualBlockLock[]>([]);
const activeLockTokenByBlock = ref<Record<string, string>>({});
const activeLockBlockId = ref('');
let heartbeatTimer: number | undefined;
const loading = ref(false);
const saving = ref(false);
const navigating = ref(false);
const error = ref('');
const message = ref('');
const draftValue = ref('');
const jsonValue = ref('');
const selectedVisualMediaPayload = ref<Record<string, unknown> | null>(null);
const buttonDrafts = ref<ButtonDraft[]>([]);
const selectedButtonIndex = ref(0);
const frameHeight = ref(720);
const previewChromeColor = ref('rgba(15, 23, 42, .04)');
const VISUAL_SELECTION_STORAGE_PREFIX = 'amcms.visual.selectedField.v1';

const statusOptions = [
  { value: 'draft', label: 'Brouillon' },
  { value: 'review', label: 'Relecture' },
  { value: 'ready', label: 'Prêt à publier' },
  { value: 'published', label: 'Publié' },
];
const buttonStyleOptions = [
  { value: 'primary', label: 'Primaire' },
  { value: 'secondary', label: 'Secondaire' },
  { value: 'ghost', label: 'Discret' },
  { value: 'outline', label: 'Contour' },
  { value: 'link', label: 'Lien' },
];
const buttonTargetOptions = [
  { value: '_self', label: 'Même fenêtre' },
  { value: '_blank', label: 'Nouvel onglet' },
];

const viewportMode = computed(() => props.viewport || 'desktop');
const frameWidth = computed(() => viewportMode.value === 'mobile' ? '390px' : viewportMode.value === 'tablet' ? '768px' : '100%');
const frameHeightCss = computed(() => `${Math.max(320, Math.min(frameHeight.value, 12000))}px`);
const fields = computed(() => map.value?.fields ?? []);
const mapBlocks = computed(() => map.value?.blocks ?? []);
const blockFields = computed(() => selected.value?.block_id ? fields.value.filter((field) => field.block_id === selected.value?.block_id && field.field_path !== 'enabled') : []);
const selectedMapBlock = computed(() => mapBlocks.value.find((block) => block.id === selected.value?.block_id) ?? null);
const selectedFullBlock = computed(() => props.blocks.find((block) => block.id === selected.value?.block_id) ?? null);
const selectedBlock = computed(() => selectedMapBlock.value ?? selectedFullBlock.value ?? null);
const selectedBlockStatusField = computed(() => selected.value?.block_id ? fields.value.find((field) => field.block_id === selected.value?.block_id && field.field_path === 'editorial_status') ?? null : null);
const selectedBlockLock = computed(() => selected.value?.block_id ? blockLocks.value.find((lock) => lock.block_id === selected.value?.block_id) ?? null : null);
const selectedBlockLockedByOther = computed(() => Boolean(selectedBlockLock.value && !selectedBlockLock.value.is_current_user));
const selectedBlockLockedByMe = computed(() => Boolean(selectedBlockLock.value?.is_current_user));
const selectedLooksLikeImageField = computed(() => {
  const field = selected.value;
  if (!field || !['inline_text', 'media_url'].includes(field.editor)) return false;
  const path = String(field.field_path || '').toLowerCase();
  const label = String(field.label || '').toLowerCase();
  return /(^|[._-])(image|img|photo|picture|visual|hero)([._-]|$)/.test(path)
    || /(image|visuel|photo|illustration)/.test(label)
    || ['src', 'url', 'image_src', 'image_url'].some((part) => path.endsWith(part));
});
function normaliseButtonDraft(value: unknown): ButtonDraft {
  const item = value && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : {};
  const target = String(item.target || '_self');
  return {
    label: String(item.label || ''),
    url: String(item.url || ''),
    style: String(item.style || 'primary'),
    target: target === '_blank' ? '_blank' : '_self',
  };
}
function normaliseButtonDrafts(value: unknown): ButtonDraft[] {
  if (!Array.isArray(value)) return [];
  return value.map(normaliseButtonDraft);
}
function syncButtonJson() {
  jsonValue.value = JSON.stringify(buttonDrafts.value.map((button) => ({
    label: String(button.label || '').trim(),
    url: String(button.url || '').trim(),
    style: String(button.style || 'primary').trim() || 'primary',
    target: button.target === '_blank' ? '_blank' : '_self',
  })), null, 2);
}
function selectButtonDraft(index: number) {
  if (!buttonDrafts.value.length) { selectedButtonIndex.value = 0; return; }
  selectedButtonIndex.value = Math.max(0, Math.min(index, buttonDrafts.value.length - 1));
}
function applyButtonJson() {
  try {
    buttonDrafts.value = normaliseButtonDrafts(JSON.parse(jsonValue.value || '[]'));
    selectButtonDraft(selectedButtonIndex.value);
    error.value = '';
  } catch {
    error.value = 'JSON invalide pour les boutons.';
  }
}
function addButtonDraft() {
  buttonDrafts.value = [...buttonDrafts.value, { label: 'Bouton', url: '/', style: 'primary', target: '_self' }];
  selectedButtonIndex.value = buttonDrafts.value.length - 1;
  syncButtonJson();
}
function removeButtonDraft(index: number) {
  buttonDrafts.value = buttonDrafts.value.filter((_, itemIndex) => itemIndex !== index);
  selectButtonDraft(Math.min(selectedButtonIndex.value, buttonDrafts.value.length - 1));
  syncButtonJson();
}

function valueAtPath(source: unknown, path: string): unknown {
  let cursor: any = source;
  for (const segment of path.split('.').filter(Boolean)) {
    if (!cursor || typeof cursor !== 'object') return null;
    cursor = cursor[segment];
  }
  return cursor;
}
function visualPeerPath(kind: 'media'|'alt'|'caption'|'title'): string {
  const path = String(selected.value?.field_path || '');
  const suffix = kind === 'media' ? 'media_id' : kind;
  if (path.endsWith('_src')) return path.slice(0, -'_src'.length) + `_${suffix}`;
  if (path.endsWith('.src')) return path.slice(0, -'.src'.length) + `.${suffix === 'media_id' ? 'media_id' : suffix}`;
  if (path.endsWith('_url')) return path.slice(0, -'_url'.length) + `_${suffix}`;
  return path.replace(/[^.]+$/, suffix === 'media_id' ? 'media_id' : suffix);
}
function visualMediaId(): number {
  const direct = Number(selectedVisualMediaPayload.value?.media_id || 0);
  if (direct > 0) return direct;
  const fromPeer = Number(valueAtPath(selectedFullBlock.value, visualPeerPath('media')) || 0);
  if (fromPeer > 0) return fromPeer;
  const field = selected.value;
  if (!field || typeof field.value !== 'object' || Array.isArray(field.value) || field.value === null) return 0;
  return Number((field.value as Record<string, unknown>).media_id || (field.value as Record<string, unknown>).id || 0);
}
function visualPreferredSet(): 'content'|'hero'|'open_graph' {
  const path = String(selected.value?.field_path || '').toLowerCase();
  return path.includes('hero') ? 'hero' : path.includes('og') || path.includes('social') ? 'open_graph' : 'content';
}
function visualMediaUrl(asset: MediaAsset): string {
  const setName = visualPreferredSet();
  const preferred = setName === 'hero' ? ['hero_1280','hero_960','hero_640'] : setName === 'open_graph' ? ['og_1200x630'] : ['content_1280','content_1024','content_768','content_480'];
  const set = asset.responsive_sets?.[setName];
  for (const key of preferred) {
    const variant = set?.find((item: MediaVariant) => item.variant_key === key && item.public_url);
    if (variant?.public_url) return String(variant.public_url);
  }
  return String(asset.public_url || asset.thumbnail_url || '');
}
function setVisualMedia(asset: MediaAsset | null) {
  if (!asset) {
    draftValue.value = '';
    selectedVisualMediaPayload.value = { __media_selection: true, media_id: 0, src: '', alt: '', caption: '', title: '' };
    return;
  }
  const src = visualMediaUrl(asset);
  draftValue.value = src;
  selectedVisualMediaPayload.value = {
    __media_selection: true,
    media_id: Number(asset.id),
    src,
    alt: String(asset.alt_text || valueAtPath(selectedFullBlock.value, visualPeerPath('alt')) || ''),
    caption: String(asset.caption || valueAtPath(selectedFullBlock.value, visualPeerPath('caption')) || ''),
    title: String(asset.title || asset.original_filename || asset.filename || valueAtPath(selectedFullBlock.value, visualPeerPath('title')) || ''),
  };
}

function notifySuccess(_text: string) {
  error.value = '';
  message.value = '';
}
function notifyError(text: string) {
  message.value = '';
  error.value = text;
  emit('feedback', { kind: 'error', message: text });
}
function clearLocalFeedback() {
  error.value = '';
  message.value = '';
}

function selectedRevisionId(): number {
  return Number(map.value?.working_revision_id || props.workingRevisionId || props.entry?.working_revision?.id || 0);
}
function makeAnchor(field: VisualField): SelectionAnchor {
  const blockIndex = Number.isFinite(Number(field.block_index)) ? Number(field.block_index) : null;
  return {
    key: field.key,
    kind: field.kind,
    block_id: field.block_id ?? null,
    block_type: field.block_type || '',
    block_index: blockIndex,
    field_path: field.field_path,
    semantic_key: [field.kind, field.block_type || '', blockIndex ?? '', field.field_path].join(':'),
  };
}
function selectionStorageKey(): string {
  return `${VISUAL_SELECTION_STORAGE_PREFIX}:${props.siteId || 0}:${props.entryId || 'new'}`;
}
function rememberSelection(anchor: SelectionAnchor | null = selectionAnchor.value) {
  if (!anchor) return;
  try {
    window.sessionStorage.setItem(selectionStorageKey(), JSON.stringify(anchor));
  } catch {
    // Selection persistence is an editor comfort feature; ignore private-mode storage failures.
  }
}
function forgetSelection() {
  selectionAnchor.value = null;
  try {
    window.sessionStorage.removeItem(selectionStorageKey());
  } catch {
    // Selection persistence is an editor comfort feature; ignore private-mode storage failures.
  }
}
function storedSelectionAnchor(): SelectionAnchor | null {
  try {
    const raw = window.sessionStorage.getItem(selectionStorageKey());
    if (!raw) return null;
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? parsed as SelectionAnchor : null;
  } catch {
    return null;
  }
}
function preferredSelectionAnchor(explicit?: SelectionAnchor | null): SelectionAnchor | null {
  return explicit || selectionAnchor.value || (selected.value ? makeAnchor(selected.value) : null) || storedSelectionAnchor();
}
function fieldByPayload(payload: any): VisualField | null {
  const blockId = String(payload?.blockId || '');
  const blockType = String(payload?.blockType || '');
  const blockIndex = Number.isFinite(Number(payload?.blockIndex)) ? Number(payload.blockIndex) : null;
  const fieldPath = String(payload?.fieldPath || '');
  if (blockId && fieldPath) {
    const exact = fields.value.find((field) => field.block_id === blockId && field.field_path === fieldPath);
    if (exact) return exact;
    const parentList = fieldPath.replace(/\.\d+(\.|$).*/, '');
    if (parentList && parentList !== fieldPath) {
      const parent = fields.value.find((field) => field.block_id === blockId && field.field_path === parentList);
      if (parent) return parent;
    }
    return null;
  }
  if (blockId) return fields.value.find((field) => field.block_id === blockId && field.field_path !== 'enabled' && field.field_path !== 'editorial_status') ?? fields.value.find((field) => field.block_id === blockId) ?? null;
  if (blockIndex !== null) return fields.value.find((field) => Number(field.block_index) === blockIndex && field.block_type === blockType && (!fieldPath || field.field_path === fieldPath)) ?? null;
  return null;
}
function fieldByAnchor(anchor: SelectionAnchor | null): VisualField | null {
  if (!anchor) return null;
  if (anchor.key) {
    const exact = fields.value.find((field) => field.key === anchor.key);
    if (exact) return exact;
  }
  const semanticKey = String(anchor.semantic_key || '');
  if (semanticKey) {
    const sameSemanticKey = fields.value.find((field) => makeAnchor(field).semantic_key === semanticKey);
    if (sameSemanticKey) return sameSemanticKey;
  }
  if (anchor.kind !== 'block') {
    return fields.value.find((field) => field.kind === anchor.kind && field.field_path === anchor.field_path) ?? null;
  }
  const blockId = String(anchor.block_id || '');
  if (blockId) {
    const sameBlock = fields.value.find((field) => field.block_id === blockId && field.field_path === anchor.field_path);
    if (sameBlock) return sameBlock;
  }
  const blockIndex = Number.isFinite(Number(anchor.block_index)) ? Number(anchor.block_index) : null;
  if (blockIndex !== null) {
    const sameSemanticPosition = fields.value.find((field) => Number(field.block_index) === blockIndex && field.block_type === (anchor.block_type || '') && field.field_path === anchor.field_path);
    if (sameSemanticPosition) return sameSemanticPosition;
    return fields.value.find((field) => Number(field.block_index) === blockIndex && field.field_path === anchor.field_path) ?? null;
  }
  if (anchor.block_type) {
    return fields.value.find((field) => field.block_type === anchor.block_type && field.field_path === anchor.field_path) ?? null;
  }
  return null;
}
function selectField(field: VisualField | null, remember = true, options: { buttonIndex?: number } = {}) {
  selected.value = field;
  if (field) {
    selectionAnchor.value = makeAnchor(field);
    if (remember) rememberSelection(selectionAnchor.value);
  }
  draftValue.value = field?.value === null || field?.value === undefined ? '' : String(field.value);
  jsonValue.value = JSON.stringify(field?.value ?? null, null, 2);
  selectedVisualMediaPayload.value = null;
  buttonDrafts.value = field?.editor === 'button_list' ? normaliseButtonDrafts(field.value) : [];
  selectedButtonIndex.value = field?.editor === 'button_list' ? Math.max(0, Math.min(Number(options.buttonIndex ?? 0), Math.max(0, buttonDrafts.value.length - 1))) : 0;
  if (field?.editor === 'button_list') syncButtonJson();
  highlightSelectedBlock();
  void ensureSelectedBlockLock();
}
function restoreSelection(anchor: SelectionAnchor | null) {
  const restored = fieldByAnchor(anchor);
  if (restored) {
    selectField(restored, false);
    selectionAnchor.value = makeAnchor(restored);
    rememberSelection(selectionAnchor.value);
    return;
  }
  if (!selected.value) {
    selectField(null, false);
  } else {
    highlightSelectedBlock();
  }
}

function mergeLock(lock: VisualBlockLock) {
  blockLocks.value = [...blockLocks.value.filter((item) => item.block_id !== lock.block_id), lock];
  if (lock.is_current_user && lock.lock_token) {
    activeLockTokenByBlock.value = { ...activeLockTokenByBlock.value, [lock.block_id]: lock.lock_token };
  }
}
function removeLock(blockId: string) {
  blockLocks.value = blockLocks.value.filter((item) => item.block_id !== blockId);
  const next = { ...activeLockTokenByBlock.value };
  delete next[blockId];
  activeLockTokenByBlock.value = next;
}
async function fetchBlockLocks() {
  if (!props.entryId) return;
  try {
    const response = await adminApi.get<any>(`/visual/entries/${props.entryId}/block-locks`, { site_id: props.siteId, language_code: props.languageCode });
    blockLocks.value = Array.isArray(response.data.locks) ? response.data.locks : [];
    const tokens: Record<string, string> = {};
    for (const lock of blockLocks.value) {
      if (lock.is_current_user && lock.lock_token) tokens[lock.block_id] = lock.lock_token;
    }
    activeLockTokenByBlock.value = { ...activeLockTokenByBlock.value, ...tokens };
  } catch {
    // Locks are collaborative safeguards; visual editing remains readable if polling fails.
  }
}
async function acquireBlockLock(blockId: string, heartbeat = false) {
  if (!blockId || !props.entryId) return;
  try {
    const response = await adminApi.post<any>(`/visual/entries/${props.entryId}/block-lock`, {
      site_id: props.siteId,
      language_code: props.languageCode,
      block_id: blockId,
      lock_token: activeLockTokenByBlock.value[blockId] || undefined,
    });
    if (response.data?.lock) mergeLock(response.data.lock as VisualBlockLock);
    if (!heartbeat) error.value = '';
  } catch (err: any) {
    const lock = err?.details?.lock as VisualBlockLock | undefined;
    if (lock?.block_id) mergeLock(lock);
    if (!heartbeat) error.value = apiErrorMessage(err, 'Ce bloc est déjà verrouillé par un autre utilisateur.');
  }
}
async function releaseBlockLock(blockId: string) {
  if (!blockId || !props.entryId) return;
  const token = activeLockTokenByBlock.value[blockId] || '';
  if (!token) return;
  try {
    await adminApi.delete<any>(`/visual/entries/${props.entryId}/block-lock`, {
      site_id: props.siteId,
      language_code: props.languageCode,
      block_id: blockId,
      lock_token: token,
    });
  } catch {
    // Releasing on navigation/unmount must never block the editor.
  } finally {
    removeLock(blockId);
  }
}
async function ensureSelectedBlockLock() {
  const blockId = selected.value?.block_id || '';
  if (activeLockBlockId.value && activeLockBlockId.value !== blockId) {
    void releaseBlockLock(activeLockBlockId.value);
  }
  activeLockBlockId.value = blockId;
  if (blockId) await acquireBlockLock(blockId);
}
function startLockHeartbeat() {
  if (heartbeatTimer) window.clearInterval(heartbeatTimer);
  heartbeatTimer = window.setInterval(() => {
    if (activeLockBlockId.value) void acquireBlockLock(activeLockBlockId.value, true);
    void fetchBlockLocks();
  }, 25000);
}
async function releaseActiveLock() {
  if (activeLockBlockId.value) await releaseBlockLock(activeLockBlockId.value);
}
async function unlockSelectedBlock() {
  const blockId = selected.value?.block_id || '';
  if (!blockId || saving.value) return;
  await releaseBlockLock(blockId);
  if (activeLockBlockId.value === blockId) activeLockBlockId.value = '';
  selectField(null, false);
  forgetSelection();
  notifySuccess('Verrou libéré sur ce bloc. L’édition du champ a été fermée.');
}

function visualPreviewStatusLabel(status: string | undefined): string {
  const normalized = String(status || '').trim();
  if (normalized === 'draft') return 'BLOC :: BROUILLON';
  if (normalized === 'review') return 'BLOC :: EN RELECTURE';
  if (normalized === 'ready') return 'BLOC :: PRÊT À PUBLIER';
  if (normalized === 'archived') return 'BLOC :: ARCHIVÉ';
  return '';
}
function statusSyncPayload() {
  return mapBlocks.value
    .filter((block) => block.id)
    .map((block) => {
      const status = block.visual_status || block.editorial_status || 'published';
      return {
        id: block.id,
        status,
        editorial_status: block.editorial_status || 'published',
        label: visualPreviewStatusLabel(status),
      };
    });
}
function syncBlockStatuses() {
  iframe.value?.contentWindow?.postMessage({ type: 'amcms:visual-sync-block-statuses', payload: { blocks: statusSyncPayload() } }, window.location.origin);
}
function highlightSelectedBlock() {
  if (!selected.value?.block_id) return;
  syncBlockStatuses();
  iframe.value?.contentWindow?.postMessage({ type: 'amcms:visual-highlight-block', payload: { blockId: selected.value.block_id } }, window.location.origin);
}
function requestFrameSize() {
  iframe.value?.contentWindow?.postMessage({ type: 'amcms:visual-request-size' }, window.location.origin);
}
async function resolveVisualNavigation(url: string) {
  if (!url || navigating.value) return;
  navigating.value = true;
  clearLocalFeedback();
  try {
    const response = await adminApi.get<any>('/visual/resolve', { site_id: props.siteId, language_code: props.languageCode, url });
    const entryId = Number(response.data.entry_id || 0);
    const contentTypeKey = String(response.data.content_type_key || 'page');
    if (entryId > 0 && String(entryId) !== String(props.entryId)) {
      selectionAnchor.value = null;
      selected.value = null;
      emit('navigate-entry', { entryId, contentTypeKey });
    } else {
      notifySuccess('Vous êtes déjà sur ce contenu en prévisualisation visuelle.');
    }
  } catch (err) {
    notifyError(apiErrorMessage(err, 'Ce lien interne ne peut pas être ouvert dans l’éditeur visuel.'));
  } finally {
    navigating.value = false;
  }
}
function buttonIndexFromPayload(payload: any): number | undefined {
  const direct = Number(payload?.buttonIndex);
  if (Number.isFinite(direct) && direct >= 0) return direct;
  const match = String(payload?.fieldPath || '').match(/\.(\d+)(?:\.|$)/);
  if (!match) return undefined;
  const parsed = Number(match[1]);
  return Number.isFinite(parsed) && parsed >= 0 ? parsed : undefined;
}
function handleMessage(event: MessageEvent) {
  if (event.origin !== window.location.origin) return;
  const data = event.data || {};
  if (data.type === 'amcms:visual-field-selected') {
    const field = fieldByPayload(data.payload);
    if (field) selectField(field, true, { buttonIndex: buttonIndexFromPayload(data.payload) });
    return;
  }
  if (data.type === 'amcms:visual-frame-resize') {
    const height = Number(data.payload?.height || 0);
    if (Number.isFinite(height) && height > 0) frameHeight.value = Math.ceil(height);
    const chromeColor = String(data.payload?.chromeColor || '').trim();
    if (chromeColor && chromeColor !== 'transparent' && chromeColor !== 'rgba(0, 0, 0, 0)') previewChromeColor.value = chromeColor;
    return;
  }
  if (data.type === 'amcms:visual-navigate') {
    void resolveVisualNavigation(String(data.payload?.url || data.payload?.path || ''));
  }
}
async function loadVisual(anchorOverride: SelectionAnchor | null = null, revisionIdOverride: number | null = null, preserveMessage = false) {
  if (!props.entryId) return;
  loading.value = true;
  error.value = '';
  if (!preserveMessage) message.value = '';
  const anchor = preferredSelectionAnchor(anchorOverride);
  if (anchor) selectionAnchor.value = anchor;
  try {
    const [mapResponse, previewResponse, translationResponse, locksResponse] = await Promise.all([
      adminApi.get<any>(`/visual/entries/${props.entryId}/map`, { site_id: props.siteId, language_code: props.languageCode }),
      adminApi.get<any>(`/visual/entries/${props.entryId}/preview`, { site_id: props.siteId, language_code: props.languageCode, revision_id: revisionIdOverride || props.workingRevisionId || undefined }),
      adminApi.get<any>(`/visual/entries/${props.entryId}/translation-status`, { site_id: props.siteId, language_code: props.languageCode }),
      adminApi.get<any>(`/visual/entries/${props.entryId}/block-locks`, { site_id: props.siteId, language_code: props.languageCode }),
    ]);
    map.value = mapResponse.data;
    previewUrl.value = withBasePath(String(previewResponse.data.preview_url || ''));
    translations.value = Array.isArray(translationResponse.data.languages) ? translationResponse.data.languages : [];
    blockLocks.value = Array.isArray(locksResponse.data.locks) ? locksResponse.data.locks : [];
    restoreSelection(anchor);
    await nextTick();
    syncBlockStatuses();
    requestFrameSize();
    highlightSelectedBlock();
  } catch (err) {
    notifyError(apiErrorMessage(err, 'Éditeur visuel indisponible.'));
  } finally {
    loading.value = false;
  }
}
function translationStatusText(item: TranslationItem): string {
  if (item.status === 'complete') {
    const raw = String(item.completed_at || '').trim();
    if (!raw) return 'Complété';
    const normalized = raw.includes('T') ? raw : raw.replace(' ', 'T') + 'Z';
    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) return 'Complété';
    const formattedDate = new Intl.DateTimeFormat('fr-CH', { day: '2-digit', month: '2-digit', year: 'numeric' }).format(date);
    const formattedTime = new Intl.DateTimeFormat('fr-CH', { hour: '2-digit', minute: '2-digit', hour12: false }).format(date);
    return `Complété le ${formattedDate} à ${formattedTime}`;
  }
  if (item.status === 'missing') return 'Absent';
  return 'Partiel';
}
function withBasePath(path: string): string {
  if (!path || /^https?:\/\//i.test(path)) return path;
  const base = window.__AMCMS_ADMIN__?.basePath || '';
  const normalizedBase = base === '/' ? '' : `/${String(base).replace(/^\/+|\/+$/g, '')}`;
  const normalizedPath = path.startsWith('/') ? path : `/${path}`;
  if (normalizedBase && (normalizedPath === normalizedBase || normalizedPath.startsWith(`${normalizedBase}/`))) return normalizedPath;
  return `${normalizedBase}${normalizedPath}` || '/';
}
function visualChangeNote(field: VisualField): string {
  const label = String(field.label || field.field_path || 'champ').trim();
  if (field.kind === 'entry') return `Identité éditoriale mise à jour depuis le visuel (${label}).`;
  if (field.kind === 'seo') return `SEO mis à jour depuis le visuel (${label}).`;
  if (field.kind === 'field') return `Champ personnalisé mis à jour depuis le visuel (${label}).`;
  const blockLabel = String(selectedBlock.value?.label || field.block_type || 'bloc').trim();
  return `Bloc « ${blockLabel} » mis à jour depuis le visuel (${label}).`;
}

function inputValue(): unknown {
  const field = selected.value;
  if (!field) return '';
  if (field.editor === 'button_list') return buttonDrafts.value.map((button) => ({
    label: String(button.label || '').trim(),
    url: String(button.url || '').trim(),
    style: String(button.style || 'primary').trim() || 'primary',
    target: button.target === '_blank' ? '_blank' : '_self',
  }));
  if (['json', 'gallery_items'].includes(field.editor)) {
    try { return JSON.parse(jsonValue.value || 'null'); }
    catch { throw new Error('JSON invalide pour ce champ.'); }
  }
  if (selectedLooksLikeImageField.value && selectedVisualMediaPayload.value) return { ...selectedVisualMediaPayload.value, src: String(draftValue.value || selectedVisualMediaPayload.value.src || '') };
  if (field.editor === 'number') return Number(draftValue.value || 0);
  if (field.editor === 'boolean') return draftValue.value === 'true' || draftValue.value === '1';
  if (field.editor === 'editorial_status') return statusOptions.some((option) => option.value === draftValue.value) ? draftValue.value : 'draft';
  return String(draftValue.value ?? '');
}
async function saveSelected() {
  if (!selected.value || selectedBlockLockedByOther.value) return;
  const savedAnchor = makeAnchor(selected.value);
  selectionAnchor.value = savedAnchor;
  rememberSelection(savedAnchor);
  saving.value = true;
  clearLocalFeedback();
  try {
    const response = await adminApi.post<any>(`/visual/entries/${props.entryId}/field`, {
      site_id: props.siteId,
      language_code: props.languageCode,
      target: {
        kind: selected.value.kind,
        block_id: selected.value.block_id,
        field_path: selected.value.field_path,
      },
      value: inputValue(),
      change_notes: visualChangeNote(selected.value),
      expected_working_revision_id: selectedRevisionId(),
      lock_token: selected.value.block_id ? activeLockTokenByBlock.value[selected.value.block_id] || undefined : undefined,
    });
    notifySuccess(selected.value.field_path === 'editorial_status' ? 'État éditorial du bloc enregistré.' : 'Champ enregistré dans le brouillon.');
    const savedRevisionId = Number(response.data.working_revision_id || 0);
    emit('field-saved', { working_revision_id: savedRevisionId });
    await loadVisual(savedAnchor, savedRevisionId || null, true);
  } catch (err) {
    notifyError(apiErrorMessage(err, 'Enregistrement visuel impossible.'));
  } finally {
    saving.value = false;
  }
}
function selectStatusField() {
  if (selectedBlockStatusField.value) selectField(selectedBlockStatusField.value);
}
function openStructure() {
  emit('open-structure', { blockId: selected.value?.block_id ?? null });
}
function handleIframeLoad() {
  syncBlockStatuses();
  requestFrameSize();
  highlightSelectedBlock();
  setTimeout(syncBlockStatuses, 80);
  setTimeout(syncBlockStatuses, 300);
}
function statusLabel(status: string | undefined): string {
  return statusOptions.find((option) => option.value === status)?.label || 'Publié';
}

onMounted(() => { window.addEventListener('message', handleMessage); startLockHeartbeat(); void loadVisual(); });
onBeforeUnmount(() => { window.removeEventListener('message', handleMessage); if (heartbeatTimer) window.clearInterval(heartbeatTimer); void releaseActiveLock(); });
watch(() => [props.entryId, props.siteId, props.languageCode, props.workingRevisionId], () => { activeLockBlockId.value = ''; void loadVisual(preferredSelectionAnchor()); });
watch(viewportMode, () => { setTimeout(requestFrameSize, 80); });
</script>

<template>
  <section class="visual-editor-shell">
    <div class="visual-editor-grid">
      <div class="visual-preview-stage" :style="{ background: previewChromeColor }">
        <div class="visual-preview-frame" :style="{ width: frameWidth }">
          <div v-if="loading || navigating" class="visual-preview-loading">{{ navigating ? 'Ouverture du lien dans l’éditeur visuel…' : 'Chargement de la preview…' }}</div>
          <iframe v-else-if="previewUrl" ref="iframe" :src="previewUrl" :style="{ height: frameHeightCss }" title="Preview visuelle" sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox" @load="handleIframeLoad" />
          <p v-else class="empty-editor">Preview indisponible.</p>
        </div>
      </div>

      <aside class="visual-side-panel">
        <section class="visual-panel-card">
          <div class="d-flex align-items-start justify-content-between gap-2">
            <div>
              <p class="eyebrow">Sélection</p>
              <h3>
                {{ selected?.label || 'Aucun champ sélectionné' }}
                <InfoHint v-if="!selected" text="Cliquez sur un bloc ou un champ dans la preview pour l’éditer ici. Les liens internes restent dans le mode visuel." placement="end" />
              </h3>
            </div>
            <button type="button" class="btn btn-sm btn-light" :disabled="!selected" @click="openStructure">Édition du bloc</button>
          </div>
          <p v-if="selectedBlock" class="muted mb-3">Bloc : {{ selectedBlock.label || selectedBlock.type }} · {{ selectedBlock.id }}</p>
          <template v-if="selected">
            <label class="form-label fw-semibold" :for="`visual-field-${selected.key}`">{{ selected.label }}</label>
            <select v-if="selected.editor === 'editorial_status'" :id="`visual-field-${selected.key}`" class="form-select" v-model="draftValue" :disabled="selectedBlockLockedByOther">
              <option v-for="option in statusOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
            <textarea v-else-if="['inline_textarea', 'richtext_light', 'html_code'].includes(selected.editor)" :id="`visual-field-${selected.key}`" class="form-control" rows="8" v-model="draftValue" :disabled="selectedBlockLockedByOther" />
            <div v-else-if="selected.editor === 'button_list'" class="visual-button-list-editor">
              <div v-if="!buttonDrafts.length" class="empty-editor compact">Aucun bouton dans ce bloc.</div>
              <article
                v-for="(button, index) in buttonDrafts"
                :key="index"
                :class="['visual-button-card', { active: selectedButtonIndex === index }]"
                @click="selectButtonDraft(index)"
              >
                <div class="visual-button-card__header">
                  <strong>Bouton {{ index + 1 }}</strong>
                  <button type="button" class="btn btn-sm btn-light" :disabled="selectedBlockLockedByOther" @click.stop="removeButtonDraft(index)">Supprimer</button>
                </div>
                <label class="form-label">Libellé</label>
                <input class="form-control" type="text" v-model="button.label" :disabled="selectedBlockLockedByOther" @input="syncButtonJson" />
                <label class="form-label mt-2">URL</label>
                <input class="form-control" type="text" v-model="button.url" :disabled="selectedBlockLockedByOther" @input="syncButtonJson" />
                <div class="visual-button-card__grid">
                  <label>
                    <span class="form-label">Style</span>
                    <select class="form-select" v-model="button.style" :disabled="selectedBlockLockedByOther" @change="syncButtonJson">
                      <option v-for="option in buttonStyleOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                  </label>
                  <label>
                    <span class="form-label">Cible</span>
                    <select class="form-select" v-model="button.target" :disabled="selectedBlockLockedByOther" @change="syncButtonJson">
                      <option v-for="option in buttonTargetOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                  </label>
                </div>
              </article>
              <button type="button" class="btn btn-light w-100" :disabled="selectedBlockLockedByOther" @click="addButtonDraft">Ajouter un bouton</button>
              <details class="visual-media-field__technical">
                <summary>JSON technique</summary>
                <textarea :id="`visual-field-${selected.key}`" class="form-control font-monospace" rows="8" v-model="jsonValue" :disabled="selectedBlockLockedByOther" @change="applyButtonJson" />
              </details>
            </div>
            <textarea v-else-if="['json', 'gallery_items'].includes(selected.editor)" :id="`visual-field-${selected.key}`" class="form-control font-monospace" rows="12" v-model="jsonValue" :disabled="selectedBlockLockedByOther" />
            <input v-else-if="selected.editor === 'number'" :id="`visual-field-${selected.key}`" class="form-control" type="number" v-model="draftValue" :disabled="selectedBlockLockedByOther" />
            <label v-else-if="selected.editor === 'boolean'" class="checkline visual-checkline"><input :id="`visual-field-${selected.key}`" type="checkbox" :checked="draftValue === 'true' || draftValue === '1'" :disabled="selectedBlockLockedByOther" @change="draftValue = ($event.target as HTMLInputElement).checked ? 'true' : 'false'" /> Actif</label>
            <div v-else-if="selectedLooksLikeImageField" class="visual-media-field">
              <MediaPicker
                accept-type="image"
                label="Image du bloc"
                help="Choisissez visuellement une image. L’URL de la variante responsive sera enregistrée dans ce champ."
                :compact="true"
                :preferred-set="visualPreferredSet()"
                :media-id="visualMediaId()"
                :src="draftValue"
                :disabled="selectedBlockLockedByOther"
                @select="setVisualMedia"
                @update:src="draftValue = $event; selectedVisualMediaPayload = null"
              />
              <details class="visual-media-field__technical">
                <summary>URL technique</summary>
                <input :id="`visual-field-${selected.key}`" class="form-control" type="text" v-model="draftValue" :disabled="selectedBlockLockedByOther" />
              </details>
            </div>
            <input v-else :id="`visual-field-${selected.key}`" class="form-control" type="text" v-model="draftValue" :disabled="selectedBlockLockedByOther" />
            <div class="form-text">{{ selected.field_path }} · {{ selected.editor }}</div>
            <p v-if="selectedBlockLockedByOther" class="alert alert-warning mt-3">Bloc actuellement verrouillé par {{ selectedBlockLock?.locked_by_label }}.</p>
            <p v-else-if="selectedBlockLockedByMe" class="visual-lock-note mt-3">Verrou actif sur ce bloc.</p>
            <div class="visual-field-actions mt-3">
              <button type="button" class="btn btn-primary" :disabled="saving || selectedBlockLockedByOther" @click="saveSelected">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
              <button v-if="selectedBlockLockedByMe" type="button" class="btn btn-light" :disabled="saving" @click="unlockSelectedBlock">Déverrouiller</button>
            </div>
          </template>
        </section>

        <section v-if="blockFields.length" class="visual-panel-card">
          <p class="eyebrow">Champs du bloc</p>
          <button v-for="field in blockFields" :key="field.key" type="button" :class="['visual-field-chip', { active: selected?.key === field.key }]" @click="selectField(field)">
            <span>{{ field.label }}</span>
            <small>{{ field.field_path }}</small>
          </button>
        </section>

        <section class="visual-panel-card translation-card">
          <p class="eyebrow">Traductions</p>
          <h3>État multilingue</h3>
          <div v-for="item in translations" :key="item.language_code" class="translation-row" :class="`is-${item.status}`">
            <strong>{{ item.language_code.toUpperCase() }}</strong>
            <span>{{ translationStatusText(item) }}</span>
            <small v-if="item.missing.length">À compléter : {{ item.missing.join(', ') }}</small>
          </div>
        </section>
      </aside>
    </div>
  </section>
</template>
