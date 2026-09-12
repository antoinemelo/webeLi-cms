<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import MediaPicker from '@/components/editor/MediaPicker.vue';
import { useAdminContextStore } from '@/stores/adminContext';

type Storytelling = { id: number; storytelling_key: string; title: string; eyebrow?: string; body_markdown?: string; image_media_id?: number | null; image_alt?: string; cta_label?: string; cta_url?: string; status: 'draft'|'published'; updated_at?: string };
const context = useAdminContextStore();
const items = ref<Storytelling[]>([]);
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const success = ref('');
const shopActive = ref(false);
const selectedId = ref(0);
const canWrite = computed(() => context.can('business.catalog.write'));
const form = reactive({ storytelling_key: '', title: '', eyebrow: '', body_markdown: '', image_media_id: '', image_alt: '', cta_label: '', cta_url: '', status: 'draft' as 'draft'|'published' });

function reset(clearFeedback = true) { selectedId.value = 0; Object.assign(form, { storytelling_key: '', title: '', eyebrow: '', body_markdown: '', image_media_id: '', image_alt: '', cta_label: '', cta_url: '', status: 'draft' }); if (clearFeedback) { success.value = ''; error.value = ''; } }
function edit(item: Storytelling) { selectedId.value = item.id; Object.assign(form, { storytelling_key: item.storytelling_key, title: item.title, eyebrow: item.eyebrow || '', body_markdown: item.body_markdown || '', image_media_id: item.image_media_id ? String(item.image_media_id) : '', image_alt: item.image_alt || '', cta_label: item.cta_label || '', cta_url: item.cta_url || '', status: item.status }); success.value = ''; error.value = ''; }
async function load() {
  loading.value = true; error.value = '';
  try { const response = await adminApi.get<{ shop_active: boolean; items: Storytelling[] }>('/business/storytellings', { site_id: context.siteId, language_code: context.contentLanguageCode }); items.value = response.data.items || []; shopActive.value = response.data.shop_active === true; }
  catch (reason) { error.value = apiErrorMessage(reason, 'Chargement des storytellings impossible.'); }
  finally { loading.value = false; }
}
async function save() {
  if (!form.title.trim()) { error.value = 'Le titre est obligatoire.'; return; }
  saving.value = true; error.value = ''; success.value = '';
  const payload = { ...form, site_id: context.siteId, language_code: context.contentLanguageCode, image_media_id: Number(form.image_media_id) || null };
  try {
    if (selectedId.value) await adminApi.patch(`/business/storytellings/${selectedId.value}`, payload);
    else await adminApi.post('/business/storytellings', payload);
    success.value = selectedId.value ? 'Storytelling mis à jour.' : 'Storytelling créé.'; await load(); if (!selectedId.value) reset(false);
  } catch (reason) { error.value = apiErrorMessage(reason, 'Enregistrement du storytelling impossible.'); }
  finally { saving.value = false; }
}
async function remove(item: Storytelling) {
  if (!window.confirm(`Supprimer « ${item.title} » ?`)) return;
  try { await adminApi.delete(`/business/storytellings/${item.id}`, { site_id: context.siteId, language_code: context.contentLanguageCode }); if (selectedId.value === item.id) reset(); await load(); success.value = 'Storytelling supprimé.'; }
  catch (reason) { error.value = apiErrorMessage(reason, 'Suppression impossible.'); }
}
function removeSelected() { const item = items.value.find((candidate) => candidate.id === selectedId.value); if (item) void remove(item); }
watch(() => [context.siteId, context.contentLanguageCode], () => { reset(); void load(); });
onMounted(() => { void load(); });
</script>

<template>
  <section class="storytelling-admin">
    <header class="business-panel-head"><div><p class="eyebrow">Commerce éditorial</p><h2>Storytelling</h2><p>Composez des récits réutilisables, puis insérez-les dans une page ou un article avec le bloc Storytelling.</p></div><button v-if="canWrite" class="btn small" type="button" @click="reset()">Nouveau</button></header>
    <ApiFeedback :error="error" :success="success" />
    <p v-if="!shopActive && !loading" class="notice notice--warning">La boutique est inactive pour ce site et cette langue. Les récits restent éditables, mais le bloc Storytelling est masqué et ne sera pas rendu publiquement.</p>
    <div class="storytelling-layout">
      <aside class="storytelling-list" aria-label="Storytellings">
        <button v-for="item in items" :key="item.id" type="button" :class="{ active: selectedId === item.id }" @click="edit(item)"><span><strong>{{ item.title }}</strong><small>{{ item.storytelling_key }}</small></span><StatusBadge :status="item.status" /></button>
        <p v-if="!items.length && !loading" class="business-empty">Aucun storytelling dans cette langue.</p>
      </aside>
      <form class="storytelling-form" @submit.prevent="save">
        <div class="block-grid"><label>Titre<input v-model="form.title" required :disabled="!canWrite"></label><label>Clé stable<input v-model="form.storytelling_key" placeholder="générée depuis le titre" :disabled="!canWrite"></label><label class="wide">Accroche<input v-model="form.eyebrow" :disabled="!canWrite"></label><label class="wide">Récit (Markdown)<textarea v-model="form.body_markdown" rows="10" :disabled="!canWrite" /></label><div class="wide"><MediaPicker accept-type="image" label="Illustration" preferred-set="content" :media-id="Number(form.image_media_id || 0)" :alt="form.image_alt" :disabled="!canWrite" @update:media-id="form.image_media_id = $event ? String($event) : ''" @update:alt="form.image_alt = $event" /></div><label>Libellé du lien<input v-model="form.cta_label" :disabled="!canWrite"></label><label>URL du lien<input v-model="form.cta_url" placeholder="/shop/..." :disabled="!canWrite"></label><label>Statut<select v-model="form.status" :disabled="!canWrite"><option value="draft">Brouillon</option><option value="published">Publié</option></select></label></div>
        <div v-if="canWrite" class="storytelling-actions"><button class="btn primary" type="submit" :disabled="saving">{{ saving ? 'Enregistrement…' : (selectedId ? 'Mettre à jour' : 'Créer') }}</button><button v-if="selectedId" class="btn danger" type="button" @click="removeSelected">Supprimer</button></div>
      </form>
    </div>
  </section>
</template>

<style scoped>
.storytelling-layout{display:grid;gap:1rem;grid-template-columns:minmax(14rem,22rem) 1fr}.storytelling-list{display:grid;align-content:start;gap:.5rem}.storytelling-list>button{align-items:center;background:var(--surface,#fff);border:1px solid var(--border,#d5dae1);border-radius:.6rem;display:flex;justify-content:space-between;padding:.7rem;text-align:left}.storytelling-list>button.active{border-color:var(--primary,#1463df)}.storytelling-list span{display:grid}.storytelling-list small{color:var(--muted,#667085)}.storytelling-form{background:var(--surface,#fff);border:1px solid var(--border,#d5dae1);border-radius:.75rem;padding:1rem}.block-grid{display:grid;gap:.85rem;grid-template-columns:repeat(2,minmax(0,1fr))}.block-grid label{display:grid;gap:.3rem}.block-grid .wide{grid-column:1/-1}.storytelling-actions{display:flex;gap:.6rem;margin-top:1rem}@media(max-width:800px){.storytelling-layout,.block-grid{grid-template-columns:1fr}}
</style>
