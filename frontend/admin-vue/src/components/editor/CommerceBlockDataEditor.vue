<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { AdminApiError, adminApi } from '@/api/client';
import type { EditorialBlock } from '@/api/contracts';

defineOptions({ name: 'CommerceBlockDataEditor' });
type Sellable = { sellable_id: number; name: string; sku: string; orderable?: boolean };
type Candidate = { product_id: number; name: string; sku: string; image?: string; availability?: string; status_label?: string; channel_id?: number; sellables?: Sellable[] };
type Story = { id: number; title: string; storytelling_key: string; status: string };

const props = defineProps<{ modelValue: EditorialBlock; siteId?: number | null; languageCode?: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: EditorialBlock] }>();
const query = ref('');
const candidates = ref<Candidate[]>([]);
const stories = ref<Story[]>([]);
const shopActive = ref(false);
const loading = ref(false);
const previewing = ref(false);
const error = ref('');
const preview = ref<Record<string, any> | null>(null);
const data = computed(() => props.modelValue.data || {});
const isStory = computed(() => props.modelValue.type === 'storytelling');
const isList = computed(() => props.modelValue.type === 'commerce_product_list');
const isVariants = computed(() => props.modelValue.type === 'commerce_product_variants');
const isSingle = computed(() => props.modelValue.type === 'commerce_product');
const isPagedGrid = computed(() => isList.value || isVariants.value);
const mode = computed(() => String(data.value.selection_mode || 'explicit'));
const selectedIds = computed(() => list(data.value.product_ids));
const manualIds = computed(() => list(data.value.manual_product_ids));
const selectedProduct = computed(() => candidates.value.find((candidate) => candidate.product_id === Number(data.value.product_id || 0)) || null);

function list(value: unknown): number[] {
  if (typeof value === 'string') { const raw = value; try { value = JSON.parse(raw); } catch { value = raw.split(','); } }
  return [...new Set((Array.isArray(value) ? value : []).map(Number).filter((id) => id > 0))];
}
function patch(values: Record<string, unknown>) { emit('update:modelValue', { ...props.modelValue, data: { ...data.value, ...values } }); }
function update(key: string, value: unknown) { patch({ [key]: value }); }
async function search() {
  loading.value = true; error.value = '';
  try {
    const response = await adminApi.get<{ products: Candidate[] }>('/business/pim/storefront-blocks/candidates', { site_id: props.siteId || undefined, language_code: props.languageCode || undefined, locale: props.languageCode || undefined, q: query.value, limit: 30 });
    candidates.value = response.data.products || [];
  } catch (reason) { error.value = reason instanceof AdminApiError ? reason.message : 'Recherche de produits impossible.'; }
  finally { loading.value = false; }
}
async function loadStories() {
  if (!isStory.value) return;
  loading.value = true; error.value = '';
  try {
    const response = await adminApi.get<{ shop_active: boolean; items: Story[] }>('/business/storytellings', { site_id: props.siteId || undefined, language_code: props.languageCode || undefined });
    shopActive.value = response.data.shop_active === true;
    stories.value = (response.data.items || []).filter((story) => story.status === 'published');
  } catch (reason) { error.value = reason instanceof AdminApiError ? reason.message : 'Chargement des storytellings impossible.'; }
  finally { loading.value = false; }
}
function choose(candidate: Candidate) {
  if (!isList.value) { patch({ product_id: candidate.product_id, sellable_id: null }); return; }
  update('product_ids', selectedIds.value.includes(candidate.product_id) ? selectedIds.value.filter((id) => id !== candidate.product_id) : [...selectedIds.value, candidate.product_id]);
}
function selected(candidate: Candidate): boolean { return isList.value ? selectedIds.value.includes(candidate.product_id) : Number(data.value.product_id || 0) === candidate.product_id; }
function moveManual(index: number, direction: number) { const items = [...manualIds.value]; const target = index + direction; if (target < 0 || target >= items.length) return; [items[index], items[target]] = [items[target], items[index]]; update('manual_product_ids', items); }
function removeManual(id: number) { update('manual_product_ids', manualIds.value.filter((item) => item !== id)); }
function addManual(event: Event) { const input = event.target as HTMLInputElement; const id = Number(input.value); if (id > 0 && !manualIds.value.includes(id)) update('manual_product_ids', [...manualIds.value, id]); input.value = ''; }
function candidateName(id: number): string { return candidates.value.find((candidate) => candidate.product_id === id)?.name || `Produit #${id}`; }
async function refreshPreview() {
  previewing.value = true; error.value = '';
  try {
    const response = await adminApi.post<{ block: EditorialBlock }>('/business/pim/storefront-blocks/preview', { site_id: props.siteId || undefined, language_code: props.languageCode || undefined, locale: props.languageCode || undefined, block: props.modelValue });
    preview.value = response.data.block.data || {};
  } catch (reason) { error.value = reason instanceof AdminApiError ? reason.message : 'Aperçu Commerce impossible. Vos critères sont conservés.'; }
  finally { previewing.value = false; }
}
watch(() => [props.siteId, props.languageCode, props.modelValue.type], () => { candidates.value = []; stories.value = []; preview.value = null; void (isStory.value ? loadStories() : search()); });
onMounted(() => { void (isStory.value ? loadStories() : search()); });
</script>

<template>
  <div class="commerce-block-editor">
    <section v-if="isStory" class="native-blueprint-section">
      <h3 class="native-blueprint-section__title">Storytelling publié</h3>
      <p class="field-help">La sélection est limitée au site, à la langue et à la boutique actifs. La création se fait sous Opérations → Offres &amp; marketing → Storytelling.</p>
      <p v-if="!shopActive && !loading" class="notice notice--warning">La boutique n’est pas active dans ce contexte : ce bloc ne sera pas rendu.</p>
      <label class="native-field native-field--wide">Storytelling
        <select :value="Number(data.storytelling_id || 0)" @change="update('storytelling_id', Number(($event.target as HTMLSelectElement).value) || null)">
          <option value="0">Sélectionner…</option><option v-for="story in stories" :key="story.id" :value="story.id">{{ story.title }} ({{ story.storytelling_key }})</option>
        </select>
      </label>
      <p v-if="!stories.length && !loading" class="muted">Aucun storytelling publié pour ce site et cette langue.</p>
    </section>

    <template v-else>
      <section class="native-blueprint-section">
        <h3 class="native-blueprint-section__title">Sélection Commerce</h3>
        <p class="field-help">Les choix proviennent exclusivement de la projection de la boutique active pour ce site et cette langue.</p>
        <div v-if="isList" class="block-grid">
          <label class="native-field native-field--wide">Critère principal<select :value="mode" @change="update('selection_mode', ($event.target as HTMLSelectElement).value)"><option value="explicit">Produits choisis</option><option value="brand">Marque</option><option value="category">Catégorie</option><option value="group">Groupe</option><option value="attribute">Attribut public</option><option value="promotion">Promotion active</option><option value="new">Nouveautés</option><option value="popular">Popularité</option><option value="relation">Relation au produit courant</option></select></label>
          <label v-if="mode === 'brand'" class="native-field">Marque (ID ou slug)<input :value="String(data.brand || '')" @input="update('brand', ($event.target as HTMLInputElement).value)"></label>
          <label v-if="mode === 'category'" class="native-field">Catégorie (ID ou slug)<input :value="String(data.category || '')" @input="update('category', ($event.target as HTMLInputElement).value)"></label>
          <label v-if="mode === 'group'" class="native-field">Groupe (ID ou code)<input :value="String(data.group || '')" @input="update('group', ($event.target as HTMLInputElement).value)"></label>
          <template v-if="mode === 'attribute'"><label class="native-field">Code de l’attribut<input :value="String(data.attribute_code || '')" @input="update('attribute_code', ($event.target as HTMLInputElement).value)"></label><label class="native-field">Valeurs séparées par des virgules<input :value="Array.isArray(data.attribute_values) ? data.attribute_values.join(', ') : String(data.attribute_values || '')" @input="update('attribute_values', ($event.target as HTMLInputElement).value.split(',').map((v) => v.trim()).filter(Boolean))"></label></template>
          <template v-if="mode === 'relation'"><label class="native-field">Relation<select :value="String(data.relation_type || 'related')" @change="update('relation_type', ($event.target as HTMLSelectElement).value)"><option value="related">Produits liés</option><option value="alternative">Alternatives</option><option value="accessory">Accessoires</option><option value="upsell">Montée en gamme</option><option value="cross_sell">Vente croisée</option></select></label><label class="native-field">Produit source (optionnel)<input type="number" :value="Number(data.source_product_id || 0) || ''" @input="update('source_product_id', Number(($event.target as HTMLInputElement).value) || null)"></label></template>
          <label v-if="mode === 'popular'" class="native-field">Fenêtre (jours)<input type="number" min="1" max="365" :value="Number(data.window_days || 30)" @input="update('window_days', Number(($event.target as HTMLInputElement).value) || 30)"></label>
        </div>
        <div v-if="mode === 'explicit' || !isList" class="commerce-product-search">
          <label class="native-field native-field--wide">Rechercher par nom ou SKU<span class="commerce-search-line"><input v-model="query" placeholder="Nom ou SKU" @keydown.enter.prevent="search"><button type="button" :disabled="loading" @click="search">{{ loading ? 'Recherche…' : 'Rechercher' }}</button></span></label>
          <div v-if="candidates.length" class="commerce-candidates"><button v-for="candidate in candidates" :key="candidate.product_id" type="button" class="commerce-candidate" :class="{ selected: selected(candidate) }" @click="choose(candidate)"><img v-if="candidate.image" :src="candidate.image" alt="" width="48" height="48"><span v-else class="candidate-image">□</span><span><strong>{{ candidate.name }}</strong><small>{{ candidate.sku || 'Sans SKU' }} · {{ candidate.availability || 'Disponibilité inconnue' }}</small></span></button></div>
          <p v-else-if="!loading" class="muted">Aucun produit public dans la boutique active pour ce contexte.</p>
          <label v-if="isSingle && selectedProduct?.sellables?.length" class="native-field native-field--wide">Variante précise (optionnel)<select :value="Number(data.sellable_id || 0)" @change="update('sellable_id', Number(($event.target as HTMLSelectElement).value) || null)"><option value="0">Produit / variante par défaut</option><option v-for="sellable in selectedProduct.sellables" :key="sellable.sellable_id" :value="sellable.sellable_id">{{ sellable.name || sellable.sku || `Variante #${sellable.sellable_id}` }}</option></select></label>
        </div>
      </section>

      <section v-if="isList" class="native-blueprint-section"><h3 class="native-blueprint-section__title">Priorités manuelles</h3><input type="number" min="1" placeholder="ID produit — Entrée pour ajouter" @keydown.enter.prevent="addManual"><ol class="manual-list"><li v-for="(id, index) in manualIds" :key="id"><span>{{ candidateName(id) }}</span><button type="button" :disabled="index === 0" @click="moveManual(index, -1)">↑</button><button type="button" :disabled="index === manualIds.length - 1" @click="moveManual(index, 1)">↓</button><button type="button" @click="removeManual(id)">Retirer</button></li></ol></section>

      <section class="native-blueprint-section"><h3 class="native-blueprint-section__title">Affichage</h3><div class="block-grid"><label v-if="isPagedGrid" class="native-field">Éléments par page<input type="number" min="1" max="100" :value="Number(data.limit || 12)" @input="update('limit', Number(($event.target as HTMLInputElement).value) || 12)"></label><label v-if="isPagedGrid" class="native-field">Colonnes<input type="number" min="1" max="6" :value="Number(data.columns || 3)" @input="update('columns', Number(($event.target as HTMLInputElement).value) || 3)"></label><label class="checkline checkline--modal native-field"><input type="checkbox" :checked="data.show_price !== false" @change="update('show_price', ($event.target as HTMLInputElement).checked)"> Prix</label><label class="checkline checkline--modal native-field"><input type="checkbox" :checked="data.show_promotion !== false" @change="update('show_promotion', ($event.target as HTMLInputElement).checked)"> Promotion</label><label class="checkline checkline--modal native-field"><input type="checkbox" :checked="data.show_availability !== false" @change="update('show_availability', ($event.target as HTMLInputElement).checked)"> Disponibilité</label><label class="checkline checkline--modal native-field"><input type="checkbox" :checked="data.show_cta !== false" @change="update('show_cta', ($event.target as HTMLInputElement).checked)"> Actions produit</label><label v-if="isPagedGrid" class="checkline checkline--modal native-field"><input type="checkbox" :checked="Boolean(data.pagination)" @change="update('pagination', ($event.target as HTMLInputElement).checked)"> Pagination</label><label class="native-field">Libellé du bouton Voir (optionnel)<input :value="String(data.view_label || '')" @input="update('view_label', ($event.target as HTMLInputElement).value)"></label><label class="native-field">Libellé du bouton Ajouter au panier (optionnel)<input :value="String(data.cart_label || '')" @input="update('cart_label', ($event.target as HTMLInputElement).value)"></label><label class="native-field native-field--wide">Message vide<input :value="String(data.empty_message || 'Aucun produit à afficher.')" @input="update('empty_message', ($event.target as HTMLInputElement).value)"></label></div></section>
    </template>

    <p v-if="error" role="alert" class="notice notice--error">{{ error }}</p>
    <section class="native-blueprint-section commerce-preview"><div class="nested-panel__header"><h3 class="native-blueprint-section__title">Contrôle du rendu</h3><button type="button" :disabled="previewing" @click="refreshPreview">{{ previewing ? 'Calcul…' : 'Actualiser l’aperçu' }}</button></div><template v-if="preview"><p v-if="preview.commerce_available === false" class="notice notice--warning">Boutique inactive : le bloc sera masqué.</p><p v-else-if="!isStory"><strong>{{ Number(preview.selection?.total ?? preview.selection?.count ?? 0) }}</strong> résultat(s).</p><p v-else-if="preview.storytelling"><strong>{{ preview.storytelling.title }}</strong> est prêt à être affiché.</p><p v-if="preview.selection?.missing_product_ids?.length" class="notice notice--warning">Produits indisponibles : {{ preview.selection.missing_product_ids.join(', ') }}.</p></template><p v-else class="muted">Le contrôle utilise les mêmes projections que le rendu public.</p></section>
  </div>
</template>

<style scoped>
.commerce-search-line{display:flex;gap:.5rem}.commerce-search-line input{flex:1}.commerce-candidates{display:grid;gap:.5rem;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr));margin-top:.75rem}.commerce-candidate{align-items:center;background:var(--surface,#fff);border:1px solid var(--border,#ccd3dc);border-radius:.5rem;color:inherit;display:flex;gap:.65rem;padding:.55rem;text-align:left}.commerce-candidate.selected{border-color:var(--primary,#1463df);box-shadow:0 0 0 2px color-mix(in srgb,var(--primary,#1463df) 20%,transparent)}.commerce-candidate img,.candidate-image{border-radius:.35rem;height:48px;object-fit:cover;width:48px}.commerce-candidate span{display:grid}.commerce-candidate small{color:var(--muted,#667085)}.manual-list{display:grid;gap:.35rem;padding-left:1.4rem}.manual-list li{align-items:center;display:flex;gap:.35rem}.manual-list li span{flex:1}@media(max-width:640px){.commerce-search-line{align-items:stretch;flex-direction:column}.commerce-candidates{grid-template-columns:1fr}}
</style>
