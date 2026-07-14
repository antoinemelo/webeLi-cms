<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import { useI18n } from '@/i18n';
import { useAdminContextStore } from '@/stores/adminContext';

type Criterion = { key: string; label: string; operators: string[]; values?: Array<string | boolean> };
type Segment = {
  id: number; name: string; segment_kind: 'calculated' | 'manual'; criterion?: string | null; operator?: string | null;
  value?: unknown; explanation?: string; rule_version: number; result_count: number; last_calculated_at?: string | null; retention_days: number;
};
type Preview = { count: number; examples: Array<{ reference: string; explanation: string }>; explanation: string };

const context = useAdminContextStore();
const { languageCode, t, dateTime } = useI18n();
const canManage = computed(() => context.can('business.segment.manage'));
const segments = ref<Segment[]>([]);
const criteria = ref<Criterion[]>([]);
const preview = ref<Preview | null>(null);
const loading = ref(false);
const busy = ref('');
const error = ref('');
const success = ref('');
const form = reactive({ name: '', segment_kind: 'calculated' as 'calculated' | 'manual', criterion: '', operator: '', value: '', retention_days: 730 });

const selectedCriterion = computed(() => criteria.value.find((item) => item.key === form.criterion));
const operators = computed(() => selectedCriterion.value?.operators || []);
const valueOptions = computed(() => selectedCriterion.value?.values || []);
const criterionTranslations: Record<string, [string, string]> = {
  customer_type: ['Type de client', 'Customer type'], last_purchase_at: ['Dernier achat', 'Last purchase'],
  order_count: ['Nombre de commandes', 'Order count'], total_spent_minor: ['Montant cumulé', 'Total spent'],
  primary_channel: ['Canal principal', 'Primary channel'], product_id: ['Produit acheté', 'Purchased product'],
  category_id: ['Catégorie achetée', 'Purchased category'], has_return: ['Retour ou remboursement', 'Return or refund'],
  gift_card_status: ['Bon cadeau', 'Gift card'],
};
const operatorTranslations: Record<string, [string, string]> = {
  eq: ['est égal à', 'equals'], neq: ['est différent de', 'does not equal'], gt: ['est supérieur à', 'is greater than'],
  gte: ['est au moins', 'is at least'], lt: ['est inférieur à', 'is less than'], lte: ['est au plus', 'is at most'],
  contains: ['contient', 'contains'], not_contains: ['ne contient pas', 'does not contain'], within_days: ['dans les derniers jours', 'within the last days'],
  before: ['avant', 'before'], after: ['après', 'after'],
};

function localized(pair?: [string, string]): string { return pair?.[languageCode.value === 'en' ? 1 : 0] || ''; }
function criterionLabel(item: Criterion): string { return localized(criterionTranslations[item.key]) || item.label; }
function operatorLabel(value: string): string { return localized(operatorTranslations[value]) || value; }
function valueLabel(value: string | boolean): string {
  const labels: Record<string, [string, string]> = { new: ['Nouveau', 'New'], recurring: ['Récurrent', 'Recurring'], issued: ['Émis', 'Issued'], redeemed: ['Utilisé', 'Redeemed'], any: ['Tous', 'Any'], true: ['Oui', 'Yes'], false: ['Non', 'No'] };
  return localized(labels[String(value)]) || String(value);
}

async function load(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    const response = await adminApi.get<{ segments: Segment[]; criteria: Criterion[] }>('/business/segments');
    segments.value = response.data.segments || [];
    criteria.value = response.data.criteria || [];
  } catch (err) { error.value = apiErrorMessage(err, 'Segments indisponibles.'); }
  finally { loading.value = false; }
}

function rulePayload(): Record<string, unknown> {
  return { criterion: form.criterion, operator: form.operator, value: form.value };
}

function validRule(): boolean {
  if (form.segment_kind === 'manual') return true;
  if (!form.criterion || !form.operator || form.value === '') {
    error.value = t('business.segments.emptyRule');
    return false;
  }
  return true;
}

async function runPreview(): Promise<void> {
  error.value = ''; preview.value = null;
  if (!validRule()) return;
  busy.value = 'preview';
  try {
    const response = await adminApi.post<{ preview: Preview }>('/business/segments/preview', rulePayload());
    preview.value = response.data.preview;
  } catch (err) { error.value = apiErrorMessage(err, t('business.segments.emptyRule')); }
  finally { busy.value = ''; }
}

async function createSegment(): Promise<void> {
  error.value = ''; success.value = '';
  if (!form.name.trim()) { error.value = t('business.segments.name'); return; }
  if (!validRule()) return;
  busy.value = 'create';
  try {
    const payload = { name: form.name, segment_kind: form.segment_kind, retention_days: form.retention_days, ...(form.segment_kind === 'calculated' ? rulePayload() : {}) };
    const response = await adminApi.post<{ segment: Segment }>('/business/segments', payload);
    if (form.segment_kind === 'calculated') await adminApi.post(`/business/segments/${response.data.segment.id}/recalculate`, { mode: 'full' });
    form.name = ''; preview.value = null;
    await load();
    success.value = t('business.segments.create');
  } catch (err) { error.value = apiErrorMessage(err, 'Création du segment impossible.'); }
  finally { busy.value = ''; }
}

async function recalculate(segment: Segment, mode: 'full' | 'incremental'): Promise<void> {
  busy.value = `recalculate.${segment.id}`; error.value = ''; success.value = '';
  try {
    await adminApi.post(`/business/segments/${segment.id}/recalculate`, { mode });
    await load();
    success.value = t('business.segments.recalculate');
  } catch (err) { error.value = apiErrorMessage(err, 'Recalcul impossible.'); }
  finally { busy.value = ''; }
}

watch(() => form.criterion, () => { form.operator = ''; form.value = ''; preview.value = null; });
watch(() => context.siteId, () => void load());
onMounted(load);
</script>

<template>
  <section class="segments-panel" data-testid="crm-segments-panel">
    <header><div><h2>{{ t('business.segments.title') }}</h2><p>{{ t('business.segments.intro') }}</p></div></header>
    <ApiFeedback :success="success" />
    <p v-if="error" class="segment-error" role="alert">{{ error }}</p>

    <form v-if="canManage" class="segment-builder" data-testid="segment-builder" @submit.prevent="createSegment">
      <label>{{ t('business.segments.name') }}<input v-model="form.name" type="text" maxlength="120" required></label>
      <label>{{ t('business.segments.kind') }}<select v-model="form.segment_kind"><option value="calculated">{{ t('business.segments.calculated') }}</option><option value="manual">{{ t('business.segments.manual') }}</option></select></label>
      <template v-if="form.segment_kind === 'calculated'">
        <label>{{ t('business.segments.criterion') }}<select v-model="form.criterion"><option value="">—</option><option v-for="item in criteria" :key="item.key" :value="item.key">{{ criterionLabel(item) }}</option></select></label>
        <label>{{ t('business.segments.operator') }}<select v-model="form.operator" :disabled="!form.criterion"><option value="">—</option><option v-for="item in operators" :key="item" :value="item">{{ operatorLabel(item) }}</option></select></label>
        <label>{{ t('business.segments.value') }}
          <select v-if="valueOptions.length" v-model="form.value"><option value="">—</option><option v-for="item in valueOptions" :key="String(item)" :value="String(item)">{{ valueLabel(item) }}</option></select>
          <input v-else v-model="form.value" :type="form.criterion.includes('date') ? 'date' : 'text'">
        </label>
      </template>
      <label>{{ t('business.segments.retentionDays') }}<input v-model.number="form.retention_days" type="number" min="30" max="3650"></label>
      <details><summary>{{ t('business.segments.advanced') }}</summary><p>{{ t('business.segments.sourceProjection') }}</p></details>
      <div class="segment-actions"><button v-if="form.segment_kind === 'calculated'" class="btn" type="button" :disabled="busy === 'preview'" @click="runPreview">{{ t('business.segments.preview') }}</button><button class="btn primary" type="submit" :disabled="busy === 'create'">{{ t('business.segments.create') }}</button></div>
    </form>

    <section v-if="preview" class="segment-preview" aria-live="polite" data-testid="segment-preview">
      <strong>{{ preview.count ? t('business.segments.previewCount', { count: preview.count }) : t('business.segments.zeroResult') }}</strong>
      <p>{{ preview.explanation }}</p>
      <ul v-if="preview.examples.length"><li v-for="item in preview.examples" :key="item.reference"><b>{{ item.reference }}</b> · {{ item.explanation }}</li></ul>
    </section>

    <p v-if="loading">{{ t('business.segments.loading') }}</p>
    <p v-else-if="!segments.length" class="segment-empty">{{ t('business.segments.noSegments') }}</p>
    <div v-else class="segment-list">
      <article v-for="segment in segments" :key="segment.id" data-testid="segment-card">
        <header><div><span>{{ segment.segment_kind === 'manual' ? t('business.segments.manual') : t('business.segments.calculated') }}</span><h3>{{ segment.name }}</h3></div><strong>{{ segment.result_count }} {{ t('business.segments.members') }}</strong></header>
        <p>{{ segment.explanation }}</p>
        <dl><div><dt>{{ t('business.segments.ruleVersion') }}</dt><dd>{{ segment.rule_version }}</dd></div><div><dt>{{ t('business.segments.lastCalculated') }}</dt><dd>{{ segment.last_calculated_at ? dateTime(segment.last_calculated_at) : t('business.segments.never') }}</dd></div></dl>
        <div v-if="canManage && segment.segment_kind === 'calculated'" class="segment-actions"><button class="btn small" type="button" :disabled="busy === `recalculate.${segment.id}`" @click="recalculate(segment, 'incremental')">{{ t('business.segments.incremental') }}</button><button class="btn small" type="button" :disabled="busy === `recalculate.${segment.id}`" @click="recalculate(segment, 'full')">{{ t('business.segments.full') }}</button></div>
      </article>
    </div>
  </section>
</template>

<style scoped>
.segments-panel { display: grid; gap: 1rem; }
.segments-panel > header h2, .segments-panel > header p, .segment-list h3, .segment-list p { margin: 0; }
.segments-panel > header p { color: #667085; margin-top: .3rem; }
.segment-builder { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; border: 1px solid #e4e7ec; border-radius: 10px; padding: 1rem; background: #fff; }
.segment-builder label { display: grid; gap: .3rem; color: #344054; font-size: .85rem; }
.segment-builder input, .segment-builder select { min-height: 2.4rem; border: 1px solid #d0d5dd; border-radius: 7px; padding: .4rem .55rem; }
.segment-builder details { grid-column: 1 / -1; color: #667085; }
.segment-actions { display: flex; gap: .5rem; justify-content: flex-end; align-items: end; grid-column: 1 / -1; }
.segment-preview { border-left: 4px solid #2e90fa; background: #eff8ff; border-radius: 7px; padding: .8rem; }
.segment-preview p { margin: .3rem 0; }
.segment-error { margin: 0; border-left: 4px solid #d92d20; background: #fef3f2; color: #912018; border-radius: 7px; padding: .8rem; }
.segment-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; }
.segment-list article { border: 1px solid #e4e7ec; border-radius: 10px; padding: .9rem; background: #fff; }
.segment-list article > header { display: flex; justify-content: space-between; gap: .75rem; }
.segment-list article span { color: #667085; font-size: .75rem; text-transform: uppercase; }
.segment-list dl { display: flex; gap: 1.2rem; color: #475467; }
.segment-list dl div { display: grid; gap: .15rem; }
.segment-list dt { font-size: .75rem; }.segment-list dd { margin: 0; }
.segment-empty { padding: 1rem; text-align: center; color: #667085; border: 1px dashed #d0d5dd; border-radius: 8px; }
@media (max-width: 780px) { .segment-builder, .segment-list { grid-template-columns: 1fr; } }
</style>
