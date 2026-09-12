<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { adminApi, apiErrorMessage } from '@/api/client';
import { useAdminContextStore } from '@/stores/adminContext';
import PageHeader from '@/components/ui/PageHeader.vue';
import { useI18n } from '@/i18n';

type Option = { key?: string; channel_id?: number; code?: string; name: string; currency?: string; status?: string; is_public?: boolean; location?: string };
type Section = { key: string; enabled: boolean; title: string; limit: number; display: 'grid'|'carousel'|'list'|'chips'; rule: string; empty_state: 'hide'|'message'; empty_message: string; view_all_label: string; window_days: number; manual_product_ids: number[]; manual_ids?: string };
type SectionPreview = { key: string; count: number; explanation: string; fallback_used?: boolean; items?: Array<{name?:string;keyword?:string}> };
type ShopConfiguration = {
  site_id: number; language_code: string; status: string; channel_id?: number|null; currency: string; theme_key: string;
  menu_key: string; menu_label: string; menu_position: number; cart_visible: boolean; show_quantities: boolean;
  last_available_threshold: number; draft: { title?: string; introduction?: string; seo?: { title?: string; description?: string; robots?: string }; sections?: Section[]; analytics?: {dedupe_minutes?:number;retention_days?:number} };
  published_version?: number|null; config_version: number; is_initialized: boolean; preview?: { sections: SectionPreview[]; total_products: number };
  channels: Option[]; themes: Option[]; menu_locations: Option[]; permissions: { edit: boolean; publish: boolean; activate: boolean };
};

const route = useRoute(); const router = useRouter(); const context = useAdminContextStore();
const { t } = useI18n();
const siteId = computed(() => Number(route.query.site_id || context.siteId || 0));
const locale = computed(() => String(route.query.language_code || context.languageCode || 'fr').toLowerCase());
const loading = ref(true); const saving = ref(false); const publishing = ref(false); const error = ref(''); const message = ref('');
const source = ref<ShopConfiguration|null>(null);
const previewing = ref(false);
const previewMode = ref<'desktop'|'mobile'>('desktop');
const draggedIndex = ref<number|null>(null);
const expandedSectionKeys = ref<Set<string>>(new Set());
const form = reactive({ channel_id: 0, currency: 'CHF', theme_key: 'default', menu_key: 'main', menu_label: 'Boutique', menu_position: 100, cart_visible: true, show_quantities: false, last_available_threshold: 1, title: 'Boutique', introduction: '', seo_title: 'Boutique', seo_description: '', seo_robots: 'index,follow', analytics:{dedupe_minutes:30,retention_days:90}, sections: [] as Section[] });
const dirty = ref(false);
const english = computed(()=>locale.value.startsWith('en'));
const ui = computed(()=>english.value ? {
  restore:'Restore defaults',limit:'Limit',display:'Display',rule:'Selection rule',empty:'When empty',hide:'Hide the section',message:'Show a message',emptyMessage:'Empty-state message',viewAll:'“View all” label',window:'Analysis window (days)',manual:'Manual product IDs (optional)',desktop:'Desktop',mobile:'Mobile',fallback:'Deterministic fallback used',noResult:'This section is currently empty.',analytics:'Audience measurement',dedupe:'Refresh deduplication (minutes)',retention:'Aggregate retention (days)',drag:'Drag to reorder',enabled:'Section enabled',expand:'Show settings for',collapse:'Hide settings for'
} : {
  restore:'Restaurer les valeurs par défaut',limit:'Limite',display:'Affichage',rule:'Règle de sélection',empty:'En l’absence de résultat',hide:'Masquer la section',message:'Afficher un message',emptyMessage:'Message d’état vide',viewAll:'Libellé « Tout voir »',window:'Fenêtre d’analyse (jours)',manual:'ID produits manuels (facultatif)',desktop:'Ordinateur',mobile:'Mobile',fallback:'Repli déterministe utilisé',noResult:'Cette section est actuellement vide.',analytics:'Mesure d’audience',dedupe:'Déduplication des rafraîchissements (minutes)',retention:'Conservation des agrégats (jours)',drag:'Glisser pour réordonner',enabled:'Section activée',expand:'Afficher les paramètres de',collapse:'Masquer les paramètres de'
});

async function load(): Promise<void> {
  loading.value = true; error.value = '';
  try {
    const response = await adminApi.get<ShopConfiguration>(`/sale/ecommerce/shops/${siteId.value}/${locale.value}`);
    source.value = response.data; const d = response.data.draft || {};
    const availableMenus = response.data.menu_locations || [];
    const storedMenu = response.data.menu_key || 'main';
    const resolvedMenu = availableMenus.some(menu=>menu.key===storedMenu) ? storedMenu : (storedMenu==='main'&&availableMenus.some(menu=>menu.key==='primary')?'primary':storedMenu);
    Object.assign(form,{ channel_id:Number(response.data.channel_id||0),currency:response.data.currency||'CHF',theme_key:response.data.theme_key||'default',menu_key:resolvedMenu,menu_label:response.data.menu_label||'Boutique',menu_position:Number(response.data.menu_position||100),cart_visible:Boolean(response.data.cart_visible),show_quantities:Boolean(response.data.show_quantities),last_available_threshold:Number(response.data.last_available_threshold||1),title:d.title||'Boutique',introduction:d.introduction||'',seo_title:d.seo?.title||d.title||'Boutique',seo_description:d.seo?.description||'',seo_robots:d.seo?.robots||'index,follow',analytics:{dedupe_minutes:Number(d.analytics?.dedupe_minutes||30),retention_days:Number(d.analytics?.retention_days||90)},sections:(d.sections||[]).map(section=>({...section,manual_ids:(section.manual_product_ids||[]).join(', ')})) });
    dirty.value = false;
  } catch (err) { error.value = apiErrorMessage(err,t('shopSystem.loadError')); }
  finally { loading.value = false; }
}

function payload() { return { channel_id:form.channel_id,currency:form.currency,theme_key:form.theme_key,menu_key:form.menu_key,menu_label:form.menu_label,menu_position:form.menu_position,cart_visible:form.cart_visible,show_quantities:form.show_quantities,last_available_threshold:form.last_available_threshold,title:form.title,introduction:form.introduction,seo:{title:form.seo_title,description:form.seo_description,robots:form.seo_robots},analytics:form.analytics,sections:form.sections.map(({manual_ids,...section})=>({...section,manual_product_ids:String(manual_ids||'').split(',').map(value=>Number(value.trim())).filter(value=>Number.isInteger(value)&&value>0)})) }; }
async function save(): Promise<void> { saving.value=true; error.value=''; message.value=''; try { await adminApi.put(`/sale/ecommerce/shops/${siteId.value}/${locale.value}`,payload()); message.value=t('shopSystem.saveSuccess'); await load(); } catch(err){ error.value=apiErrorMessage(err,t('shopSystem.saveError')); } finally { saving.value=false; } }
async function publish(): Promise<void> { if(dirty.value) await save(); publishing.value=true; error.value=''; message.value=''; try { await adminApi.post(`/sale/ecommerce/shops/${siteId.value}/${locale.value}/publish`,{}); message.value=t('shopSystem.publishSuccess'); await load(); } catch(err){ error.value=apiErrorMessage(err,t('shopSystem.publishError')); } finally { publishing.value=false; } }
function move(index:number,delta:number){ const target=index+delta;if(target<0||target>=form.sections.length)return;const item=form.sections.splice(index,1)[0];form.sections.splice(target,0,item);dirty.value=true; }
function startDrag(index:number,event:DragEvent){ draggedIndex.value=index;if(event.dataTransfer){event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',String(index));} }
function drop(index:number){ const sourceIndex=draggedIndex.value;draggedIndex.value=null;if(sourceIndex===null||sourceIndex===index)return;const item=form.sections.splice(sourceIndex,1)[0];form.sections.splice(index,0,item);dirty.value=true; }
function isSectionExpanded(key:string){ return expandedSectionKeys.value.has(key); }
function toggleSection(key:string){ const next=new Set(expandedSectionKeys.value);if(next.has(key))next.delete(key);else next.add(key);expandedSectionKeys.value=next; }
function previewFor(key:string){ return source.value?.preview?.sections.find(section=>section.key===key); }
function restoreDefaults(){
  const en=english.value; const empty=en?'Nothing to show yet.':'Aucun élément à afficher pour le moment.'; const all=en?'View all':'Tout voir';
  const definitions:[string,string,number,Section['display'],string,Section['empty_state'],string][]=[
    ['search',en?'Search, filters and sorting':'Recherche, filtres et tri',24,'list','automatic','hide',''],['groups',en?'Product groups':'Groupes de produits',8,'chips','automatic','hide',all],['promotions',en?'Best offers':'Meilleures promotions',6,'grid','percent','hide',all],['collections',en?'Explore categories':'Explorer les catégories',8,'grid','automatic','hide',all],['popular',en?'Popular products':'Produits populaires',6,'grid','views','hide',all],['keywords',en?'Popular keywords':'Mots-clés populaires',8,'chips','automatic','hide',''],['new',en?'New products':'Nouveaux produits',6,'grid','first_published','hide',all],['catalog',en?'All products':'Tous les produits',24,'grid','automatic','message','']];
  form.sections=definitions.map(([key,title,limit,display,rule,empty_state,view_all_label])=>({key,title,limit,display,rule,empty_state,view_all_label,enabled:true,empty_message:empty,window_days:30,manual_product_ids:[],manual_ids:''}));
  form.analytics={dedupe_minutes:30,retention_days:90}; expandedSectionKeys.value=new Set(); dirty.value=true;
}
function markDirty(){ dirty.value=true; }
function back(){ router.push('/contents/pages'); }
onMounted(load);
</script>

<template>
  <section class="shop-system-editor">
    <PageHeader :title="t('shopSystem.title')" :intro="t('shopSystem.intro')">
      <template #actions><button class="btn" type="button" @click="back">{{ t('shopSystem.back') }}</button><button class="btn" type="button" @click="previewing=!previewing">{{ previewing?t('shopSystem.previewClose'):t('shopSystem.preview') }}</button><button class="btn" type="button" :disabled="saving||!source?.permissions.edit" @click="save">{{ saving?t('shopSystem.saving'):t('shopSystem.save') }}</button><button class="btn primary" type="button" :disabled="publishing||!source?.permissions.publish" @click="publish">{{ publishing?t('shopSystem.publishing'):t('shopSystem.publish') }}</button></template>
    </PageHeader>
    <div v-if="loading" role="status" class="card">{{ t('shopSystem.loading') }}</div>
    <div v-if="error" role="alert" class="alert alert-danger">{{ error }}</div>
    <div v-if="message" role="status" class="alert alert-success">{{ message }}</div>
    <aside v-if="previewing" class="card shop-system-preview" :class="`shop-system-preview--${previewMode}`" :aria-label="t('shopSystem.previewLabel')">
      <div class="preview-toolbar"><p class="eyebrow">{{ t('shopSystem.previewLabel') }}</p><div><button type="button" class="btn btn-sm" :aria-pressed="previewMode==='desktop'" @click="previewMode='desktop'">{{ ui.desktop }}</button><button type="button" class="btn btn-sm" :aria-pressed="previewMode==='mobile'" @click="previewMode='mobile'">{{ ui.mobile }}</button></div></div>
      <h2>{{ form.title }}</h2>
      <p v-if="form.introduction">{{ form.introduction }}</p>
      <ol class="preview-sections"><li v-for="section in form.sections.filter(item=>item.enabled)" :key="section.key"><strong>{{ section.title || section.key }}</strong><span>{{ previewFor(section.key)?.count || 0 }} / {{ section.limit }}</span><small>{{ previewFor(section.key)?.explanation }}</small><small v-if="previewFor(section.key)?.fallback_used">{{ ui.fallback }}</small><em v-if="!previewFor(section.key)?.count">{{ section.empty_state==='hide'?ui.noResult:section.empty_message }}</em></li></ol>
    </aside>
    <form v-if="!loading&&source" class="shop-system-grid" @input="markDirty" @change="markDirty" @submit.prevent="save">
      <section class="card shop-system-card">
        <div class="shop-system-heading"><div><p class="eyebrow">{{ t('shopSystem.badge') }}</p><h2>{{ t('shopSystem.editorial') }}</h2></div><span class="badge text-bg-secondary">{{ t(`commerce.status.${source.status}`) }}</span></div>
        <label class="field"><span>{{ t('shopSystem.field.title') }}</span><input v-model="form.title" class="input" required maxlength="160"></label>
        <label class="field"><span>{{ t('shopSystem.field.introduction') }}</span><textarea v-model="form.introduction" class="input" rows="4" maxlength="2000"></textarea></label>
        <div class="locked-fields"><div><span>{{ t('shopSystem.locked.type') }}</span><strong>{{ t('shopSystem.locked.systemPage') }}</strong><small>{{ t('shopSystem.locked.typeHelp') }}</small></div><div><span>{{ t('shopSystem.locked.route') }}</span><strong>/shop</strong><small>{{ t('shopSystem.locked.routeHelp') }}</small></div><div><span>{{ t('shopSystem.locked.scope') }}</span><strong>#{{ siteId }} · {{ locale.toUpperCase() }}</strong><small>{{ t('shopSystem.locked.scopeHelp') }}</small></div></div>
      </section>
      <section class="card shop-system-card">
        <h2>{{ t('shopSystem.seo') }}</h2>
        <label class="field"><span>{{ t('shopSystem.field.seoTitle') }}</span><input v-model="form.seo_title" class="input" maxlength="180"></label>
        <label class="field"><span>{{ t('shopSystem.field.seoDescription') }}</span><textarea v-model="form.seo_description" class="input" rows="3" maxlength="320"></textarea></label>
        <label class="field"><span>{{ t('shopSystem.field.robots') }}</span><select v-model="form.seo_robots" class="select"><option value="index,follow">{{ t('shopSystem.robots.index') }}</option><option value="noindex,follow">{{ t('shopSystem.robots.noindexFollow') }}</option><option value="noindex,nofollow">{{ t('shopSystem.robots.noindex') }}</option></select></label>
      </section>
      <section class="card shop-system-card shop-system-sections">
        <div class="shop-system-heading"><div><h2>{{ t('shopSystem.sections') }}</h2><p class="muted">{{ t('shopSystem.sectionsHelp') }}</p></div><button class="btn btn-sm" type="button" @click="restoreDefaults">{{ ui.restore }}</button></div>
        <article v-for="(section,index) in form.sections" :key="section.key" class="shop-section-row" :class="{'shop-section-row--disabled':!section.enabled,'shop-section-row--dragging':draggedIndex===index}" @dragover.prevent @drop.prevent="drop(index)">
          <div class="shop-section-row__summary">
            <button class="dnd-handle" type="button" draggable="true" :title="ui.drag" :aria-label="`${ui.drag} ${section.title||section.key}`" @dragstart.stop="startDrag(index,$event)" @dragend="draggedIndex=null" @keydown.up.prevent="move(index,-1)" @keydown.down.prevent="move(index,1)"><span aria-hidden="true">⋮</span></button>
            <label class="shop-section-row__identity"><input v-model="section.enabled" type="checkbox" :aria-label="`${ui.enabled} ${section.title||section.key}`"><span><strong>{{ section.title||section.key }}</strong><small>{{ section.key }}</small></span></label>
            <button class="shop-section-disclosure" type="button" :aria-expanded="isSectionExpanded(section.key)" :aria-controls="`shop-section-details-${section.key}`" :aria-label="`${isSectionExpanded(section.key)?ui.collapse:ui.expand} ${section.title||section.key}`" @click="toggleSection(section.key)">
              <svg :class="{'shop-section-disclosure__icon--open':isSectionExpanded(section.key)}" class="shop-section-disclosure__icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path fill-rule="evenodd" d="M4.646 1.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1 0 .708l-6 6a.5.5 0 0 1-.708-.708L10.293 8 4.646 2.354a.5.5 0 0 1 0-.708"/></svg>
            </button>
          </div>
          <div v-show="isSectionExpanded(section.key)" :id="`shop-section-details-${section.key}`" class="shop-section-row__details">
            <label class="field wide"><span>{{ t('shopSystem.sectionTitle') }}</span><input v-model="section.title" class="input"></label>
            <label class="field"><span>{{ ui.limit }}</span><input v-model.number="section.limit" class="input" type="number" min="1" max="24"></label>
            <label class="field"><span>{{ ui.display }}</span><select v-model="section.display" class="select"><option value="grid">Grid</option><option value="carousel">Carousel</option><option value="list">List</option><option value="chips">Chips</option></select></label>
            <label v-if="section.key==='promotions'" class="field"><span>{{ ui.rule }}</span><select v-model="section.rule" class="select"><option value="percent">%</option><option value="amount">Montant</option></select></label>
            <label v-if="['popular','keywords'].includes(section.key)" class="field"><span>{{ ui.window }}</span><input v-model.number="section.window_days" class="input" type="number" min="1" max="365"></label>
            <label class="field"><span>{{ ui.empty }}</span><select v-model="section.empty_state" class="select"><option value="hide">{{ ui.hide }}</option><option value="message">{{ ui.message }}</option></select></label>
            <label v-if="section.empty_state==='message'" class="field wide"><span>{{ ui.emptyMessage }}</span><input v-model="section.empty_message" class="input" maxlength="240"></label>
            <label class="field"><span>{{ ui.viewAll }}</span><input v-model="section.view_all_label" class="input" maxlength="80"></label>
            <label v-if="['promotions','popular','new'].includes(section.key)" class="field wide"><span>{{ ui.manual }}</span><input v-model="section.manual_ids" class="input" inputmode="numeric"></label>
            <p class="section-explanation wide"><strong>{{ previewFor(section.key)?.count || 0 }} / {{ section.limit }}</strong> {{ previewFor(section.key)?.explanation || ui.noResult }}</p>
            <div class="shop-section-row__order wide"><button class="btn btn-sm" type="button" :disabled="index===0" @click="move(index,-1)">↑ {{ t('shopSystem.moveUp') }}</button><button class="btn btn-sm" type="button" :disabled="index===form.sections.length-1" @click="move(index,1)">↓ {{ t('shopSystem.moveDown') }}</button></div>
          </div>
        </article>
        <fieldset class="analytics-settings"><legend>{{ ui.analytics }}</legend><label class="field"><span>{{ ui.dedupe }}</span><input v-model.number="form.analytics.dedupe_minutes" class="input" type="number" min="1" max="1440"></label><label class="field"><span>{{ ui.retention }}</span><input v-model.number="form.analytics.retention_days" class="input" type="number" min="7" max="365"></label></fieldset>
      </section>
      <section class="card shop-system-card">
        <h2>{{ t('shopSystem.channelDisplay') }}</h2>
        <label class="field"><span>{{ t('shopSystem.field.channel') }}</span><select v-model.number="form.channel_id" class="select" required><option :value="0" disabled>{{ t('shopSystem.select') }}</option><option v-for="channel in source.channels" :key="channel.channel_id" :value="channel.channel_id">{{ channel.name }} · {{ channel.code }} · {{ channel.currency }}{{ channel.status!=='active'||!channel.is_public?` — ${t('shopSystem.notPublishable')}`:'' }}</option></select></label>
        <label class="field"><span>{{ t('shopSystem.field.currency') }}</span><input v-model="form.currency" class="input" required pattern="[A-Z]{3}" maxlength="3"></label>
        <label class="field"><span>{{ t('shopSystem.field.theme') }}</span><select v-model="form.theme_key" class="select"><option v-for="theme in source.themes" :key="theme.key" :value="theme.key">{{ theme.name }}</option></select></label>
        <label class="field"><span>{{ t('shopSystem.field.menu') }}</span><select v-model="form.menu_key" class="select"><option v-for="menu in source.menu_locations" :key="menu.key" :value="menu.key">{{ menu.name }} · {{ menu.location }}</option></select></label>
        <label class="field"><span>{{ t('shopSystem.field.menuLabel') }}</span><input v-model="form.menu_label" class="input" maxlength="80"></label>
        <label class="field"><span>{{ t('shopSystem.field.position') }}</span><input v-model.number="form.menu_position" class="input" type="number" min="0" max="1000"></label>
        <label class="check"><input v-model="form.cart_visible" type="checkbox"> {{ t('shopSystem.field.cartVisible') }}</label>
        <label class="field"><span>{{ t('shopSystem.field.threshold') }}</span><input v-model.number="form.last_available_threshold" class="input" type="number" min="0" max="9999"></label>
      </section>
    </form>
  </section>
</template>

<style scoped>
.shop-system-editor{display:grid;gap:1rem}.shop-system-grid{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(18rem,.75fr);gap:1rem;align-items:start}.shop-system-card{display:grid;gap:.85rem;padding:1rem}.shop-system-heading{display:flex;justify-content:space-between;gap:1rem;align-items:start}.shop-system-card h2{font-size:1.05rem;margin:0}.shop-system-preview{border:2px solid #93c5fd;display:grid;gap:.5rem;padding:1.25rem}.shop-system-preview--mobile{max-width:28rem}.shop-system-preview h2,.shop-system-preview p,.shop-system-preview ol{margin:0}.preview-toolbar,.preview-toolbar>div{display:flex;align-items:center;justify-content:space-between;gap:.5rem}.preview-sections{display:grid;gap:.6rem;padding-left:1.25rem}.preview-sections li{display:grid;grid-template-columns:1fr auto;gap:.15rem .75rem}.preview-sections small,.preview-sections em{grid-column:1/-1}.field{display:grid;gap:.3rem}.check{display:flex;gap:.5rem;align-items:center}.locked-fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.6rem}.locked-fields>div{background:#f1f5f9;border:1px solid #dbe2ea;border-radius:.65rem;display:grid;gap:.15rem;padding:.7rem}.locked-fields span,.locked-fields small{color:#64748b;font-size:.78rem}.shop-system-sections{grid-column:1}.shop-section-row{border:1px solid #dbe2ea;border-radius:.7rem;background:#fff;overflow:hidden;transition:border-color .15s ease,box-shadow .15s ease,opacity .15s ease}.shop-section-row--disabled{background:#f8fafc;opacity:.76}.shop-section-row--dragging{border-color:#60a5fa;box-shadow:0 0 0 3px rgba(59,130,246,.12)}.shop-section-row__summary{display:grid;grid-template-columns:2.4rem minmax(0,1fr) 2.4rem;align-items:center;min-height:3.6rem;padding:.35rem .45rem}.shop-section-disclosure{display:grid;place-items:center;width:2.25rem;height:2.25rem;border:0;border-radius:.45rem;background:transparent;color:#64748b}.shop-section-disclosure:hover,.shop-section-disclosure:focus-visible{background:#eaf2fb;color:#1d4ed8;outline:none}.shop-section-row__identity{display:flex;align-items:center;gap:.7rem;min-width:0;padding:.25rem .35rem;cursor:pointer}.shop-section-row__identity>span{display:grid;gap:.05rem;min-width:0}.shop-section-row__identity strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.shop-section-row__identity small{color:#64748b;font-size:.75rem}.shop-section-disclosure__icon{width:1rem;height:1rem;fill:currentColor;transition:transform .16s ease}.shop-section-disclosure__icon--open{transform:rotate(90deg)}.shop-section-row__details{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.7rem;align-items:end;padding:.9rem;border-top:1px solid #e2e8f0;background:#fbfdff}.shop-section-row__order{display:flex;justify-content:flex-end;gap:.5rem}.wide{grid-column:1/-1}.section-explanation{margin:0;padding:.55rem;background:#f1f5f9;border-radius:.5rem;color:#475569;font-size:.85rem}.analytics-settings{display:grid;grid-template-columns:1fr 1fr;gap:.7rem;border:1px solid #e2e8f0;border-radius:.65rem;padding:.8rem}.eyebrow{color:#64748b;font-size:.75rem;font-weight:700;letter-spacing:.06em;margin:0;text-transform:uppercase}@media(max-width:850px){.shop-system-grid{grid-template-columns:1fr}.shop-system-sections{grid-column:auto}.locked-fields,.shop-section-row__details,.analytics-settings{grid-template-columns:1fr}.shop-system-heading{align-items:stretch;flex-direction:column}.shop-system-heading .btn{align-self:flex-start}.shop-section-row__order{justify-content:flex-start}}
</style>
