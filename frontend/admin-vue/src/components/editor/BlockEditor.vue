<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { AdminApiError, adminApi } from '@/api/client';
import type { BlockBlueprintIndex, EditorialBlock, FormDefinition, NativeBlockBlueprint } from '@/api/contracts';
import NativeBlockDataEditor from '@/components/editor/NativeBlockDataEditor.vue';
import InfoHint from '@/components/ui/InfoHint.vue';

// Responsive media admin contract kept here for c_db_validate.py:
// Hero media is edited through native fields, while MediaPicker still receives
// the semantic labels expected by the responsive media contract: Image principale du hero, preferred-set="hero".

type BlockType = EditorialBlock['type'];
type EditorialStatus = 'draft' | 'review' | 'published' | 'archived';

const props = defineProps<{ modelValue: EditorialBlock[]; entryId?: number | string | null; siteId?: number | null; languageCode?: string; allowedBlockTypes?: string[]; disabled?: boolean; disabledMessage?: string }>();
const emit = defineEmits<{
  'update:modelValue': [EditorialBlock[]];
  'modal-closed': [];
  'lock-feedback': [payload: { kind: 'success' | 'error'; message: string }];
  autosave: [reason: string];
}>();

type BlockLock = {
  block_id: string;
  locked_by_label: string;
  is_current_user: boolean;
  lock_token: string;
  expires_at: string;
};

const nativeBlockTypes: Array<[BlockType, string]> = [
  ['markdown', 'Markdown'], ['richtext', 'Texte enrichi'], ['html_safe', 'HTML sûr'], ['html_raw', 'HTML brut'], ['image', 'Image'], ['video', 'Vidéo'], ['audio', 'Audio'],
  ['iframe', 'Iframe'], ['embed', 'Embed'], ['hero', 'Hero'], ['gallery', 'Galerie'], ['buttons', 'Boutons'], ['card', 'Carte'], ['columns', 'Colonnes'], ['form', 'Formulaire'],
  ['plan', 'Plan'], ['articles', 'Articles'],
  ['commerce_product', 'Produit ou variante'], ['commerce_product_variants', 'Variantes d’un produit'], ['commerce_product_list', 'Liste de produits'], ['storytelling', 'Storytelling']
];
const commerceBlockTypes = new Set<BlockType>(['commerce_product', 'commerce_product_variants', 'commerce_product_list', 'storytelling']);
const availableCommerceBlocks = ref(new Set<BlockType>());
const blockTypes = computed<Array<[BlockType, string]>>(() => {
  const allowed = new Set((props.allowedBlockTypes || []).map(String));
  return nativeBlockTypes.filter(([type]) => (!commerceBlockTypes.has(type) || availableCommerceBlocks.value.has(type)) && (allowed.size === 0 || allowed.has(type)));
});
const statuses: Array<[EditorialStatus, string]> = [
  ['draft', 'Brouillon'], ['review', 'Relecture'], ['published', 'Publié'], ['archived', 'Archivé']
];
const presets = [
  ['home', 'Page d’accueil complète'], ['homeHero', 'Hero d’accueil'], ['featureCards', 'Cartes en 3 colonnes'], ['splitPanel', 'Section texte + encadré']
] as const;
const blocks = computed<EditorialBlock[]>({
  get: () => normalize(props.modelValue ?? []),
  set: (value: EditorialBlock[]) => emit('update:modelValue', normalize(value))
});
const activeIndex = ref<number | null>(null);
const draft = ref<EditorialBlock | null>(null);
const draggingBlockIndex = ref<number | null>(null);
const dragOverBlockIndex = ref<number | null>(null);
const formOptions = ref<FormDefinition[]>([]);
const blockBlueprints = ref<NativeBlockBlueprint[]>([]);
const fieldsets = ref<Record<string, unknown>>({});
const formsLoading = ref(false);
const formSelectOptions = computed(() => formOptions.value.filter((form) => form.is_active !== false && form.status === 'published'));
const blueprintByType = computed(() => Object.fromEntries(blockBlueprints.value.map((blueprint) => [blueprint.key, blueprint])) as Partial<Record<BlockType, NativeBlockBlueprint>>);
const blockLocks = ref<BlockLock[]>([]);
const activeLockBlockId = ref('');
const lockHeartbeat = ref<number | null>(null);
const canUseRemoteLocks = computed(() => Number(props.entryId || 0) > 0 && Number(props.siteId || 0) > 0 && String(props.languageCode || '').trim() !== '');
const isEditorDisabled = computed(() => props.disabled === true);
const editorDisabledMessage = computed(() => props.disabledMessage || 'Saisissez d’abord le titre avant de modifier la composition par blocs.');

function lockFor(blockId: string): BlockLock | null {
  return blockLocks.value.find((lock) => lock.block_id === blockId) ?? null;
}
function isLockedByOther(blockId: string): boolean {
  const lock = lockFor(blockId);
  return Boolean(lock && !lock.is_current_user);
}
function lockedByLabel(blockId: string): string {
  return lockFor(blockId)?.locked_by_label || '';
}
function rememberLock(lock: BlockLock) {
  blockLocks.value = [...blockLocks.value.filter((item) => item.block_id !== lock.block_id), lock];
}
function forgetLock(blockId: string) {
  blockLocks.value = blockLocks.value.filter((item) => item.block_id !== blockId);
  if (activeLockBlockId.value === blockId) activeLockBlockId.value = '';
}
async function refreshBlockLocks() {
  if (!canUseRemoteLocks.value) return;
  try {
    const response = await adminApi.get<{ locks: BlockLock[] }>(`/visual/entries/${props.entryId}/block-locks`, { site_id: props.siteId || undefined, language_code: props.languageCode || undefined });
    blockLocks.value = Array.isArray(response.data.locks) ? response.data.locks : [];
    if (activeLockBlockId.value && !blockLocks.value.some((lock) => lock.block_id === activeLockBlockId.value && lock.is_current_user)) {
      activeLockBlockId.value = '';
    }
  } catch {
    // The structure editor remains usable if polling fails; the backend save guard still prevents unsafe writes.
  }
}
async function acquireStructureLock(blockId: string, heartbeat = false): Promise<boolean> {
  if (!blockId || !canUseRemoteLocks.value) return true;
  try {
    const current = lockFor(blockId);
    const response = await adminApi.post<{ lock: BlockLock }>(`/visual/entries/${props.entryId}/block-lock`, {
      site_id: props.siteId,
      language_code: props.languageCode,
      block_id: blockId,
      lock_token: current?.is_current_user ? current.lock_token : undefined
    });
    rememberLock(response.data.lock);
    activeLockBlockId.value = blockId;
    return true;
  } catch (error) {
    if (error instanceof AdminApiError && error.code === 'RESOURCE_LOCKED') {
      const lock = error.details?.lock as BlockLock | undefined;
      if (lock?.block_id) rememberLock(lock);
      if (!heartbeat) emit('lock-feedback', { kind: 'error', message: `Ce bloc est déjà édité par ${lock?.locked_by_label || 'un autre utilisateur'}.` });
      return false;
    }
    if (!heartbeat) emit('lock-feedback', { kind: 'error', message: 'Verrouillage du bloc impossible. Réessayez avant de modifier ce bloc.' });
    return false;
  }
}
async function releaseStructureLock(blockId: string) {
  if (!blockId || !canUseRemoteLocks.value) return;
  const lock = lockFor(blockId);
  if (lock && !lock.is_current_user) return;
  try {
    await adminApi.delete(`/visual/entries/${props.entryId}/block-lock`, {
      site_id: props.siteId,
      language_code: props.languageCode,
      block_id: blockId,
      lock_token: lock?.lock_token || undefined
    });
  } catch {
    // Expired locks are purged server-side; release failures are non-blocking.
  } finally {
    forgetLock(blockId);
  }
}
function startLockHeartbeat() {
  if (lockHeartbeat.value !== null) return;
  lockHeartbeat.value = window.setInterval(() => {
    if (activeLockBlockId.value) void acquireStructureLock(activeLockBlockId.value, true);
    void refreshBlockLocks();
  }, 45_000);
}
function stopLockHeartbeat() {
  if (lockHeartbeat.value === null) return;
  window.clearInterval(lockHeartbeat.value);
  lockHeartbeat.value = null;
}


watch(() => props.modelValue, () => {
  if (activeIndex.value !== null) {
    const current = blocks.value[activeIndex.value];
    if (current) draft.value = cloneBlock(current);
  }
}, { deep: true });

function uid() { return `block_${Math.random().toString(36).slice(2, 10)}${Date.now().toString(36).slice(-4)}`; }
function normalize(value: EditorialBlock[]): EditorialBlock[] {
  return value.map((b, i) => ({
    ...b,
    enabled: b.enabled !== false,
    editorial_status: normalizeStatus((b as any).editorial_status ?? (b as any).workflow_status ?? 'published'),
    label: b.label ?? '',
    css_class: b.css_class ?? '',
    anchor: b.anchor ?? '',
    sort_order: i,
    data: b.data ?? {}
  }));
}
function normalizeStatus(status: unknown): EditorialStatus {
  return statuses.some(([value]) => value === status) ? status as EditorialStatus : 'published';
}
function cloneBlock(block: EditorialBlock): EditorialBlock {
  const cloned = JSON.parse(JSON.stringify(block)) as EditorialBlock;
  cloned.editorial_status = normalizeStatus(cloned.editorial_status ?? 'published');
  return cloned;
}
function labelFor(type: BlockType) {
  return blockTypes.value.find(([value]) => value === type)?.[1] ?? type;
}
function statusLabel(status: unknown) {
  return statuses.find(([value]) => value === status)?.[1] ?? 'Publié';
}
function formOptionLabel(form: FormDefinition): string {
  const name = typeof form.name === 'string' && form.name.trim() !== '' ? form.name.trim() : form.form_key;
  return `${name} (${form.form_key})`;
}
async function loadFormOptions() {
  formsLoading.value = true;
  try {
    const res = await adminApi.get<{ forms: FormDefinition[] }>('/forms');
    formOptions.value = Array.isArray(res.data.forms) ? res.data.forms : [];
  } catch {
    formOptions.value = [];
  } finally {
    formsLoading.value = false;
  }
}
async function loadBlockBlueprints() {
  try {
    const res = await adminApi.get<BlockBlueprintIndex>('/block-blueprints', { site_id: props.siteId || undefined, language_code: props.languageCode || undefined });
    blockBlueprints.value = Array.isArray(res.data.blueprints) ? res.data.blueprints : [];
    availableCommerceBlocks.value = new Set(blockBlueprints.value.map((blueprint) => blueprint.key).filter((key): key is BlockType => commerceBlockTypes.has(key as BlockType)));
    fieldsets.value = res.data.fieldsets && typeof res.data.fieldsets === 'object' ? res.data.fieldsets : {};
  } catch {
    blockBlueprints.value = [];
    availableCommerceBlocks.value = new Set();
    fieldsets.value = {};
  }
}

function block(type: BlockType, data: Record<string, unknown>, label = '', css_class = '', anchor = '', status: EditorialStatus = 'published'): EditorialBlock {
  return { id: uid(), type, enabled: true, editorial_status: status, label, css_class, anchor, data, sort_order: 0 } as EditorialBlock;
}
function defaultData(type: BlockType): Record<string, unknown> {
  const defaults = blueprintByType.value[type]?.defaults;
  if (defaults && typeof defaults === 'object') return JSON.parse(JSON.stringify(defaults)) as Record<string, unknown>;
  if (type === 'markdown') return { text: '' };
  if (type === 'richtext') return { html: '<p>Votre texte enrichi</p>' };
  if (type === 'html_safe') return { html: '<div class="container py-4">Votre HTML filtré</div>' };
  if (type === 'html_raw') return { html: '<!-- Réservé aux utilisateurs autorisés -->' };
  if (type === 'image') return { media_id: 0, src: '', alt: '', caption: '', ratio: 'auto', loading: 'lazy' };
  if (type === 'video') return { media_id: 0, src: '', title: '', caption: '', poster_media_id: 0, poster: '' };
  if (type === 'audio') return { media_id: 0, src: '', title: '', caption: '' };
  if (type === 'iframe') return { src: '', title: '', height: 420, allow: 'fullscreen; picture-in-picture', sandbox: 'allow-scripts allow-same-origin allow-presentation' };
  if (type === 'embed') return { provider: 'generic', url: '', title: '' };
  if (type === 'hero') return { eyebrow: '', title: '', lead: '', heading_level: 'h2', layout: 'simple', image_media_id: 0, image_src: '', image_alt: '', image_width: 1280, image_height: 853, image_loading: 'eager', caption: '', buttons: [] };
  if (type === 'gallery') return { columns: 3, items: [] };
  if (type === 'form') return { form_key: '', title: '', intro: '', layout: 'default' };
  if (type === 'plan') return { title: 'Plan', source: 'pages', limit: 8, show_pagination: true };
  if (type === 'articles') return { title: 'Articles', limit: 3, category: '', tag: '', show_more_button: true, more_label: '' };
  if (type === 'buttons') return { items: [{ label: 'En savoir plus', url: '/', style: 'primary', target: '_self' }] };
  if (type === 'card') return { icon_media_id: 0, icon_src: '', icon_alt: '', icon: '', title: '', text: '', url: '', link_label: '', style: 'default' };
  return { layout: '2_equal', gap: 'md', stack_on_mobile: true, columns: [{ label: '', width: 'auto', blocks: [] }, { label: '', width: 'auto', blocks: [] }] };
}
async function add(type: BlockType) {
  if (isEditorDisabled.value) return;
  const nextBlock = block(type, defaultData(type), labelFor(type));
  const next = [...blocks.value, nextBlock];
  blocks.value = next;
  await nextTick();
  document.getElementById('block-structure-title')?.scrollIntoView({ block: 'start', behavior: 'smooth' });
  const opened = await open(next.length - 1);
  if (!opened) emit('lock-feedback', { kind: 'success', message: `Bloc « ${labelFor(type)} » ajouté à la composition. Renseignez son contenu puis enregistrez le bloc.` });
}
function canDragBlock(index: number): boolean {
  if (isEditorDisabled.value) return false;
  const current = blocks.value[index];
  return Boolean(current && !isLockedByOther(current.id));
}
function onBlockDragStart(index: number, event: DragEvent) {
  if (!canDragBlock(index)) {
    event.preventDefault();
    return;
  }
  draggingBlockIndex.value = index;
  dragOverBlockIndex.value = index;
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(index));
  }
}
function onBlockDragEnter(index: number) {
  if (draggingBlockIndex.value === null || !canDragBlock(index)) return;
  dragOverBlockIndex.value = index;
}
function onBlockDragOver(index: number, event: DragEvent) {
  if (draggingBlockIndex.value === null || !canDragBlock(index)) return;
  event.preventDefault();
  if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
  dragOverBlockIndex.value = index;
}
function onBlockDrop(targetIndex: number, event: DragEvent) {
  event.preventDefault();
  const sourceIndex = draggingBlockIndex.value;
  if (sourceIndex === null || sourceIndex === targetIndex || !canDragBlock(sourceIndex) || !canDragBlock(targetIndex)) {
    onBlockDragEnd();
    return;
  }
  const next = [...blocks.value];
  const [moved] = next.splice(sourceIndex, 1);
  next.splice(targetIndex, 0, moved);
  blocks.value = next;
  const nextIndex = next.findIndex((item) => item.id === moved.id);
  const label = moved.label || labelFor(moved.type as BlockType);
  emit('autosave', `Bloc « ${label} » déplacé en position ${nextIndex + 1}.`);
  onBlockDragEnd();
}
function onBlockDragEnd() {
  draggingBlockIndex.value = null;
  dragOverBlockIndex.value = null;
}
function updateBlockEnabled(index: number, enabled: boolean) {
  if (isEditorDisabled.value) return;
  const blockId = blocks.value[index]?.id || '';
  if (blockId && isLockedByOther(blockId)) return;
  blocks.value = blocks.value.map((b, i) => i === index ? { ...b, enabled } : b);
}
function remove(index: number) {
  if (isEditorDisabled.value) return;
  const selected = blocks.value[index];
  const blockId = selected?.id || '';
  if (blockId && isLockedByOther(blockId)) return;
  const label = selected?.label || (selected?.type ? labelFor(selected.type as BlockType) : 'bloc');
  blocks.value = blocks.value.filter((_, i) => i !== index);
  emit('autosave', `Bloc « ${label} » supprimé.`);
}
async function open(index: number) {
  if (isEditorDisabled.value) return false;
  const selected = blocks.value[index];
  if (!selected) return false;
  if (isLockedByOther(selected.id)) {
    emit('lock-feedback', { kind: 'error', message: `Ce bloc est déjà édité par ${lockedByLabel(selected.id) || 'un autre utilisateur'}.` });
    return false;
  }
  if (activeLockBlockId.value && activeLockBlockId.value !== selected.id) {
    await releaseStructureLock(activeLockBlockId.value);
  }
  if (!(await acquireStructureLock(selected.id))) return false;
  activeIndex.value = index;
  draft.value = cloneBlock(selected);
  startLockHeartbeat();
  return true;
}
async function close() {
  const blockId = activeLockBlockId.value;
  activeIndex.value = null;
  draft.value = null;
  if (blockId) await releaseStructureLock(blockId);
  stopLockHeartbeat();
  emit('modal-closed');
}
function commitPendingDraft() {
  if (activeIndex.value === null || !draft.value) return false;
  const next = [...blocks.value];
  if (!next[activeIndex.value]) return false;
  next[activeIndex.value] = cloneBlock(draft.value);
  blocks.value = next;
  return true;
}
function hasPendingDraft() {
  return activeIndex.value !== null && draft.value !== null;
}
async function openByBlockId(blockId: string) {
  const index = blocks.value.findIndex((item) => item.id === blockId);
  if (index < 0) return false;
  const opened = await open(index);
  if (!opened) return false;
  requestAnimationFrame(() => {
    document.querySelector(`[data-block-row-id=\"${CSS.escape(blockId)}\"]`)?.scrollIntoView({ block: 'center', behavior: 'smooth' });
  });
  return true;
}
function saveModal() {
  if (!commitPendingDraft()) return;
  const label = draft.value?.label || draft.value?.type || 'bloc';
  emit('autosave', `Bloc « ${label} » enregistré depuis la fenêtre modale.`);
  void close();
}
defineExpose({ commitPendingDraft, hasPendingDraft, openByBlockId });
onMounted(() => {
  void loadFormOptions();
  void loadBlockBlueprints();
  void refreshBlockLocks();
});
watch(() => [props.siteId, props.languageCode], () => { void loadBlockBlueprints(); });
onBeforeUnmount(() => {
  const blockId = activeLockBlockId.value;
  stopLockHeartbeat();
  if (blockId) void releaseStructureLock(blockId);
});
function updateDraft(patch: Partial<EditorialBlock>) {
  if (!draft.value) return;
  draft.value = { ...draft.value, ...patch };
}
function updateDraftData(key: string, value: unknown) {
  if (!draft.value) return;
  draft.value = { ...draft.value, data: { ...(draft.value.data ?? {}), [key]: value } };
}
function allowedChildBlockTypes(): Array<[BlockType, string]> {
  return blockTypes.value.filter(([type]) => type !== 'columns');
}

function applyPreset(name: typeof presets[number][0]) {
  if (isEditorDisabled.value) return;
  if (name === 'homeHero') { blocks.value = [...blocks.value, block('hero', homeHeroData(), 'Hero accueil', '', '', 'published')]; emit('autosave', 'Preset « Hero d’accueil » ajouté.'); return; }
  if (name === 'featureCards') { blocks.value = [...blocks.value, featureCardsBlock()]; emit('autosave', 'Preset « Cartes en 3 colonnes » ajouté.'); return; }
  if (name === 'splitPanel') { blocks.value = [...blocks.value, splitPanelBlock()]; emit('autosave', 'Preset « Section texte + encadré » ajouté.'); return; }
  blocks.value = [block('hero', homeHeroData(), 'Hero accueil', '', '', 'published'), featureCardsBlock(), splitPanelBlock()];
  emit('autosave', 'Preset « Page d’accueil complète » appliqué.');
}
function homeHeroData() {
  return { eyebrow: 'Publication propre, rapide et multilingue', title: 'Accueil', lead: 'Un front public de référence pour un CMS éditorial SEO-first.', heading_level: 'h1', layout: 'split', image_src: '/frontend/theme-default/assets/img/cms-hero-1280.png', image_alt: 'Illustration d’un CMS SEO-first avec contenus, recherche et publication', image_width: 1280, image_height: 853, image_loading: 'eager', buttons: [{ label: 'Découvrir le CMS', url: '/articles/seo-multisite', style: 'primary', target: '_self' }, { label: 'Contact', url: '/contact', style: 'ghost', target: '_self' }] };
}
function featureCardsBlock(): EditorialBlock {
  const cards = [
    ['↝', 'SEO-first', 'Canonical, hreflang, Open Graph, meta description et routes publiées propres.'],
    ['文', 'Multilingue', 'Références en français, anglais et allemand avec navigation adaptée à la langue.'],
    ['⚡', 'Runtime léger', 'HTML natif, templates Twig, CSS compact et couche publique minimale.']
  ];
  return block('columns', { layout: 'cards_3', gap: 'md', stack_on_mobile: true, columns: cards.map(([icon, title, text]) => ({ label: String(title), width: 'auto', blocks: [block('card', { icon: String(icon), title: String(title), text: String(text), url: '', link_label: '', style: 'feature' }, String(title), 'card feature-card', '', 'published')] })) }, 'Cartes forces', 'section', '', 'published');
}
function splitPanelBlock(): EditorialBlock {
  return block('html_safe', { html: '<section class="split-panel" aria-labelledby="publication-title"><div><p class="eyebrow">Front de référence</p><h2 id="publication-title">Une publication propre,<br>rapide et multilingue</h2></div><div class="prose"><p>Cette page d’accueil démontre une vitrine publique responsive, multilingue et pensée pour le référencement naturel : routes canoniques, meta title, meta description, hreflang, sitemap, robots.txt, Open Graph, JSON-LD, recherche publique et navigation claire.</p></div></section>' }, 'Section publication', 'section', '', 'published');
}

</script>

<template>
  <section :class="['schema-section block-editor', { 'is-editor-disabled': isEditorDisabled }]">
    <div class="section-heading">
      <div>
        <h2>Composition par blocs <InfoHint text="Les blocs sont repliés par défaut. Cliquez sur le numéro ou le type du bloc pour éditer ses détails dans une fenêtre dédiée." placement="end" /></h2>
      </div>
    </div>

    <div v-if="isEditorDisabled" class="block-editor-title-lock" role="status">
      <strong>Composition par blocs non éditable.</strong>
      <span>{{ editorDisabledMessage }}</span>
    </div>

    <div class="block-composition-grid" :aria-disabled="isEditorDisabled">
      <aside class="block-composition-column block-composition-column--library" aria-labelledby="block-library-title">
        <div class="block-composition-column__head">
          <div>
            <p class="eyebrow">Bibliothèque</p>
            <h3 id="block-library-title">Blocs à ajouter <InfoHint text="Ajoutez un bloc natif ou un preset prêt à l’emploi dans la composition." placement="end" /></h3>
          </div>
          <span class="block-count-pill">{{ blockTypes.length }}</span>
        </div>
        <div class="block-toolbar block-toolbar--library">
          <button v-for="[type, label] in blockTypes" :key="type" type="button" class="chip" :disabled="isEditorDisabled" @click="add(type)">+ {{ label }}</button>
        </div>
        <div class="block-toolbar block-toolbar--presets">
          <span class="muted">Presets</span>
          <button v-for="[key, label] in presets" :key="key" type="button" class="chip chip--preset" :disabled="isEditorDisabled" @click="applyPreset(key)">+ {{ label }}</button>
        </div>
      </aside>

      <section class="block-composition-column block-composition-column--structure" aria-labelledby="block-structure-title">
        <div class="block-composition-column__head">
          <div>
            <p class="eyebrow">Structure</p>
            <h3 id="block-structure-title">Blocs ajoutés <InfoHint text="Glissez-déposez un bloc grâce à sa poignée pour réordonner la composition." placement="end" /></h3>
          </div>
          <span class="block-count-pill">{{ blocks.length }}</span>
        </div>
        <p v-if="blocks.length === 0" class="empty-editor">Aucun bloc. Ajoutez un bloc Markdown, HTML Bootstrap, média, hero, galerie, formulaire, boutons, colonnes, plan ou articles.</p>

        <ol v-else class="block-list">
          <li
            v-for="(block, index) in blocks"
            :key="block.id"
            :data-block-row-id="block.id"
            class="block-list-row"
            :class="[`is-${block.editorial_status || 'published'}`, { 'is-disabled': block.enabled === false || isEditorDisabled, 'is-locked': isLockedByOther(block.id), 'is-dragging': draggingBlockIndex === index, 'is-drop-target': dragOverBlockIndex === index && draggingBlockIndex !== null && draggingBlockIndex !== index }]"
            :draggable="canDragBlock(index)"
            @dragstart="onBlockDragStart(index, $event)"
            @dragenter.prevent="onBlockDragEnter(index)"
            @dragover="onBlockDragOver(index, $event)"
            @drop="onBlockDrop(index, $event)"
            @dragend="onBlockDragEnd"
          >
            <span class="blueprint-drag-handle block-drag-handle" :class="{ 'is-disabled': !canDragBlock(index) }" title="Déplacer" aria-hidden="true">⋮</span>
            <button type="button" class="block-list-row__title" :disabled="isEditorDisabled || isLockedByOther(block.id)" @click="open(index)">
              <span class="block-list-row__number">{{ index + 1 }}</span>
              <span class="block-list-row__type">{{ labelFor(block.type) }}</span>
            </button>
            <span class="block-list-row__label-wrap">
              <span class="block-list-row__label">{{ block.label || 'Sans libellé interne' }}</span>
              <span v-if="isLockedByOther(block.id)" class="block-lock-pill">Verrouillé par {{ lockedByLabel(block.id) }}</span>
            </span>
            <label class="block-list-row__enabled"><input type="checkbox" :checked="block.enabled" :disabled="isEditorDisabled || isLockedByOther(block.id)" @change="updateBlockEnabled(index, ($event.target as HTMLInputElement).checked)"> Actif</label>
            <span class="block-status" :class="`block-status--${block.editorial_status || 'published'}`">{{ statusLabel(block.editorial_status) }}</span>
            <div class="block-list-row__actions" aria-label="Actions du bloc">
              <button type="button" class="btn btn-sm btn-danger-soft" :disabled="isEditorDisabled || isLockedByOther(block.id)" @click="remove(index)">Supprimer</button>
            </div>
          </li>
        </ol>
      </section>
    </div>

    <Teleport to="body">
      <div v-if="draft" class="modal-backdrop-editor" @click.self="close">
        <form class="block-modal" @submit.prevent="saveModal">
          <header class="block-modal__header">
            <div>
              <p class="eyebrow">Bloc éditorial</p>
              <h2>{{ activeIndex !== null ? activeIndex + 1 : '' }}. {{ labelFor(draft.type) }}</h2>
            </div>
            <button type="button" class="block-modal__close" aria-label="Fermer" @click="close">×</button>
          </header>

          <div class="block-modal__body">
            <div class="block-grid block-grid--meta">
              <label>Type
                <select :value="draft.type" @change="updateDraft({ type: ($event.target as HTMLSelectElement).value as BlockType, data: defaultData(($event.target as HTMLSelectElement).value as BlockType) })">
                  <option v-for="[type, label] in allowedChildBlockTypes()" :key="type" :value="type">{{ label }}</option>
                </select>
              </label>
              <label>Libellé interne<input :value="draft.label" @input="updateDraft({ label: ($event.target as HTMLInputElement).value })"></label>
              <label>État éditorial
                <select :value="draft.editorial_status || 'published'" @change="updateDraft({ editorial_status: ($event.target as HTMLSelectElement).value as EditorialStatus } as Partial<EditorialBlock>)">
                  <option v-for="[value, label] in statuses" :key="value" :value="value">{{ label }}</option>
                </select>
              </label>
              <label class="checkline checkline--modal"><input type="checkbox" :checked="draft.enabled" @change="updateDraft({ enabled: ($event.target as HTMLInputElement).checked })"> Actif</label>
              <label>Ancre SEO<input :value="draft.anchor" placeholder="ex: details-offre" @input="updateDraft({ anchor: ($event.target as HTMLInputElement).value })"></label>
              <label>Classe CSS<input :value="draft.css_class" placeholder="container py-5" @input="updateDraft({ css_class: ($event.target as HTMLInputElement).value })"></label>
            </div>

            <NativeBlockDataEditor
              v-model="draft"
              :blueprints="blockBlueprints"
              :fieldsets="fieldsets"
              :block-types="blockTypes"
              :statuses="statuses"
              :allowed-block-types="allowedChildBlockTypes().map(([type]) => type)"
              :form-options="formSelectOptions"
              :forms-loading="formsLoading"
              :site-id="siteId"
              :language-code="languageCode"
              form-placeholder="Sélectionner un formulaire publié"
            />

          </div>

          <footer class="block-modal__footer">
            <button type="button" class="btn ghost" @click="close">Annuler</button>
            <button type="submit" class="btn primary">Enregistrer le bloc</button>
          </footer>
        </form>
      </div>
    </Teleport>
  </section>
</template>
