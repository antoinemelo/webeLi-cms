<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
type AuditLog={id:number;actor_email?:string;action_key:string;resource_type?:string;resource_id?:number;ip_address:string;created_at:string};
const logs=ref<AuditLog[]>([]); const error=ref(''); const action=ref('');
const rows=computed(()=>logs.value.map(l=>({id:l.id,created_at:l.created_at,actor:l.actor_email||'Système',action_key:l.action_key,resource:`${l.resource_type||'—'} ${l.resource_id||''}`,ip:l.ip_address||'—'})));
async function load(){error.value='';try{const res=await adminApi.get<{audit_logs:AuditLog[]}>('/iam/audit-logs',{action_key:action.value,limit:100});logs.value=res.data.audit_logs;}catch(e){error.value=apiErrorMessage(e,'Journal indisponible.')}};
onMounted(load);
</script><template><PageHeader title="Journal d’audit" intro="Traçabilité des actions sensibles : utilisateurs, rôles, sessions, sécurité et authentification."/><ApiFeedback :error="error"/><section class="card mb-3"><div class="d-flex gap-2"><input v-model="action" class="form-control" placeholder="Filtrer par action_key"><button class="btn btn-secondary" @click="load">Filtrer</button></div></section><DataTable :columns="[{key:'created_at',label:'Date'},{key:'actor',label:'Acteur'},{key:'action_key',label:'Action'},{key:'resource',label:'Ressource'},{key:'ip',label:'IP'}]" :rows="rows"/></template>
