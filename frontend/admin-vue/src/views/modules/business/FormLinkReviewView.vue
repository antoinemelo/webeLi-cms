<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { adminApi, apiErrorMessage } from '@/api/client';

type Row = Record<string, any>;
const props = withDefaults(defineProps<{ relationType?: string; relationId?: number }>(), { relationType: '', relationId: 0 });
const items = ref<Row[]>([]);
const loading = ref(false);
const error = ref('');
const notice = ref('');

async function load(): Promise<void> {
  loading.value = true; error.value = '';
  try { const response = await adminApi.get<{ items: Row[] }>('/business/form-links/pending', { limit: 100 }); items.value = response.data.items || []; }
  catch (err) { error.value = apiErrorMessage(err); }
  finally { loading.value = false; }
}

async function decide(row: Row, decision: 'link' | 'unlink' | 'postpone'): Promise<void> {
  const reason = window.prompt('Motif obligatoire de la décision');
  if (!reason?.trim()) return;
  let relationType = props.relationType;
  let relationId = props.relationId;
  if (decision === 'link' && (!['contact', 'company'].includes(relationType) || relationId < 1)) {
    relationType = window.prompt('Type de relation : contact ou company', 'contact') || '';
    relationId = Number(window.prompt('Identifiant de la relation') || 0);
  }
  loading.value = true;
  try {
    await adminApi.post(`/business/form-links/${row.id}/decision`, { decision, reason: reason.trim(), relation_type: relationType || undefined, relation_id: relationId || undefined });
    notice.value = decision === 'link' ? 'Soumission rattachée.' : decision === 'unlink' ? 'Soumission laissée sans relation.' : 'Décision reportée.';
    await load();
  } catch (err) { error.value = apiErrorMessage(err); loading.value = false; }
}

onMounted(load);
</script>

<template>
  <section class="form-link-review">
    <header><div><p>Opérations · Formulaires</p><h2>Rattachements à vérifier</h2><span>Les noms, e-mails non vérifiés et téléphones non normalisés ne sont jamais liés automatiquement.</span></div><button class="btn btn-outline-primary" type="button" :disabled="loading" @click="load">Actualiser</button></header>
    <div v-if="notice" class="alert alert-success">{{ notice }}</div><div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>
    <p v-if="loading" class="alert alert-info">Chargement…</p><p v-else-if="!items.length" class="empty">Aucun rattachement de formulaire à vérifier.</p>
    <article v-for="row in items" :key="row.id"><div><strong>{{ row.form_name || row.form_key }}</strong><span>{{ row.safe_summary }} · {{ row.occurred_at }}</span><small>{{ row.candidate_count }} candidat(s) · provenance conservée</small></div><footer><button class="btn btn-primary" type="button" @click="decide(row, 'link')">Rattacher</button><button class="btn btn-outline-danger" type="button" @click="decide(row, 'unlink')">Ne pas rattacher</button><button class="btn btn-outline-secondary" type="button" @click="decide(row, 'postpone')">Reporter</button></footer></article>
  </section>
</template>

<style scoped>
.form-link-review{display:grid;gap:1rem;margin-top:1rem}.form-link-review>header,.form-link-review article,.form-link-review article footer{display:flex;justify-content:space-between;gap:1rem}.form-link-review>header p{margin:0;color:#6366f1;font-size:.75rem;font-weight:700;text-transform:uppercase}.form-link-review article{align-items:center;border:1px solid #e2e8f0;background:#fff;border-radius:.8rem;padding:1rem}.form-link-review article>div{display:grid}.form-link-review article span,.form-link-review article small{color:#64748b}.empty{text-align:center;padding:1.5rem}@media(max-width:700px){.form-link-review>header,.form-link-review article{display:grid}.form-link-review article footer{flex-wrap:wrap}.form-link-review article footer button{flex:1}}
</style>
