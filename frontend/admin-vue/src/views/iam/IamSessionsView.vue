<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import DataTable from '@/components/ui/DataTable.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
type SessionRow = { id:number; user_id:number; email:string; ip_address:string; user_agent:string; last_seen_at?:string; expires_at:string; is_active:boolean };
const sessions=ref<SessionRow[]>([]); const error=ref(''); const message=ref(''); const status=ref('active');
const rows=computed(()=>sessions.value.map(s=>({id:s.id,email:s.email,ip:s.ip_address||'—',last_seen_at:s.last_seen_at||'—',expires_at:s.expires_at,status:s.is_active?'Active':'Expirée'})));
async function load(){error.value=''; try{const res=await adminApi.get<{sessions:SessionRow[]}>('/iam/sessions',{status:status.value,limit:100}); sessions.value=res.data.sessions;}catch(e){error.value=apiErrorMessage(e,'Sessions indisponibles.')}};
async function revoke(id:number){error.value='';message.value='';try{await adminApi.delete(`/iam/sessions/${id}`);message.value='Session révoquée.';await load();}catch(e){error.value=apiErrorMessage(e,'Révocation impossible.')}};
onMounted(load);
</script><template><PageHeader title="Sessions" intro="Contrôle des sessions persistées, stockage par hash uniquement et révocation immédiate."/><ApiFeedback :error="error" :message="message"/><section class="card mb-3"><label class="form-label">Statut</label><select v-model="status" class="form-select" @change="load"><option value="active">Actives</option><option value="expired">Expirées</option><option value="">Toutes</option></select></section><DataTable :columns="[{key:'email',label:'Utilisateur'},{key:'ip',label:'IP'},{key:'last_seen_at',label:'Dernière activité'},{key:'expires_at',label:'Expiration'},{key:'status',label:'Statut'},{key:'actions',label:'Actions'}]" :rows="rows"><template #cell-actions="{row}"><button class="btn btn-sm btn-outline-danger" @click="revoke(Number(row.id))">Révoquer</button></template></DataTable></template>
