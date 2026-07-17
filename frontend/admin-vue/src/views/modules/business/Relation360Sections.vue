<script setup lang="ts">
import { computed } from 'vue';
import RelationTimeline from './RelationTimeline.vue';
import { useI18n } from '@/i18n';

type Row = Record<string, any>;
const props = withDefaults(defineProps<{
  data?: Row | null;
  canAddMemo?: boolean;
  canManage?: boolean;
  canReadConsents?: boolean;
  canUseAdvancedTools?: boolean;
}>(), { data: null, canAddMemo: false, canManage: false, canReadConsents: false, canUseAdvancedTools: false });

defineEmits<{ addMemo: []; addTask: []; manageRoles: []; openConsents: []; openProfiles: [] }>();
const rows = (key: string): Row[] => Array.isArray(props.data?.[key]) ? props.data?.[key] : [];
const text = (value: unknown): string => String(value ?? '').replaceAll('_', ' ');
const { t } = useI18n();
const roleLabel = (value: unknown): string => t(`business.relation360.role.${String(value || 'other')}`);
const timeline = computed(() => rows('timeline').map((row: Row) => ({
  id: `${row.kind || 'activity'}-${row.id}`,
  kind: text(row.kind || 'activity'),
  title: String(row.summary || row.title || row.action || t('business.relation360.activity')),
  detail: String(row.metadata?.source_reference || row.metadata?.body_excerpt || ''),
  date: row.created_at || row.occurred_at || null,
  action: row.action,
  channel: row.metadata?.channel,
  groupKey: row.metadata?.source_reference,
})));
</script>

<template>
  <section class="relation-360" aria-label="Relation 360">
    <div v-if="data?.projection_health?.degraded" class="alert alert-warning" role="status">
      {{ t('business.relation360.degraded') }}
    </div>
    <div v-for="(alert, index) in rows('alerts')" :key="index" :class="['relation-alert', `relation-alert--${alert.level || 'info'}`]">
      {{ alert.message }}
    </div>

    <nav class="relation-anchors" :aria-label="t('business.relation360.sectionsLabel')">
      <a href="#relation-overview">{{ t('business.relation360.overview') }}</a><a href="#relation-timeline">{{ t('business.relation360.timeline') }}</a>
      <a href="#relation-orders">{{ t('business.relation360.orders') }}</a><a href="#relation-financial">{{ t('business.relation360.financial') }}</a>
      <a href="#relation-fulfillments">{{ t('business.relation360.fulfillments') }}</a><a href="#relation-forms">{{ t('business.relation360.forms') }}</a>
      <a href="#relation-consents">{{ t('business.relation360.consents') }}</a>
    </nav>

    <section id="relation-overview" class="relation-section">
      <header><h3>{{ t('business.relation360.overview') }}</h3></header>
      <dl class="overview-grid">
        <div><dt>{{ t('business.relation360.roles') }}</dt><dd>{{ (data?.relation?.roles || []).map((role: Row) => roleLabel(role.role_key)).join(', ') || '—' }}</dd></div>
        <div><dt>{{ t('business.relation360.nextAction') }}</dt><dd>{{ data?.next_action?.title || '—' }}<small v-if="data?.next_action?.due_at">{{ data.next_action.due_at }}</small></dd></div>
        <div><dt>{{ t('business.relation360.responsible') }}</dt><dd>{{ data?.relation?.responsible_iam_user_id ? `#${data.relation.responsible_iam_user_id}` : '—' }}</dd></div>
        <div><dt>{{ t('business.relation360.linkedAccount') }}</dt><dd>{{ data?.relation?.identity_linked ? t('business.relation360.yes') : '—' }}</dd></div>
      </dl>
      <div><button v-if="canManage" class="btn small" type="button" @click="$emit('addTask')">{{ t('business.relation360.planAction') }}</button> <button v-if="canManage" class="btn ghost small" type="button" @click="$emit('manageRoles')">{{ t('business.relation360.manageRoles') }}</button> <button v-if="canUseAdvancedTools" class="btn ghost small" type="button" @click="$emit('openProfiles')">{{ t('business.relation360.profileMatching') }}</button></div>
    </section>

    <section id="relation-timeline" class="relation-section">
      <RelationTimeline
        :title="t('business.relation360.timeline')"
        :empty-label="t('business.relation360.noActivity')"
        :items="timeline"
        :can-add-memo="canAddMemo"
        :action-label="t('business.relation360.addMemo')"
        @add-memo="$emit('addMemo')"
      />
      <details v-if="canUseAdvancedTools && rows('timeline').length" class="technical-details">
        <summary>{{ t('business.relation360.technical') }}</summary><pre>{{ JSON.stringify(rows('timeline'), null, 2) }}</pre>
      </details>
    </section>

    <section id="relation-orders" class="relation-section">
      <header><h3>{{ t('business.relation360.orders') }}</h3><span>{{ rows('orders').length }}</span></header>
      <p v-if="!rows('orders').length" class="empty-section">{{ t('business.relation360.noOrders') }}</p>
      <router-link v-for="row in rows('orders')" :key="row.id" :to="row.owner_link" class="projection-row"><strong>{{ row.reference }}</strong><span>{{ row.summary }}</span><small>{{ text(row.source) }} · {{ text(row.status) }}</small></router-link>
    </section>

    <section id="relation-financial" class="relation-section">
      <header><h3>{{ t('business.relation360.financial') }}</h3><span>{{ rows('financial').length }}</span></header>
      <p v-if="!rows('financial').length" class="empty-section">{{ t('business.relation360.noFinancial') }}</p>
      <router-link v-for="row in rows('financial')" :key="row.id" :to="row.owner_link" class="projection-row"><strong>{{ row.reference }}</strong><span>{{ row.summary }}</span><small>{{ text(row.status) }}</small></router-link>
    </section>

    <section id="relation-fulfillments" class="relation-section">
      <header><h3>{{ t('business.relation360.fulfillments') }}</h3><span>{{ rows('fulfillments').length }}</span></header>
      <p v-if="!rows('fulfillments').length" class="empty-section">{{ t('business.relation360.noFulfillments') }}</p>
      <router-link v-for="row in rows('fulfillments')" :key="row.id" :to="row.owner_link" class="projection-row"><strong>{{ row.reference }}</strong><span>{{ row.summary }}</span><small>{{ text(row.status) }}</small></router-link>
    </section>

    <section id="relation-forms" class="relation-section">
      <header><h3>{{ t('business.relation360.forms') }}</h3><span>{{ rows('forms').length }}</span></header>
      <p v-if="!rows('forms').length" class="empty-section">{{ t('business.relation360.noForms') }}</p>
      <router-link v-for="row in rows('forms')" :key="row.id" :to="{ path: '/forms', query: { form_id: row.form_id, submission_id: row.submission_id } }" class="projection-row"><strong>{{ row.form_name || row.form_key }}</strong><span>{{ row.safe_summary }}</span><small>{{ row.occurred_at }} · {{ text(row.resolution_strategy) }}</small></router-link>
    </section>

    <section id="relation-consents" class="relation-section">
      <header><h3>{{ t('business.relation360.consents') }}</h3></header>
      <button v-if="canReadConsents && data?.consents_available" class="btn ghost small" type="button" @click="$emit('openConsents')">{{ t('business.relation360.viewConsents') }}</button>
      <p v-else class="empty-section">{{ t('business.relation360.consentsUnavailable') }}</p>
    </section>
  </section>
</template>

<style scoped>
.relation-360{display:grid;gap:.8rem}.relation-anchors{position:sticky;top:0;z-index:2;display:flex;gap:.35rem;overflow:auto;background:#fff;padding:.5rem 0}.relation-anchors a{white-space:nowrap;border:1px solid #d0d5dd;border-radius:999px;padding:.3rem .6rem;color:#344054;text-decoration:none;font-size:.82rem}.relation-section{scroll-margin-top:4rem;border:1px solid #e4e7ec;border-radius:8px;padding:.85rem;display:grid;gap:.65rem}.relation-section>header{display:flex;justify-content:space-between;align-items:center}.relation-section h3{margin:0;font-size:1rem}.overview-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.6rem;margin:0}.overview-grid div{background:#f8fafc;padding:.6rem;border-radius:6px}.overview-grid dt{font-size:.75rem;color:#667085}.overview-grid dd{margin:0;font-weight:600}.overview-grid small{display:block;font-weight:400}.projection-row{display:grid;grid-template-columns:minmax(7rem,.5fr) 1fr auto;gap:.6rem;border-top:1px solid #eaecf0;padding:.55rem 0;color:inherit;text-decoration:none}.projection-row small,.empty-section{color:#667085}.relation-alert{border-left:4px solid #60a5fa;background:#eff6ff;padding:.65rem}.relation-alert--warning{border-color:#f59e0b;background:#fffbeb}.technical-details pre{max-height:18rem;overflow:auto;font-size:.72rem}.technical-details summary{cursor:pointer}@media(max-width:700px){.overview-grid{grid-template-columns:1fr}.projection-row{grid-template-columns:1fr}.relation-anchors{margin-inline:-.85rem;padding-inline:.85rem}}
</style>
