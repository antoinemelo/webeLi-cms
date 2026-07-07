<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import { useAdminContextStore } from '@/stores/adminContext';
import BusinessCatalogView from './BusinessCatalogView.vue';
import BusinessRelationsView from './BusinessRelationsView.vue';
import RelationHeader from './business/RelationHeader.vue';
import RelationInfoCard from './business/RelationInfoCard.vue';
import RelationLinkedEntities from './business/RelationLinkedEntities.vue';
import RelationTimeline from './business/RelationTimeline.vue';
import MemoCard from './business/MemoCard.vue';
import MessageCard from './business/MessageCard.vue';
import ProviderStatusBadge from './business/ProviderStatusBadge.vue';
import QuickCreateMemoDrawer from './business/QuickCreateMemoDrawer.vue';
import QuickCreateRelationDrawer from './business/QuickCreateRelationDrawer.vue';
import SendMessageDrawer from './business/SendMessageDrawer.vue';

type BusinessTab = 'dashboard' | 'relations' | 'messages' | 'products' | 'offers' | 'settings';
type LegacyBusinessTab = 'companies' | 'contacts' | 'memos' | 'mailing' | 'messaging';
type RelationsPanel = 'main' | 'memos';
type BusinessModal = '' | 'relation' | 'memo' | 'import' | 'export' | 'message' | 'consent' | 'archive';
type RelationModalMode = 'create' | 'view' | 'edit';
type RelationMemoFilter = { type: 'contact' | 'company'; id: number; display_name: string };
type IdValue = number | string | null | undefined;
type Company = Record<string, unknown> & { id: number; name: string; status?: string; email?: string | null; phone?: string | null; website_url?: string | null; notes?: string | null; is_system?: boolean };
type Contact = Record<string, unknown> & { id: number; company_id?: number; company_name?: string; display_name: string; status?: string; email?: string | null; phone?: string | null; mobile?: string | null; iam_user_id?: number | null };
type BusinessRelation = Record<string, unknown> & { type: 'contact' | 'company'; id: number; display_name: string; primary_email?: string | null; phone?: string | null; mobile?: string | null; status?: string; memo_count?: number; shared_memo_count?: number; linked_contacts_count?: number; last_activity_at?: string | null; last_memo_excerpt?: string | null; company?: { id?: number; name?: string; is_system_individuals?: boolean } | null };
type Memo = Record<string, unknown> & { id: number; company_id?: number | null; contact_id?: number | null; title: string; body?: string; visibility?: string; created_at?: string };
type MemoShare = Record<string, unknown> & { id: number; share_type?: string; public_label?: string | null; shared_with_iam_user_id?: number | null; expires_at?: string | null; revoked_at?: string | null };
type MemoComment = Record<string, unknown> & { id: number; body: string; created_at?: string };
type Consent = Record<string, unknown> & { channel: string; consent_status?: string };
type ChannelRow = Record<string, unknown> & { channel: string; channel_value?: string; is_primary?: boolean; is_verified?: boolean };
type MailingList = Record<string, unknown> & { id: number; name: string; list_key?: string; channel?: string; status?: string; description?: string };
type MailingMember = Record<string, unknown> & { contact_id: number; display_name?: string; email?: string | null; mobile?: string | null; status?: string };
type Campaign = Record<string, unknown> & { id: number; name: string; list_id?: number | null; channel?: string; status?: string; subject?: string | null; body_text?: string };
type MessageRow = Record<string, unknown> & { id: number; channel?: string; recipient_value?: string; subject?: string | null; status?: string; last_error?: string | null; created_at?: string; sent_at?: string | null; updated_at?: string | null; contact_name?: string | null; company_name?: string | null; event_count?: number };
type MessagingProvider = Record<string, unknown> & { id?: number; key?: string; provider_key?: string; name?: string; channel?: string; provider_type?: string; config?: Record<string, unknown>; secret_ref?: string | null; is_enabled?: boolean; is_default?: boolean; enabled?: boolean; errors?: string[] };
type MessagePreview = Record<string, unknown> & { channel?: string; recipient_value?: string | null; has_channel?: boolean; consent_status?: string; has_consent?: boolean; can_send?: boolean; reason?: string | null };
type IamUserOption = { id: number; email: string; name?: string; is_active?: boolean };
type BusinessActivity = { id: number; kind: string; action: string; summary: string; created_at?: string | null; metadata?: Record<string, unknown> };
type DashboardAlert = Record<string, unknown> & { level?: string; code?: string; message?: string; channel?: string; count?: number };
type DashboardSearchItem = Record<string, unknown> & { group?: string; type?: string; id: number; title?: string; subtitle?: string | null; excerpt?: string | null; status?: string | null; company_id?: number | null; contact_id?: number | null };
type ProviderDraft = { id: number; provider_key: string; name: string; channel: string; provider_type: string; config_json: string; secret_ref: string; is_enabled: boolean; is_default: boolean };
type BusinessDashboard = {
  counters: Record<string, number>;
  recent_relations: BusinessRelation[];
  latest_memos: Memo[];
  latest_messages: MessageRow[];
  alerts: DashboardAlert[];
};

const props = withDefaults(defineProps<{
  initialTab?: BusinessTab | LegacyBusinessTab;
}>(), {
  initialTab: 'dashboard',
});

const context = useAdminContextStore();
const normalizeBusinessTab = (tab?: BusinessTab | LegacyBusinessTab): BusinessTab => {
  if (tab === 'dashboard') return 'dashboard';
  if (tab === 'products' || tab === 'offers' || tab === 'settings') return tab;
  if (tab === 'mailing') return 'messages';
  if (tab === 'messaging') return 'messages';
  return 'relations';
};
const activeTab = ref<BusinessTab>(normalizeBusinessTab(props.initialTab));
const relationsPanel = ref<RelationsPanel>('main');
const loading = ref(false);
const busy = ref('');
const error = ref('');
const success = ref('');
const oneTimeShareUrl = ref('');
const contactImportFile = ref<File | null>(null);
const contactImportInput = ref<HTMLInputElement | null>(null);
const contactImportReport = ref<Record<string, unknown> | null>(null);
const catalogRefreshKey = ref(0);
const relationsView = ref<{ reload: () => Promise<void> } | null>(null);
const activeModal = ref<BusinessModal>('');
const modalPanel = ref<HTMLElement | null>(null);
const relationModalMode = ref<RelationModalMode>('create');
const relationDraftType = ref<'contact' | 'company'>('contact');
const messageRelation = ref<BusinessRelation | null>(null);
const archiveRelation = ref<BusinessRelation | null>(null);
const memoCommentEdit = reactive({ id: 0, body: '' });
const relationDetail = ref<BusinessRelation | null>(null);
const relationMemoFilter = ref<RelationMemoFilter | null>(null);
const linkedRelationContacts = ref<BusinessRelation[]>([]);
const iamUsers = ref<IamUserOption[]>([]);
const relationActivity = ref<BusinessActivity[]>([]);
const dashboard = ref<BusinessDashboard | null>(null);
const dashboardSearch = reactive({ q: '' });
const dashboardSearchResults = ref<DashboardSearchItem[]>([]);

const canCrmRead = computed(() => context.can('business.crm.read'));
const canCrmManage = computed(() => context.can('business.crm.manage'));
const canMemoRead = computed(() => context.can('business.memo.read'));
const canMemoManage = computed(() => context.can('business.memo.manage'));
const canMemoShare = computed(() => context.can('business.memo.share'));
const canMailingRead = computed(() => context.can('business.mailing.read'));
const canMailingManage = computed(() => context.can('business.mailing.manage'));
const canMessagingSend = computed(() => context.can('business.messaging.send'));
const canMessagingAdmin = computed(() => context.can('business.messaging.admin'));
const canCatalogRead = computed(() => context.can('business.catalog.read'));
const canMessagesAccess = computed(() => canMailingRead.value || canMessagingAdmin.value);

const tabs: Array<{ key: BusinessTab; label: string; permission: () => boolean }> = [
  { key: 'dashboard', label: 'Tableau de bord', permission: () => canCrmRead.value },
  { key: 'relations', label: 'Relations', permission: () => canCrmRead.value || canMemoRead.value },
  { key: 'messages', label: 'Messages', permission: () => canMessagesAccess.value },
  { key: 'products', label: 'Produits', permission: () => canCatalogRead.value },
  { key: 'offers', label: 'Offres', permission: () => canCatalogRead.value },
  { key: 'settings', label: 'Réglages', permission: () => canMessagingAdmin.value },
];

const crmStatuses = ['prospect', 'client', 'supplier', 'former_client', 'other'];
const channels = ['email', 'whatsapp', 'telegram'];
const consentStatuses = ['unknown', 'opt_in', 'opt_out'];
const memoVisibilities = ['private', 'internal', 'public_link'];
const messageStatuses = ['', 'pending', 'queued', 'sent', 'failed', 'cancelled', 'skipped'];
const providerTypes = ['null', 'smtp', 'whatsapp_cloud', 'telegram_bot', 'webhook', 'custom'];

const companies = ref<Company[]>([]);
const contacts = ref<Contact[]>([]);
const memos = ref<Memo[]>([]);
const tags = ref<Array<Record<string, unknown>>>([]);
const memoComments = ref<MemoComment[]>([]);
const memoShares = ref<MemoShare[]>([]);
const contactChannels = ref<ChannelRow[]>([]);
const contactConsents = ref<Consent[]>([]);
const mailingLists = ref<MailingList[]>([]);
const mailingMembers = ref<MailingMember[]>([]);
const campaigns = ref<Campaign[]>([]);
const campaignPreview = ref<Array<Record<string, unknown>>>([]);
const messagingProviders = ref<{ runtime: MessagingProvider[]; configured: MessagingProvider[] }>({ runtime: [], configured: [] });
const outbox = ref<MessageRow[]>([]);
const relationMessages = ref<MessageRow[]>([]);
const messagePreview = ref<MessagePreview | null>(null);
const messagePreviewProviders = ref<{ runtime: MessagingProvider[]; configured: MessagingProvider[] }>({ runtime: [], configured: [] });
const providerDrafts = reactive<Record<string, ProviderDraft>>({});
const shareExpiryDrafts = reactive<Record<number, string>>({});
const shareLabelDrafts = reactive<Record<number, string>>({});
let previousBodyOverflow = '';

const companyFilter = reactive({ q: '', status: '', archived: false });
const contactFilter = reactive({ q: '', status: '', company_id: '' });
const contactImportOptions = reactive({ create_companies: false, update_existing: false });
const memoFilter = reactive({ q: '', company_id: '', contact_id: '' });
const messageFilter = reactive({ status: 'sent', channel: '', q: '' });
const relationsToolbar = reactive({ q: '', status: '', sort: 'name_asc' });
const quickRelation = reactive({ inline_company_name: '', tags: '' });
const quickMemo = reactive({ public_share: false });

const companyForm = reactive({ id: 0, name: '', status: 'prospect', email: '', phone: '', website_url: '', notes: '' });
const contactForm = reactive({ id: 0, company_id: '', iam_user_id: '', first_name: '', last_name: '', display_name: '', status: 'prospect', preferred_language: '', email: '', phone: '', mobile: '', job_title: '', notes: '' });
const memoForm = reactive({ id: 0, company_id: '', contact_id: '', title: '', body: '', visibility: 'private', comment: '', share_iam_user_ids: '', public_label: '', public_expires_at: '' });
const consentForm = reactive<Record<string, { value: string; consent_status: string; evidence: string; is_verified: boolean }>>({});
const mailingListForm = reactive({ id: 0, name: '', list_key: '', channel: 'email', description: '', status: 'active', member_contact_id: '' });
const campaignForm = reactive({ id: 0, list_id: '', name: '', channel: 'email', subject: '', body_text: '', scheduled_at: '' });
const messageTestForm = reactive({ channel: 'email', provider_key: 'email', recipient_value: 'runtime@example.test', subject: 'Test Business messaging', body_text: 'Message de test Business.', confirm_external_test: false });

const selectedCompany = computed(() => companies.value.find((company) => company.id === companyForm.id) || null);
const selectedContact = computed(() => contacts.value.find((contact) => contact.id === contactForm.id) || null);
const selectedMemo = computed(() => memos.value.find((memo) => memo.id === memoForm.id) || null);
const selectedList = computed(() => mailingLists.value.find((list) => list.id === mailingListForm.id) || null);
const selectedCampaign = computed(() => campaigns.value.find((campaign) => campaign.id === campaignForm.id) || null);
const isSystemCompanyForm = computed(() => relationDraftType.value === 'company' && selectedCompany.value?.is_system === true);
const canEditCurrentRelation = computed(() => canCrmManage.value && !isSystemCompanyForm.value);
const editableCompanies = computed(() => companies.value.filter((company) => company.is_system !== true));
const contactLinkedCompany = computed(() => {
  const companyId = Number(contactForm.company_id || 0);
  if (!companyId) return [];
  const company = companies.value.find((item) => item.id === companyId);
  if (company?.is_system) return [];
  return [{ id: companyId, label: companyName(companyId), detail: 'Organisation' }];
});
const linkedContacts = computed(() => selectedCompany.value ? contacts.value.filter((contact) => Number(contact.company_id || 0) === selectedCompany.value?.id) : []);
const linkedCompanyMemos = computed(() => selectedCompany.value ? memos.value.filter((memo) => Number(memo.company_id || 0) === selectedCompany.value?.id) : []);
const linkedContactMemos = computed(() => selectedContact.value ? memos.value.filter((memo) => Number(memo.contact_id || 0) === selectedContact.value?.id) : []);
const relationMessageProviders = computed(() => [...messagePreviewProviders.value.configured, ...messagePreviewProviders.value.runtime]);
const relationMessageChannelRows = computed(() => channels.map((channel) => {
  const channelRow = contactChannels.value.find((row) => row.channel === channel);
  const consent = contactConsents.value.find((row) => row.channel === channel);
  return {
    channel,
    value: valueText(channelRow?.channel_value),
    consent_status: valueText(consent?.consent_status || 'unknown'),
    has_channel: Boolean(channelRow?.channel_value),
    can_send: Boolean(channelRow?.channel_value) && consent?.consent_status === 'opt_in',
  };
}));
const selectedMessageChannel = computed(() => relationMessageChannelRows.value.find((row) => row.channel === messageTestForm.channel) || relationMessageChannelRows.value[0]);
const canSendRelationMessage = computed(() => Boolean(messageRelation.value && messagePreview.value?.can_send === true && messageTestForm.body_text.trim() !== ''));
const assignedIamUserIds = computed(() => new Set(contacts.value.map((contact) => Number(contact.iam_user_id || 0)).filter((id) => id > 0 && id !== Number(contactForm.iam_user_id || 0))));
const availableIamUsers = computed(() => iamUsers.value.filter((user) => !assignedIamUserIds.value.has(user.id)));
function iamUserLabel(id: unknown): string {
  const userId = Number(id || 0);
  if (!userId) return '—';
  const user = iamUsers.value.find((item) => item.id === userId);
  return user ? (user.name || user.email || `Utilisateur #${userId}`) : `Utilisateur #${userId}`;
}

function auditDate(value: unknown): string {
  return valueText(value) || '—';
}

const relationHeaderContext = computed(() => {
  if (relationDraftType.value === 'company') {
    const count = Number(relationDetail.value?.linked_contacts_count ?? linkedRelationContacts.value.length);
    return selectedCompany.value?.is_system ? 'Entreprise système Individus' : `${count} contact(s) lié(s)`;
  }
  return companyName(contactForm.company_id);
});
const relationInfoRows = computed(() => relationDraftType.value === 'company'
  ? [
      { label: 'Nom', value: companyForm.name },
      { label: 'Statut', value: statusLabel(companyForm.status) },
      { label: 'Site web', value: companyForm.website_url },
      { label: 'Notes', value: companyForm.notes },
    ]
  : [
      { label: 'Nom affiché', value: contactForm.display_name },
      { label: 'Fonction', value: contactForm.job_title },
      { label: 'Langue', value: contactForm.preferred_language },
      { label: 'Utilisateur IAM', value: contactForm.iam_user_id ? `#${contactForm.iam_user_id}` : '' },
    ]);
const relationContactRows = computed(() => relationDraftType.value === 'company'
  ? [
      { label: 'Email', value: companyForm.email },
      { label: 'Téléphone', value: companyForm.phone },
    ]
  : [
      { label: 'Email', value: contactForm.email },
      { label: 'Téléphone', value: contactForm.phone },
      { label: 'Mobile', value: contactForm.mobile },
    ]);
const relationAuditRows = computed(() => {
  const current = relationDraftType.value === 'company' ? selectedCompany.value : selectedContact.value;
  return [
    { label: 'Créé par', value: iamUserLabel(current?.created_by_iam_user_id) },
    { label: 'Créé le', value: auditDate(current?.created_at) },
    { label: 'Modifié par', value: iamUserLabel(current?.updated_by_iam_user_id) },
    { label: 'Modifié le', value: auditDate(current?.updated_at) },
  ];
});
const relationTimelineItems = computed(() => {
  const items = relationActivity.value.length
    ? relationActivity.value.map((item) => ({
        id: `${item.kind}-${item.id}`,
        kind: activityKindLabel(item.kind, item.action),
        title: item.summary,
        detail: activityDetail(item),
        date: item.created_at || null,
      }))
    : (relationDraftType.value === 'company' ? linkedCompanyMemos.value : linkedContactMemos.value)
        .map((memo) => ({ id: `memo-${memo.id}`, kind: 'Mémo', title: memo.title, detail: valueText(memo.body), date: memo.created_at || null }));
  return items.slice(0, 7);
});
const linkedRelationEntities = computed(() => linkedRelationContacts.value.map((relation) => ({
  id: relation.id,
  label: relation.display_name,
  detail: [relation.primary_email, relation.mobile || relation.phone].filter(Boolean).join(' · '),
  memo_count: relation.memo_count,
  shared_memo_count: relation.shared_memo_count,
})));
const currentRelation = computed<BusinessRelation>(() => relationDetail.value || (relationDraftType.value === 'company'
  ? {
      type: 'company',
      id: Number(companyForm.id || 0),
      display_name: companyForm.name,
      status: companyForm.status,
      primary_email: companyForm.email,
      phone: companyForm.phone,
      memo_count: linkedCompanyMemos.value.length,
      shared_memo_count: 0,
      linked_contacts_count: linkedRelationContacts.value.length,
    }
  : {
      type: 'contact',
      id: Number(contactForm.id || 0),
      display_name: contactForm.display_name,
      status: contactForm.status,
      primary_email: contactForm.email,
      phone: contactForm.phone,
      mobile: contactForm.mobile,
      memo_count: linkedContactMemos.value.length,
      shared_memo_count: 0,
      company: { id: Number(contactForm.company_id || 0), name: companyName(contactForm.company_id) },
    }));
const companiesExportHref = computed(() => adminApi.href('/business/companies/export.csv', {
  q: companyFilter.q,
  status: companyFilter.status,
  archived: companyFilter.archived ? 'all' : undefined,
}));
const contactsExportHref = computed(() => adminApi.href('/business/contacts/export.csv', {
  q: contactFilter.q,
  status: contactFilter.status,
  company_id: contactFilter.company_id || undefined,
}));
const contactImportRows = computed(() => Array.isArray(contactImportReport.value?.rows) ? contactImportReport.value.rows as Array<Record<string, unknown>> : []);
const contactImportErrors = computed(() => Array.isArray(contactImportReport.value?.errors) ? contactImportReport.value.errors as Array<Record<string, unknown>> : []);

function setNotice(message: string): void {
  success.value = message;
  error.value = '';
}

function setError(err: unknown, fallback = 'Action Business impossible.'): void {
  error.value = apiErrorMessage(err, fallback);
  success.value = '';
}

function intOrNull(value: IdValue): number | null {
  const number = Number(value || 0);
  return Number.isFinite(number) && number > 0 ? number : null;
}

function valueText(value: unknown): string {
  return value === null || value === undefined ? '' : String(value);
}

function toDateTimeLocal(value: unknown): string {
  const text = valueText(value).trim();
  if (!text) return '';
  return text.replace(' ', 'T').slice(0, 16);
}

function fromDateTimeLocal(value: unknown): string {
  const text = valueText(value).trim();
  if (!text) return '';
  const normalized = text.replace('T', ' ');
  return normalized.length === 16 ? `${normalized}:00` : normalized;
}

function companyName(id: IdValue): string {
  const company = companies.value.find((item) => item.id === Number(id || 0));
  return company?.name || (id ? `Entreprise #${id}` : 'Individus');
}

function contactName(id: IdValue): string {
  const contact = contacts.value.find((item) => item.id === Number(id || 0));
  return contact?.display_name || (id ? `Contact #${id}` : '');
}

function statusLabel(value: unknown): string {
  return String(value || 'draft').replace(/_/g, ' ');
}

function messageContext(message: MessageRow): string {
  return [valueText(message.contact_name), valueText(message.company_name)].filter(Boolean).join(' · ') || '—';
}

function messageDate(message: MessageRow): string {
  return valueText(message.sent_at || message.updated_at || message.created_at) || '—';
}

function shareStatusLabel(share: MemoShare): string {
  if (share.revoked_at) return `révoqué le ${share.revoked_at}`;
  if (share.expires_at) return `expire le ${share.expires_at}`;
  return 'actif sans expiration';
}

function shareTypeLabel(share: MemoShare): string {
  return share.share_type === 'public_link' ? 'Lien public' : 'Partage interne';
}

function shareMainLabel(share: MemoShare): string {
  if (share.share_type === 'public_link') return valueText(share.public_label) || `Lien #${share.id}`;
  return iamUserLabel(share.shared_with_iam_user_id) || `Utilisateur #${share.shared_with_iam_user_id || share.id}`;
}

function shareDetailLabel(share: MemoShare): string {
  if (share.share_type === 'public_link') return 'URL masquée après création. Créez un nouveau lien si vous devez recopier l’adresse.';
  return `Utilisateur IAM #${share.shared_with_iam_user_id || '—'}`;
}

function providerKey(provider: MessagingProvider): string {
  return String(provider.provider_key || provider.key || '');
}

function messagePreviewNotice(): string {
  const preview = messagePreview.value;
  if (!preview) return 'Prévisualisation du canal en cours.';
  if (preview.can_send) return `Prêt à envoyer vers ${valueText(preview.recipient_value)}.`;
  if (preview.reason === 'business.messaging_channel_missing') return 'Aucun destinataire n’est disponible pour ce canal.';
  if (preview.reason === 'business.messaging_consent_required') return 'Consentement requis ou révoqué pour ce canal.';
  return 'Ce canal n’est pas prêt pour l’envoi.';
}

function activityKindLabel(kind: string, action: string): string {
  if (kind === 'memo') return 'Mémo';
  if (kind === 'comment') return 'Commentaire';
  if (kind === 'share') return 'Partage';
  if (kind === 'message') return 'Message';
  if (action.includes('archived')) return 'Archivage';
  if (action.includes('updated')) return 'Modification';
  if (action.includes('created')) return 'Création';
  return 'Activité';
}

function activityDetail(item: BusinessActivity): string {
  const metadata = item.metadata || {};
  const bits = [
    typeof metadata.channel === 'string' ? metadata.channel : '',
    typeof metadata.status === 'string' ? metadata.status : '',
    typeof metadata.body_excerpt === 'string' ? metadata.body_excerpt : '',
    typeof metadata.comment_excerpt === 'string' ? metadata.comment_excerpt : '',
    typeof metadata.share_type === 'string' ? metadata.share_type : '',
  ].filter(Boolean);
  return bits.join(' · ');
}

function publicUrl(path: string): string {
  const basePath = (window.__AMCMS_ADMIN__?.siteBasePath || window.__AMCMS_ADMIN__?.basePath || '').replace(/\/+$/g, '');
  const normalizedPath = `/${String(path || '').replace(/^\/+/g, '')}`;
  return new URL(`${basePath}${normalizedPath}`, window.location.origin).toString();
}

function resetCompanyForm(): void {
  Object.assign(companyForm, { id: 0, name: '', status: 'prospect', email: '', phone: '', website_url: '', notes: '' });
}

function resetContactForm(): void {
  Object.assign(contactForm, { id: 0, company_id: '', iam_user_id: '', first_name: '', last_name: '', display_name: '', status: 'prospect', preferred_language: '', email: '', phone: '', mobile: '', job_title: '', notes: '' });
  contactChannels.value = [];
  contactConsents.value = [];
  Object.keys(consentForm).forEach((key) => delete consentForm[key]);
  Object.assign(quickRelation, { inline_company_name: '', tags: '' });
}

function resetMemoForm(): void {
  Object.assign(memoForm, { id: 0, company_id: '', contact_id: '', title: '', body: '', visibility: 'internal', comment: '', share_iam_user_ids: '', public_label: '', public_expires_at: '' });
  memoComments.value = [];
  memoShares.value = [];
  oneTimeShareUrl.value = '';
  Object.assign(memoCommentEdit, { id: 0, body: '' });
  quickMemo.public_share = false;
}

function resetRelationMessageForm(): void {
  Object.assign(messageTestForm, {
    channel: 'email',
    provider_key: 'email',
    recipient_value: '',
    subject: '',
    body_text: '',
    confirm_external_test: false,
  });
  relationMessages.value = [];
  messagePreview.value = null;
  messagePreviewProviders.value = { runtime: [], configured: [] };
}

function resetMailingListForm(): void {
  Object.assign(mailingListForm, { id: 0, name: '', list_key: '', channel: 'email', description: '', status: 'active', member_contact_id: '' });
  mailingMembers.value = [];
}

function resetCampaignForm(): void {
  Object.assign(campaignForm, { id: 0, list_id: '', name: '', channel: 'email', subject: '', body_text: '', scheduled_at: '' });
  campaignPreview.value = [];
}

async function loadCompanies(): Promise<void> {
  if (!canCrmRead.value) return;
  loading.value = true;
  try {
    const response = await adminApi.get<{ companies: Company[] }>('/business/companies', { q: companyFilter.q, status: companyFilter.status, archived: companyFilter.archived ? 'all' : undefined, limit: 100 });
    companies.value = response.data.companies || [];
  } catch (err) {
    setError(err, 'Entreprises indisponibles.');
  } finally {
    loading.value = false;
  }
}

async function loadContacts(): Promise<void> {
  if (!canCrmRead.value) return;
  loading.value = true;
  try {
    const response = await adminApi.get<{ contacts: Contact[] }>('/business/contacts', { q: contactFilter.q, status: contactFilter.status, company_id: contactFilter.company_id || undefined, limit: 100 });
    contacts.value = response.data.contacts || [];
  } catch (err) {
    setError(err, 'Contacts indisponibles.');
  } finally {
    loading.value = false;
  }
}

async function loadTags(): Promise<void> {
  if (!canCrmRead.value) return;
  try {
    const response = await adminApi.get<{ tags: Array<Record<string, unknown>> }>('/business/tags');
    tags.value = response.data.tags || [];
  } catch (_) {
    tags.value = [];
  }
}

async function loadMemos(): Promise<void> {
  if (!canMemoRead.value) return;
  loading.value = true;
  try {
    if (relationMemoFilter.value) {
      const relation = relationMemoFilter.value;
      const response = await adminApi.get<{ memos: Memo[] }>(`/business/relations/${relation.type}/${relation.id}/memos`, { limit: 100 });
      const q = memoFilter.q.trim().toLowerCase();
      const items = response.data.memos || [];
      memos.value = q
        ? items.filter((memo) => `${memo.title || ''} ${memo.body || ''}`.toLowerCase().includes(q))
        : items;
      return;
    }
    const response = await adminApi.get<{ memos: Memo[] }>('/business/memos', { q: memoFilter.q, company_id: memoFilter.company_id || undefined, contact_id: memoFilter.contact_id || undefined, limit: 100 });
    memos.value = response.data.memos || [];
  } catch (err) {
    setError(err, 'Mémos indisponibles.');
  } finally {
    loading.value = false;
  }
}

async function loadMailing(): Promise<void> {
  if (!canMailingRead.value) return;
  loading.value = true;
  try {
    const [listsResponse, campaignsResponse] = await Promise.all([
      adminApi.get<{ lists: MailingList[] }>('/business/mailing/lists', { limit: 100 }),
      adminApi.get<{ campaigns: Campaign[] }>('/business/mailing/campaigns', { limit: 100 }),
    ]);
    mailingLists.value = listsResponse.data.lists || [];
    campaigns.value = campaignsResponse.data.campaigns || [];
  } catch (err) {
    setError(err, 'Mailing indisponible.');
  } finally {
    loading.value = false;
  }
}

async function loadMessaging(): Promise<void> {
  if (!canMessagingAdmin.value) return;
  loading.value = true;
  try {
    const [providersResponse, outboxResponse] = await Promise.all([
      adminApi.get<{ runtime?: MessagingProvider[]; configured?: MessagingProvider[] }>('/business/messaging/providers'),
      adminApi.get<{ messages: MessageRow[] }>('/business/messaging/outbox', { status: messageFilter.status || undefined, channel: messageFilter.channel || undefined, q: messageFilter.q || undefined, limit: 100 }),
    ]);
    messagingProviders.value = {
      runtime: providersResponse.data.runtime || [],
      configured: providersResponse.data.configured || [],
    };
    syncProviderDrafts();
    outbox.value = outboxResponse.data.messages || [];
  } catch (err) {
    setError(err, 'Messaging indisponible.');
  } finally {
    loading.value = false;
  }
}

function providerDraftKey(provider: MessagingProvider | ProviderDraft): string {
  const runtimeKey = 'key' in provider ? provider.key : '';
  return provider.id ? `configured-${provider.id}` : `new-${provider.provider_key || runtimeKey || 'provider'}-${provider.channel || 'email'}`;
}

function providerDefaultType(provider: MessagingProvider): string {
  const key = String(provider.provider_key || provider.key || '');
  if (key === 'email') return 'smtp';
  if (key === 'whatsapp_cloud') return 'whatsapp_cloud';
  if (key === 'telegram_bot') return 'telegram_bot';
  return String(provider.provider_type || 'null');
}

function providerDraftFrom(provider: MessagingProvider): ProviderDraft {
  const providerKey = String(provider.provider_key || provider.key || '');
  return {
    id: Number(provider.id || 0),
    provider_key: providerKey,
    name: String(provider.name || providerKey || 'Provider'),
    channel: String(provider.channel || 'email'),
    provider_type: providerDefaultType(provider),
    config_json: JSON.stringify(provider.config || {}, null, 2),
    secret_ref: String(provider.secret_ref || ''),
    is_enabled: Boolean(provider.is_enabled ?? provider.enabled ?? false),
    is_default: Boolean(provider.is_default ?? false),
  };
}

function syncProviderDrafts(): void {
  for (const provider of messagingProviders.value.configured) {
    const key = providerDraftKey(provider);
    if (!providerDrafts[key]) providerDrafts[key] = providerDraftFrom(provider);
  }
}

function newProviderFromRuntime(provider?: MessagingProvider): void {
  const source = provider || { key: 'log_only', channel: 'email', enabled: true };
  const draft = providerDraftFrom({ ...source, id: 0, provider_key: `${source.key || 'provider'}_${source.channel || 'email'}` });
  draft.name = `${source.key || 'Provider'} ${source.channel || 'email'}`;
  draft.is_enabled = true;
  const key = providerDraftKey(draft);
  providerDrafts[key] = draft;
}

async function saveProviderDraft(key: string): Promise<void> {
  const draft = providerDrafts[key];
  if (!draft || !canMessagingAdmin.value) return;
  let config: Record<string, unknown>;
  try {
    config = JSON.parse(draft.config_json || '{}');
  } catch {
    setError(new Error('Configuration JSON invalide.'), 'Provider non enregistré.');
    return;
  }
  if (draft.config_json.includes('***')) {
    setError(new Error('Le JSON contient une valeur masquée.'), 'Remplacez les valeurs masquées par une référence secret_ref env:... ou retirez-les du JSON.');
    return;
  }
  busy.value = `messaging.provider.${key}`;
  try {
    const payload = {
      provider_key: draft.provider_key,
      name: draft.name,
      channel: draft.channel,
      provider_type: draft.provider_type,
      config,
      secret_ref: draft.secret_ref || null,
      is_enabled: draft.is_enabled,
      is_default: draft.is_default,
    };
    if (draft.id) {
      await adminApi.patch(`/business/messaging/providers/${draft.id}`, payload);
    } else {
      await adminApi.post('/business/messaging/providers', payload);
    }
    await loadMessaging();
    setNotice('Provider enregistré.');
  } catch (err) {
    setError(err, 'Provider non enregistré.');
  } finally {
    busy.value = '';
  }
}

async function deleteProviderDraft(key: string): Promise<void> {
  const draft = providerDrafts[key];
  if (!draft || !canMessagingAdmin.value) return;
  if (!draft.id) {
    delete providerDrafts[key];
    return;
  }
  busy.value = `messaging.provider.delete.${draft.id}`;
  try {
    await adminApi.delete(`/business/messaging/providers/${draft.id}`);
    delete providerDrafts[key];
    await loadMessaging();
    setNotice('Provider supprimé.');
  } catch (err) {
    setError(err, 'Provider non supprimé.');
  } finally {
    busy.value = '';
  }
}

async function loadDashboard(): Promise<void> {
  if (!canCrmRead.value) return;
  loading.value = true;
  try {
    const response = await adminApi.get<BusinessDashboard>('/business/dashboard');
    dashboard.value = {
      counters: response.data.counters || {},
      recent_relations: response.data.recent_relations || [],
      latest_memos: response.data.latest_memos || [],
      latest_messages: response.data.latest_messages || [],
      alerts: response.data.alerts || [],
    };
  } catch (err) {
    setError(err, 'Dashboard Business indisponible.');
  } finally {
    loading.value = false;
  }
}

async function runDashboardSearch(): Promise<void> {
  const q = dashboardSearch.q.trim();
  if (q.length < 2) {
    dashboardSearchResults.value = [];
    return;
  }
  busy.value = 'dashboard.search';
  try {
    const response = await adminApi.get<{ relations: DashboardSearchItem[]; memos: DashboardSearchItem[]; messages: DashboardSearchItem[] }>('/business/search', { q, limit: 5 });
    dashboardSearchResults.value = [
      ...(response.data.relations || []),
      ...(response.data.memos || []),
      ...(response.data.messages || []),
    ];
  } catch (err) {
    setError(err, 'Recherche Business indisponible.');
  } finally {
    busy.value = '';
  }
}

async function loadRelations(): Promise<void> {
  companyFilter.q = relationsToolbar.q;
  companyFilter.status = relationsToolbar.status;
  contactFilter.q = relationsToolbar.q;
  contactFilter.status = relationsToolbar.status;
  memoFilter.q = relationsToolbar.q;
  await Promise.all([loadCompanies(), loadContacts(), loadMemos(), loadTags()]);
}

async function loadCurrentTab(): Promise<void> {
  if (activeTab.value === 'dashboard') await loadDashboard();
  if (activeTab.value === 'relations') await loadRelations();
  if (activeTab.value === 'messages') await Promise.all([loadMailing(), loadMessaging()]);
  if (activeTab.value === 'settings') await loadMessaging();
  if (activeTab.value === 'products' || activeTab.value === 'offers') catalogRefreshKey.value++;
}

function openRelationsMain(): void {
  relationsPanel.value = 'main';
  relationMemoFilter.value = null;
}

function openRelationsMemos(relation?: BusinessRelation | RelationMemoFilter | null): void {
  if (relation && relation.id) {
    relationMemoFilter.value = {
      type: relation.type,
      id: Number(relation.id),
      display_name: String(relation.display_name || (relation.type === 'company' ? 'Entreprise' : 'Individu')),
    };
    memoFilter.company_id = relation.type === 'company' ? String(relation.id) : '';
    memoFilter.contact_id = relation.type === 'contact' ? String(relation.id) : '';
  } else {
    relationMemoFilter.value = null;
    memoFilter.company_id = '';
    memoFilter.contact_id = '';
  }
  relationsPanel.value = 'memos';
  if (activeModal.value) {
    closeModal(true);
  }
  void loadMemos();
}

function splitQuickTags(value: string): string[] {
  return value.split(/[,\n;]+/).map((tag) => tag.trim()).filter(Boolean).slice(0, 12);
}

async function applyQuickTags(targetType: 'contact' | 'company', targetId: number): Promise<void> {
  const labels = splitQuickTags(quickRelation.tags);
  for (const label of labels) {
    const response = await adminApi.post<{ tag: { id: number } }>('/business/tags', { label });
    const tagId = Number(response.data.tag?.id || 0);
    if (tagId > 0) {
      await adminApi.post('/business/tag-links', { tag_id: tagId, target_type: targetType, target_id: targetId });
    }
  }
}

async function createInlineCompanyForContact(): Promise<Company | null> {
  const name = quickRelation.inline_company_name.trim();
  if (!name || !canCrmManage.value) return null;
  busy.value = 'company.save';
  try {
    const response = await adminApi.post<{ company: Company }>('/business/companies', {
      name,
      status: contactForm.status || 'prospect',
      email: '',
      phone: '',
      website_url: '',
      notes: '',
    });
    await loadCompanies();
    return response.data.company;
  } catch (err) {
    setError(err, 'Création entreprise inline impossible.');
    return null;
  } finally {
    busy.value = '';
  }
}

function quickModalDirty(): boolean {
  if (activeModal.value === 'relation' && !contactForm.id && !companyForm.id) {
    return Boolean(
      quickRelation.inline_company_name || quickRelation.tags ||
      contactForm.display_name || contactForm.first_name || contactForm.last_name || contactForm.email || contactForm.phone || contactForm.mobile ||
      companyForm.name || companyForm.email || companyForm.phone
    );
  }
  if (activeModal.value === 'memo' && !memoForm.id) {
    return Boolean(memoForm.title || memoForm.body || memoForm.company_id || memoForm.contact_id || quickMemo.public_share || memoForm.public_expires_at);
  }
  if (activeModal.value === 'message') {
    return Boolean(messageTestForm.subject || messageTestForm.body_text);
  }
  return false;
}

async function saveQuickRelationContact(): Promise<void> {
  if (quickRelation.inline_company_name.trim()) {
    const company = await createInlineCompanyForContact();
    if (!company) return;
    contactForm.company_id = String(company.id);
  }
  const contact = await saveContact(false);
  if (!contact) return;
  closeModal(true);
  if (quickRelation.tags.trim()) await applyQuickTags('contact', contact.id);
  await Promise.all([loadTags(), loadDashboard(), reloadRelationsView()]);
}

async function saveQuickRelationCompany(): Promise<void> {
  const company = await saveCompany(false);
  if (!company) return;
  closeModal(true);
  if (quickRelation.tags.trim()) await applyQuickTags('company', company.id);
  await Promise.all([loadTags(), loadDashboard(), reloadRelationsView()]);
}

async function saveQuickMemo(): Promise<void> {
  const publicExpiresAt = memoForm.public_expires_at;
  if (quickMemo.public_share && canMemoShare.value) {
    memoForm.visibility = 'public_link';
  }
  const memo = await saveMemo();
  if (!memo) return;
  if (quickMemo.public_share && canMemoShare.value) {
    memoForm.id = memo.id;
    memoForm.public_expires_at = publicExpiresAt;
    await createPublicMemoShare();
    await loadDashboard();
    return;
  }
  await loadDashboard();
  closeModal(true);
}

function startNewRelation(): void {
  relationsPanel.value = 'main';
  relationModalMode.value = 'create';
  relationDraftType.value = 'contact';
  relationDetail.value = null;
  linkedRelationContacts.value = [];
  relationActivity.value = [];
  resetCompanyForm();
  resetContactForm();
  void loadIamUserOptions();
  activeModal.value = 'relation';
}

function openMessages(): void {
  activeTab.value = 'messages';
}

function openDashboardRelation(relation: BusinessRelation): void {
  activeTab.value = 'relations';
  relation.type === 'company' ? void editRelationCompany(relation) : void editRelationContact(relation);
}

function startDashboardMemo(): void {
  const relation = dashboard.value?.recent_relations[0];
  if (relation) {
    startRelationMemo(relation);
    return;
  }
  startNewMemo();
}

function startNewMemo(): void {
  openRelationsMemos();
  resetMemoForm();
  activeModal.value = 'memo';
}

function startDashboardMessage(): void {
  const relation = dashboard.value?.recent_relations.find((item) => item.type === 'contact');
  if (relation) {
    void openRelationMessage(relation);
    return;
  }
  activeTab.value = 'relations';
}

function openDashboardSearchItem(item: DashboardSearchItem): void {
  if (item.group === 'relation' || item.type === 'contact' || item.type === 'company') {
    openDashboardRelation({
      type: item.type === 'company' ? 'company' : 'contact',
      id: Number(item.id),
      display_name: String(item.title || item.subtitle || `#${item.id}`),
      status: String(item.status || 'prospect'),
      company: item.company_id ? { id: Number(item.company_id), name: String(item.company_name || item.subtitle || '') } : null,
    });
    return;
  }
  if (item.group === 'memo' || item.type === 'memo') {
    openRelationsMemos();
    return;
  }
  openMessages();
}

function closeModal(force = false): void {
  if (!force && quickModalDirty() && !window.confirm('Fermer sans enregistrer ?')) return;
  activeModal.value = '';
  relationModalMode.value = 'create';
  messageRelation.value = null;
  archiveRelation.value = null;
  relationMessages.value = [];
  messagePreview.value = null;
  messagePreviewProviders.value = { runtime: [], configured: [] };
}

function beginRelationEdit(): void {
  if (!currentRelation.value.id || !canEditCurrentRelation.value) return;
  relationModalMode.value = 'edit';
}

async function saveRelationEdit(): Promise<void> {
  if (activeModal.value !== 'relation' || relationModalMode.value !== 'edit') return;
  if (!canEditCurrentRelation.value) return;
  const saved = relationDraftType.value === 'company' ? await saveCompany(true) : await saveContact(true);
  if (!saved) return;
  relationModalMode.value = 'view';
}

function changeRelationDraftType(type: 'contact' | 'company'): void {
  if (relationDraftType.value === type) return;
  const hasContactData = Boolean(contactForm.id || contactForm.display_name || contactForm.first_name || contactForm.last_name || contactForm.email || contactForm.mobile);
  const hasCompanyData = Boolean(companyForm.id || companyForm.name || companyForm.email || companyForm.phone);
  if ((hasContactData || hasCompanyData) && !window.confirm('Changer le type de relation peut laisser des champs non utilises. Continuer ?')) {
    return;
  }
  relationDraftType.value = type;
  relationDetail.value = null;
  if (type === 'contact') {
    linkedRelationContacts.value = [];
    void loadIamUserOptions();
  } else if (companyForm.id) {
    void loadLinkedRelationContacts(companyForm.id);
  }
}

async function reloadRelationsView(): Promise<void> {
  await relationsView.value?.reload();
}

async function loadRelationDetail(type: 'contact' | 'company', id: number): Promise<void> {
  try {
    const response = await adminApi.get<{ relation: BusinessRelation }>(`/business/relations/${type}/${id}`);
    relationDetail.value = response.data.relation;
  } catch (_) {
    relationDetail.value = null;
  }
}

async function loadRelationActivity(type: 'contact' | 'company', id: number): Promise<void> {
  if (!id) {
    relationActivity.value = [];
    return;
  }
  try {
    const response = await adminApi.get<{ activity: BusinessActivity[] }>(`/business/relations/${type}/${id}/activity`, { limit: 100 });
    relationActivity.value = response.data.activity || [];
  } catch (_) {
    relationActivity.value = [];
  }
}

async function reloadCurrentRelationActivity(): Promise<void> {
  if (!currentRelation.value.id) return;
  await loadRelationActivity(currentRelation.value.type, currentRelation.value.id);
}

async function loadLinkedRelationContacts(companyId: number): Promise<void> {
  if (!companyId) {
    linkedRelationContacts.value = [];
    return;
  }
  try {
    const response = await adminApi.get<{ relations: BusinessRelation[] }>('/business/relations', { type: 'contact', limit: 200 });
    linkedRelationContacts.value = (response.data.relations || []).filter((relation) => Number(relation.company?.id || 0) === companyId);
  } catch (_) {
    linkedRelationContacts.value = [];
  }
}

async function loadIamUserOptions(): Promise<void> {
  try {
    const response = await adminApi.get<{ users: IamUserOption[] }>('/business/iam/available-users', { limit: 200, contact_id: contactForm.id || undefined, include_assigned: true });
    iamUsers.value = response.data.users || [];
  } catch (_) {
    iamUsers.value = [];
  }
}

async function editRelationCompany(relation: BusinessRelation): Promise<void> {
  try {
    const response = await adminApi.get<{ company: Company }>(`/business/companies/${relation.id}`);
    editCompany(response.data.company);
    await Promise.all([loadRelationDetail('company', relation.id), loadLinkedRelationContacts(relation.id), loadRelationActivity('company', relation.id), loadIamUserOptions()]);
    relationDraftType.value = 'company';
    relationModalMode.value = 'view';
    activeModal.value = 'relation';
  } catch (err) {
    setError(err, 'Ouverture entreprise impossible.');
  }
}

async function editRelationContact(relation: BusinessRelation): Promise<void> {
  try {
    const response = await adminApi.get<{ contact: Contact }>(`/business/contacts/${relation.id}`);
    editContact(response.data.contact);
    await Promise.all([loadRelationDetail('contact', relation.id), loadRelationActivity('contact', relation.id), loadContactConsents(response.data.contact.id), loadIamUserOptions()]);
    relationDraftType.value = 'contact';
    relationModalMode.value = 'view';
    activeModal.value = 'relation';
  } catch (err) {
    setError(err, 'Ouverture contact impossible.');
  }
}

function startRelationMemo(relation: BusinessRelation): void {
  openRelationsMemos(relation);
  resetMemoForm();
  if (relation.type === 'contact') {
    memoForm.contact_id = String(relation.id);
    memoForm.company_id = relation.company?.id ? String(relation.company.id) : '';
  } else {
    memoForm.company_id = String(relation.id);
  }
  activeModal.value = 'memo';
}

function openRelationMemos(relation: BusinessRelation): void {
  memoFilter.q = '';
  openRelationsMemos(relation);
}

async function openRelationConsent(relation: BusinessRelation): Promise<void> {
  if (relation.type !== 'contact') return;
  await editRelationContact(relation);
  activeModal.value = 'consent';
}

function openImportModal(): void {
  resetContactImport();
  activeModal.value = 'import';
}

function openExportModal(): void {
  activeModal.value = 'export';
}

function openCommentsList(): void {
  openRelationsMemos();
}

async function loadRelationMessages(relation: BusinessRelation): Promise<void> {
  if (!canMessagingSend.value || relation.type !== 'contact' || !relation.id) return;
  try {
    const response = await adminApi.get<{ messages: MessageRow[] }>(`/business/relations/${relation.type}/${relation.id}/messages`, { limit: 20 });
    relationMessages.value = response.data.messages || [];
  } catch (err) {
    setError(err, 'Messages de la relation indisponibles.');
  }
}

async function loadRelationMessagePreview(): Promise<void> {
  if (!messageRelation.value || messageRelation.value.type !== 'contact' || !canMessagingSend.value) return;
  busy.value = 'relation.message.preview';
  try {
    const response = await adminApi.post<{ preview: MessagePreview; providers?: { runtime?: MessagingProvider[]; configured?: MessagingProvider[] } }>('/business/messages/preview', {
      relation_type: 'contact',
      relation_id: messageRelation.value.id,
      channel: messageTestForm.channel,
    });
    messagePreview.value = response.data.preview || null;
    messagePreviewProviders.value = {
      runtime: response.data.providers?.runtime || [],
      configured: response.data.providers?.configured || [],
    };
    const currentProvider = messageTestForm.provider_key;
    const options = relationMessageProviders.value.map((provider) => providerKey(provider)).filter(Boolean);
    if (options.length > 0 && !options.includes(currentProvider)) {
      messageTestForm.provider_key = options[0];
    }
  } catch (err) {
    messagePreview.value = null;
    setError(err, 'Prévisualisation message impossible.');
  } finally {
    busy.value = '';
  }
}

async function openRelationMessage(relation: BusinessRelation, preferredChannel = ''): Promise<void> {
  if (relation.type !== 'contact') {
    setError(new Error('Les messages directs sont disponibles pour les personnes.'));
    return;
  }
  if (!canMessagingSend.value) {
    setError(new Error('Permission business.messaging.send requise.'));
    return;
  }
  messageRelation.value = relation;
  resetRelationMessageForm();
  await loadContactConsents(relation.id);
  const preferredReady = preferredChannel ? relationMessageChannelRows.value.find((row) => row.channel === preferredChannel && row.has_channel) : null;
  const firstReady = preferredReady || relationMessageChannelRows.value.find((row) => row.can_send) || relationMessageChannelRows.value.find((row) => row.has_channel);
  messageTestForm.channel = firstReady?.channel || 'email';
  activeModal.value = 'message';
  await Promise.all([loadRelationMessagePreview(), loadRelationMessages(relation)]);
}

async function sendRelationMessage(): Promise<void> {
  if (!messageRelation.value || messageRelation.value.type !== 'contact') return;
  if (!canSendRelationMessage.value) {
    setError(new Error(messagePreviewNotice()));
    return;
  }
  busy.value = 'relation.message';
  try {
    await adminApi.post('/business/messages/send', {
      relation_type: 'contact',
      relation_id: messageRelation.value.id,
      channel: messageTestForm.channel,
      provider_key: messageTestForm.provider_key,
      subject: messageTestForm.subject,
      body_text: messageTestForm.body_text,
    });
    await Promise.all([loadRelationMessages(messageRelation.value), loadRelationActivity('contact', messageRelation.value.id)]);
    setNotice('Message traité pour la relation.');
  } catch (err) {
    setError(err, 'Envoi du message impossible.');
  } finally {
    busy.value = '';
  }
}

async function archiveRelationCompany(relation: BusinessRelation): Promise<void> {
  archiveRelation.value = relation;
  activeModal.value = 'archive';
}

async function archiveRelationContact(relation: BusinessRelation): Promise<void> {
  archiveRelation.value = relation;
  activeModal.value = 'archive';
}

async function confirmArchiveRelation(): Promise<void> {
  const relation = archiveRelation.value;
  if (!relation) return;
  if (relation.type === 'company') {
    await archiveCompany({ id: relation.id, name: relation.display_name, is_system: Boolean(relation.company?.is_system_individuals) });
  } else {
    await archiveContact({ id: relation.id, display_name: relation.display_name });
  }
  await reloadRelationsView();
  closeModal();
}

function editCompany(company: Company): void {
  Object.assign(companyForm, {
    id: company.id,
    name: valueText(company.name),
    status: valueText(company.status || 'prospect'),
    email: valueText(company.email),
    phone: valueText(company.phone),
    website_url: valueText(company.website_url),
    notes: valueText(company.notes),
  });
}

async function saveCompany(hydrateAfterSave = true): Promise<Company | null> {
  if (!canCrmManage.value || isSystemCompanyForm.value) return null;
  busy.value = 'company.save';
  try {
    const payload = { name: companyForm.name, status: companyForm.status, email: companyForm.email, phone: companyForm.phone, website_url: companyForm.website_url, notes: companyForm.notes };
    const response = companyForm.id
      ? await adminApi.patch<{ company: Company }>(`/business/companies/${companyForm.id}`, payload)
      : await adminApi.post<{ company: Company }>('/business/companies', payload);
    if (hydrateAfterSave) {
      editCompany(response.data.company);
      await loadRelationDetail('company', response.data.company.id);
      await loadLinkedRelationContacts(response.data.company.id);
      await loadRelationActivity('company', response.data.company.id);
    }
    await loadCompanies();
    await reloadRelationsView();
    setNotice(companyForm.id ? 'Entreprise enregistrée.' : 'Entreprise créée.');
    return response.data.company;
  } catch (err) {
    setError(err, 'Enregistrement entreprise impossible.');
    return null;
  } finally {
    busy.value = '';
  }
}

async function archiveCompany(company: Company): Promise<void> {
  if (!canCrmManage.value || company.is_system) return;
  busy.value = `company.archive.${company.id}`;
  try {
    await adminApi.post(`/business/companies/${company.id}/archive`, {});
    if (companyForm.id === company.id) resetCompanyForm();
    await loadCompanies();
    await reloadRelationsView();
    setNotice('Entreprise archivée.');
  } catch (err) {
    setError(err, 'Archivage entreprise impossible.');
  } finally {
    busy.value = '';
  }
}

function editContact(contact: Contact): void {
  Object.assign(contactForm, {
    id: contact.id,
    company_id: valueText(contact.company_id),
    iam_user_id: valueText(contact.iam_user_id),
    first_name: valueText(contact.first_name),
    last_name: valueText(contact.last_name),
    display_name: valueText(contact.display_name),
    status: valueText(contact.status || 'prospect'),
    preferred_language: valueText(contact.preferred_language),
    email: valueText(contact.email),
    phone: valueText(contact.phone),
    mobile: valueText(contact.mobile),
    job_title: valueText(contact.job_title),
    notes: valueText(contact.notes),
  });
  void loadContactConsents(contact.id);
}

async function saveContact(hydrateAfterSave = true): Promise<Contact | null> {
  if (!canCrmManage.value) return null;
  busy.value = 'contact.save';
  try {
    const payload = {
      company_id: intOrNull(contactForm.company_id),
      iam_user_id: intOrNull(contactForm.iam_user_id),
      first_name: contactForm.first_name,
      last_name: contactForm.last_name,
      display_name: contactForm.display_name,
      status: contactForm.status,
      preferred_language: contactForm.preferred_language,
      email: contactForm.email,
      phone: contactForm.phone,
      mobile: contactForm.mobile,
      job_title: contactForm.job_title,
      notes: contactForm.notes,
    };
    const response = contactForm.id
      ? await adminApi.patch<{ contact: Contact }>(`/business/contacts/${contactForm.id}`, payload)
      : await adminApi.post<{ contact: Contact }>('/business/contacts', payload);
    if (hydrateAfterSave) {
      editContact(response.data.contact);
      await loadRelationDetail('contact', response.data.contact.id);
      await loadRelationActivity('contact', response.data.contact.id);
    }
    await loadContacts();
    await reloadRelationsView();
    setNotice(contactForm.id ? 'Contact enregistré.' : 'Contact créé.');
    return response.data.contact;
  } catch (err) {
    setError(err, 'Enregistrement contact impossible.');
    return null;
  } finally {
    busy.value = '';
  }
}

async function archiveContact(contact: Contact): Promise<void> {
  if (!canCrmManage.value) return;
  busy.value = `contact.archive.${contact.id}`;
  try {
    await adminApi.post(`/business/contacts/${contact.id}/archive`, {});
    if (contactForm.id === contact.id) resetContactForm();
    await loadContacts();
    await reloadRelationsView();
    setNotice('Contact archivé.');
  } catch (err) {
    setError(err, 'Archivage contact impossible.');
  } finally {
    busy.value = '';
  }
}

async function loadContactConsents(contactId: number): Promise<void> {
  if (!canCrmRead.value) return;
  try {
    const response = await adminApi.get<{ channels: ChannelRow[]; consents: Consent[] }>(`/business/contacts/${contactId}/consents`);
    contactChannels.value = response.data.channels || [];
    contactConsents.value = response.data.consents || [];
    channels.forEach((channel) => {
      const channelRow = contactChannels.value.find((row) => row.channel === channel);
      const consent = contactConsents.value.find((row) => row.channel === channel);
      consentForm[channel] = {
        value: valueText(channelRow?.channel_value),
        consent_status: valueText(consent?.consent_status || 'unknown'),
        evidence: valueText(consent?.evidence),
        is_verified: Boolean(channelRow?.is_verified),
      };
    });
  } catch (err) {
    setError(err, 'Consentements indisponibles.');
  }
}

async function saveConsent(channel: string): Promise<void> {
  if (!contactForm.id || !canCrmManage.value) return;
  busy.value = `consent.${channel}`;
  try {
    const payload = consentForm[channel] || { value: '', consent_status: 'unknown', evidence: '', is_verified: false };
    await adminApi.patch(`/business/contacts/${contactForm.id}/consents/${channel}`, {
      value: payload.value,
      consent_status: payload.consent_status,
      source: 'manual',
      evidence: payload.evidence,
      is_primary: true,
      is_verified: payload.is_verified,
    });
    await loadContactConsents(contactForm.id);
    setNotice(`Consentement ${channel} enregistré.`);
  } catch (err) {
    setError(err, 'Enregistrement consentement impossible.');
  } finally {
    busy.value = '';
  }
}

function editMemo(memo: Memo): void {
  Object.assign(memoForm, {
    id: memo.id,
    company_id: valueText(memo.company_id),
    contact_id: valueText(memo.contact_id),
    title: valueText(memo.title),
    body: valueText(memo.body),
    visibility: valueText(memo.visibility || 'private'),
    comment: '',
    share_iam_user_ids: '',
    public_label: '',
    public_expires_at: '',
  });
  oneTimeShareUrl.value = '';
  Object.assign(memoCommentEdit, { id: 0, body: '' });
  activeModal.value = 'memo';
  void Promise.all([loadMemoComments(memo.id), canMemoShare.value ? loadMemoShares(memo.id) : Promise.resolve()]);
}

async function saveMemo(): Promise<Memo | null> {
  if (!canMemoManage.value) return null;
  busy.value = 'memo.save';
  try {
    const fallbackTitle = (memoForm.body.trim().split(/\n/)[0] || 'Mémo').slice(0, 120);
    const payload = { company_id: intOrNull(memoForm.company_id), contact_id: intOrNull(memoForm.contact_id), title: memoForm.title.trim() || fallbackTitle, body: memoForm.body, visibility: memoForm.visibility };
    const response = memoForm.id
      ? await adminApi.patch<{ memo: Memo }>(`/business/memos/${memoForm.id}`, payload)
      : await adminApi.post<{ memo: Memo }>('/business/memos', payload);
    editMemo(response.data.memo);
    await loadMemos();
    await reloadRelationsView();
    await reloadCurrentRelationActivity();
    setNotice(memoForm.id ? 'Mémo enregistré.' : 'Mémo créé.');
    return response.data.memo;
  } catch (err) {
    setError(err, 'Enregistrement mémo impossible.');
    return null;
  } finally {
    busy.value = '';
  }
}

async function archiveMemo(memo: Memo): Promise<void> {
  if (!canMemoManage.value) return;
  busy.value = `memo.archive.${memo.id}`;
  try {
    await adminApi.post(`/business/memos/${memo.id}/archive`, {});
    if (memoForm.id === memo.id) resetMemoForm();
    await loadMemos();
    await reloadRelationsView();
    await reloadCurrentRelationActivity();
    setNotice('Mémo archivé.');
  } catch (err) {
    setError(err, 'Archivage mémo impossible.');
  } finally {
    busy.value = '';
  }
}

async function loadMemoComments(memoId: number): Promise<void> {
  if (!canMemoRead.value) return;
  try {
    const response = await adminApi.get<{ comments: MemoComment[] }>(`/business/memos/${memoId}/comments`);
    memoComments.value = response.data.comments || [];
  } catch (err) {
    setError(err, 'Commentaires indisponibles.');
  }
}

async function addMemoComment(): Promise<void> {
  if (!memoForm.id || !canMemoManage.value || memoForm.comment.trim() === '') return;
  busy.value = 'memo.comment';
  try {
    await adminApi.post(`/business/memos/${memoForm.id}/comments`, { body: memoForm.comment });
    memoForm.comment = '';
    await loadMemoComments(memoForm.id);
    await reloadRelationsView();
    await reloadCurrentRelationActivity();
    setNotice('Commentaire ajouté.');
  } catch (err) {
    setError(err, 'Ajout de commentaire impossible.');
  } finally {
    busy.value = '';
  }
}

function startEditMemoComment(comment: MemoComment): void {
  Object.assign(memoCommentEdit, { id: comment.id, body: valueText(comment.body) });
}

async function updateMemoComment(): Promise<void> {
  if (!memoForm.id || !memoCommentEdit.id || !canMemoManage.value || memoCommentEdit.body.trim() === '') return;
  busy.value = 'memo.comment.update';
  try {
    await adminApi.patch(`/business/memos/${memoForm.id}/comments/${memoCommentEdit.id}`, { body: memoCommentEdit.body });
    Object.assign(memoCommentEdit, { id: 0, body: '' });
    await loadMemoComments(memoForm.id);
    await reloadRelationsView();
    await reloadCurrentRelationActivity();
    setNotice('Commentaire mis à jour.');
  } catch (err) {
    setError(err, 'Mise à jour du commentaire impossible.');
  } finally {
    busy.value = '';
  }
}

async function loadMemoShares(memoId: number): Promise<void> {
  try {
    const response = await adminApi.get<{ shares: MemoShare[] }>(`/business/memos/${memoId}/shares`);
    memoShares.value = response.data.shares || [];
    Object.keys(shareExpiryDrafts).forEach((key) => delete shareExpiryDrafts[Number(key)]);
    Object.keys(shareLabelDrafts).forEach((key) => delete shareLabelDrafts[Number(key)]);
    memoShares.value.forEach((share) => {
      shareExpiryDrafts[share.id] = toDateTimeLocal(share.expires_at);
      shareLabelDrafts[share.id] = valueText(share.public_label);
    });
  } catch (err) {
    setError(err, 'Partages indisponibles.');
  }
}

async function shareMemoInternally(): Promise<void> {
  if (!memoForm.id || !canMemoShare.value) return;
  const ids = memoForm.share_iam_user_ids.split(/[,\s;]+/).map((value) => Number(value)).filter((value) => Number.isFinite(value) && value > 0);
  if (!ids.length) {
    setError(new Error('Indiquez au moins un ID utilisateur IAM.'));
    return;
  }
  busy.value = 'memo.share.internal';
  try {
    await adminApi.post(`/business/memos/${memoForm.id}/shares`, { iam_user_ids: ids });
    memoForm.share_iam_user_ids = '';
    await loadMemoShares(memoForm.id);
    await reloadRelationsView();
    await reloadCurrentRelationActivity();
    setNotice('Partage interne créé.');
  } catch (err) {
    setError(err, 'Partage interne impossible.');
  } finally {
    busy.value = '';
  }
}

async function createPublicMemoShare(): Promise<void> {
  if (!memoForm.id || !canMemoShare.value) return;
  busy.value = 'memo.share.public';
  try {
    const response = await adminApi.post<{ public_path: string; message?: string }>(`/business/memos/${memoForm.id}/public-share`, { label: memoForm.public_label, expires_at: fromDateTimeLocal(memoForm.public_expires_at) });
    oneTimeShareUrl.value = publicUrl(response.data.public_path);
    await loadMemoShares(memoForm.id);
    await reloadRelationsView();
    await reloadCurrentRelationActivity();
    setNotice(response.data.message || 'Lien public créé.');
  } catch (err) {
    setError(err, 'Création du lien public impossible.');
  } finally {
    busy.value = '';
  }
}

async function updateMemoPublicShare(share: MemoShare): Promise<void> {
  if (!memoForm.id || !canMemoShare.value || share.share_type !== 'public_link') return;
  busy.value = `memo.share.update.${share.id}`;
  try {
    await adminApi.patch(`/business/memos/${memoForm.id}/shares/${share.id}`, { label: shareLabelDrafts[share.id], expires_at: fromDateTimeLocal(shareExpiryDrafts[share.id]) });
    await loadMemoShares(memoForm.id);
    await reloadCurrentRelationActivity();
    setNotice('Lien public mis à jour.');
  } catch (err) {
    setError(err, 'Modification du lien public impossible.');
  } finally {
    busy.value = '';
  }
}

async function revokeMemoShare(share: MemoShare): Promise<void> {
  if (!memoForm.id || !canMemoShare.value) return;
  busy.value = `memo.share.revoke.${share.id}`;
  try {
    await adminApi.delete(`/business/memos/${memoForm.id}/shares/${share.id}`);
    await loadMemoShares(memoForm.id);
    await reloadRelationsView();
    await reloadCurrentRelationActivity();
    setNotice(share.share_type === 'public_link' ? 'Lien public révoqué.' : 'Partage interne révoqué.');
  } catch (err) {
    setError(err, 'Révocation du partage impossible.');
  } finally {
    busy.value = '';
  }
}

async function revokePublicMemoShares(): Promise<void> {
  if (!memoForm.id || !canMemoShare.value) return;
  busy.value = 'memo.share.public.revoke';
  try {
    await adminApi.delete(`/business/memos/${memoForm.id}/public-share`);
    oneTimeShareUrl.value = '';
    await loadMemoShares(memoForm.id);
    await reloadCurrentRelationActivity();
    setNotice('Liens publics révoqués.');
  } catch (err) {
    setError(err, 'Révocation du lien public impossible.');
  } finally {
    busy.value = '';
  }
}

async function copyShareUrl(): Promise<void> {
  if (!oneTimeShareUrl.value) return;
  try {
    await navigator.clipboard.writeText(oneTimeShareUrl.value);
    setNotice('URL copiée.');
  } catch (_) {
    setError(new Error('Copie presse-papiers indisponible.'));
  }
}

function onContactImportFile(event: Event): void {
  const input = event.target as HTMLInputElement;
  contactImportFile.value = input.files?.[0] || null;
  contactImportReport.value = null;
}

async function runContactImport(forceReal = false): Promise<void> {
  if (!canCrmManage.value || !contactImportFile.value) return;
  busy.value = forceReal ? 'contacts.import.real' : 'contacts.import.dry-run';
  try {
    const form = new FormData();
    form.append('file', contactImportFile.value);
    form.append('dry_run', forceReal ? '0' : '1');
    form.append('confirm_import', forceReal ? '1' : '0');
    form.append('create_companies', contactImportOptions.create_companies ? '1' : '0');
    form.append('update_existing', contactImportOptions.update_existing ? '1' : '0');
    const response = await adminApi.upload<Record<string, unknown>>('/business/contacts/import.csv', form);
    contactImportReport.value = response.data;
    await Promise.all([loadCompanies(), loadContacts()]);
    setNotice(forceReal ? 'Import contacts exécuté.' : 'Dry-run import contacts terminé.');
  } catch (err) {
    setError(err, 'Import contacts impossible.');
  } finally {
    busy.value = '';
  }
}

function resetContactImport(): void {
  contactImportFile.value = null;
  contactImportReport.value = null;
  if (contactImportInput.value) contactImportInput.value.value = '';
}

function editMailingList(list: MailingList): void {
  Object.assign(mailingListForm, { id: list.id, name: valueText(list.name), list_key: valueText(list.list_key), channel: valueText(list.channel || 'email'), description: valueText(list.description), status: valueText(list.status || 'active'), member_contact_id: '' });
  void loadMailingListMembers(list.id);
}

async function saveMailingList(): Promise<void> {
  if (!canMailingManage.value) return;
  busy.value = 'mailing.list.save';
  try {
    const payload = { name: mailingListForm.name, list_key: mailingListForm.list_key, channel: mailingListForm.channel, description: mailingListForm.description, status: mailingListForm.status };
    const response = mailingListForm.id
      ? await adminApi.patch<{ list: MailingList }>(`/business/mailing/lists/${mailingListForm.id}`, payload)
      : await adminApi.post<{ list: MailingList }>('/business/mailing/lists', payload);
    editMailingList(response.data.list);
    await loadMailing();
    setNotice(mailingListForm.id ? 'Liste enregistrée.' : 'Liste créée.');
  } catch (err) {
    setError(err, 'Enregistrement liste impossible.');
  } finally {
    busy.value = '';
  }
}

async function loadMailingListMembers(listId: number): Promise<void> {
  if (!canMailingRead.value) return;
  try {
    const response = await adminApi.get<{ members: MailingMember[] }>(`/business/mailing/lists/${listId}`);
    mailingMembers.value = response.data.members || [];
  } catch (err) {
    setError(err, 'Membres de liste indisponibles.');
  }
}

async function addMailingMember(): Promise<void> {
  if (!mailingListForm.id || !canMailingManage.value) return;
  busy.value = 'mailing.member.add';
  try {
    await adminApi.post(`/business/mailing/lists/${mailingListForm.id}/members`, { contact_id: intOrNull(mailingListForm.member_contact_id) });
    mailingListForm.member_contact_id = '';
    await loadMailingListMembers(mailingListForm.id);
    setNotice('Contact ajouté à la liste.');
  } catch (err) {
    setError(err, 'Ajout membre impossible.');
  } finally {
    busy.value = '';
  }
}

async function removeMailingMember(member: MailingMember): Promise<void> {
  if (!mailingListForm.id || !canMailingManage.value) return;
  busy.value = `mailing.member.remove.${member.contact_id}`;
  try {
    await adminApi.delete(`/business/mailing/lists/${mailingListForm.id}/members/${member.contact_id}`);
    await loadMailingListMembers(mailingListForm.id);
    setNotice('Contact retiré de la liste.');
  } catch (err) {
    setError(err, 'Retrait membre impossible.');
  } finally {
    busy.value = '';
  }
}

function editCampaign(campaign: Campaign): void {
  Object.assign(campaignForm, { id: campaign.id, list_id: valueText(campaign.list_id), name: valueText(campaign.name), channel: valueText(campaign.channel || 'email'), subject: valueText(campaign.subject), body_text: valueText(campaign.body_text), scheduled_at: valueText(campaign.scheduled_at) });
  campaignPreview.value = [];
}

async function saveCampaign(): Promise<void> {
  if (!canMailingManage.value) return;
  busy.value = 'mailing.campaign.save';
  try {
    const payload = { list_id: intOrNull(campaignForm.list_id), name: campaignForm.name, channel: campaignForm.channel, subject: campaignForm.subject, body_text: campaignForm.body_text, scheduled_at: campaignForm.scheduled_at };
    const response = campaignForm.id
      ? await adminApi.patch<{ campaign: Campaign }>(`/business/mailing/campaigns/${campaignForm.id}`, payload)
      : await adminApi.post<{ campaign: Campaign }>('/business/mailing/campaigns', payload);
    editCampaign(response.data.campaign);
    await loadMailing();
    setNotice(campaignForm.id ? 'Campagne enregistrée.' : 'Campagne créée.');
  } catch (err) {
    setError(err, 'Enregistrement campagne impossible.');
  } finally {
    busy.value = '';
  }
}

async function previewCampaignRecipients(): Promise<void> {
  if (!campaignForm.id || !canMailingRead.value) return;
  busy.value = 'mailing.campaign.preview';
  try {
    const response = await adminApi.post<{ recipients: Array<Record<string, unknown>> }>(`/business/mailing/campaigns/${campaignForm.id}/preview-recipients`, {});
    campaignPreview.value = response.data.recipients || [];
    setNotice(`${campaignPreview.value.length} destinataire(s) éligible(s).`);
  } catch (err) {
    setError(err, 'Prévisualisation destinataires impossible.');
  } finally {
    busy.value = '';
  }
}

async function enqueueCampaign(): Promise<void> {
  if (!campaignForm.id || !canMailingManage.value) return;
  busy.value = 'mailing.campaign.enqueue';
  try {
    const response = await adminApi.post<{ queued_count?: number; eligible_count?: number }>(`/business/mailing/campaigns/${campaignForm.id}/enqueue`, {});
    await loadMailing();
    setNotice(`${response.data.queued_count ?? 0} message(s) mis en file sur ${response.data.eligible_count ?? 0} éligible(s).`);
  } catch (err) {
    setError(err, 'Mise en file campagne impossible.');
  } finally {
    busy.value = '';
  }
}

async function sendTestMessage(): Promise<void> {
  if (!canMessagingAdmin.value) return;
  busy.value = 'messaging.send-test';
  try {
    const response = await adminApi.post<{ send_result?: Record<string, unknown> }>('/business/messaging/send-test', { ...messageTestForm });
    await loadMessaging();
    const ok = response.data.send_result?.success === true ? 'envoyé' : 'traité';
    setNotice(`Message de test ${ok}.`);
  } catch (err) {
    setError(err, 'Message de test impossible.');
  } finally {
    busy.value = '';
  }
}

watch(() => props.initialTab, (tab) => {
  const normalized = normalizeBusinessTab(tab);
  if (activeTab.value !== normalized) activeTab.value = normalized;
});
watch(activeTab, () => { void loadCurrentTab(); });
watch(() => messageTestForm.channel, () => {
  if (activeModal.value === 'message') void loadRelationMessagePreview();
});
watch(activeModal, async (modal) => {
  if (typeof document === 'undefined') return;
  if (modal) {
    previousBodyOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    await nextTick();
    modalPanel.value?.focus();
    return;
  }
  document.body.style.overflow = previousBodyOverflow;
});
watch(() => context.siteId, () => {
  void Promise.all([loadDashboard(), loadCompanies(), loadContacts(), loadMemos(), loadMailing(), loadMessaging()]);
});

onMounted(async () => {
  await loadCurrentTab();
});

onBeforeUnmount(() => {
  if (typeof document !== 'undefined') {
    document.body.style.overflow = previousBodyOverflow;
  }
});
</script>

<template>
  <section class="page-stack business-crm">
    <PageHeader title="Business" intro="CRM, catalogue produits, offres, mailing simple et outbox messaging.">
      <template #actions>
        <button class="btn ghost" type="button" :disabled="loading" @click="loadCurrentTab">Rafraîchir</button>
      </template>
    </PageHeader>

    <ApiFeedback :error="error" :success="success" />

    <nav class="editor-tabs business-tabs" aria-label="Sections Business">
      <button v-for="tab in tabs" :key="tab.key" type="button" :class="['editor-tab', { active: activeTab === tab.key }]" :disabled="!tab.permission()" @click="activeTab = tab.key">
        {{ tab.label }}
      </button>
    </nav>

    <section v-if="activeTab === 'relations' && relationsPanel !== 'main'" class="business-relations-shell">
      <div class="business-secondary-head">
        <button class="btn ghost small" type="button" @click="openRelationsMain">Retour aux relations</button>
        <span>{{ relationsPanel === 'memos' ? 'Liste mémos et commentaires' : 'Relations' }}</span>
      </div>
    </section>

    <section v-if="activeTab === 'dashboard'" class="business-dashboard">
      <div class="business-dashboard-commandbar">
        <form class="business-dashboard-search" @submit.prevent="runDashboardSearch">
          <div class="business-dashboard-search-control">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 21-4.35-4.35m1.35-5.65a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
            <input v-model="dashboardSearch.q" type="search" aria-label="Recherche rapide Business" placeholder="Recherche rapide relation, mémo ou message">
          </div>
          <button class="btn small" type="submit" :disabled="busy === 'dashboard.search'">Rechercher</button>
        </form>
        <div class="business-dashboard-actions">
          <button class="btn primary small business-action-btn" type="button" @click="startNewRelation" aria-label="Nouvelle relation">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-database-add" viewBox="0 0 16 16"><path d="M12.5 16a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7m.5-5v1h1a.5.5 0 0 1 0 1h-1v1a.5.5 0 0 1-1 0v-1h-1a.5.5 0 0 1 0-1h1v-1a.5.5 0 0 1 1 0"/><path d="M12.096 6.223A5 5 0 0 0 13 5.698V7c0 .289-.213.654-.753 1.007a4.5 4.5 0 0 1 1.753.25V4c0-1.007-.875-1.755-1.904-2.223C11.022 1.289 9.573 1 8 1s-3.022.289-4.096.777C2.875 2.245 2 2.993 2 4v9c0 1.007.875 1.755 1.904 2.223C4.978 15.71 6.427 16 8 16c.536 0 1.058-.034 1.555-.097a4.5 4.5 0 0 1-.813-.927Q8.378 15 8 15c-1.464 0-2.766-.27-3.682-.687C3.356 13.875 3 13.373 3 13v-1.302c.271.202.58.378.904.525C4.978 12.71 6.427 13 8 13h.027a4.6 4.6 0 0 1 0-1H8c-1.464 0-2.766-.27-3.682-.687C3.356 10.875 3 10.373 3 10V8.698c.271.202.58.378.904.525C4.978 9.71 6.427 10 8 10q.393 0 .774-.024a4.5 4.5 0 0 1 1.102-1.132C9.298 8.944 8.666 9 8 9c-1.464 0-2.766-.27-3.682-.687C3.356 7.875 3 7.373 3 7V5.698c.271.202.58.378.904.525C4.978 6.711 6.427 7 8 7s3.022-.289 4.096-.777M3 4c0-.374.356-.875 1.318-1.313C5.234 2.271 6.536 2 8 2s2.766.27 3.682.687C12.644 3.125 13 3.627 13 4c0 .374-.356.875-1.318 1.313C10.766 5.729 9.464 6 8 6s-2.766-.27-3.682-.687C3.356 4.875 3 4.373 3 4"/></svg>
            <span>Nouvelle relation</span>
          </button>
          <button class="btn small business-action-btn" type="button" @click="startDashboardMemo" aria-label="Nouveau mémo">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-chat-square-text" viewBox="0 0 16 16"><path d="M14 1a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1h-2.5a2 2 0 0 0-1.6.8L8 14.333 6.1 11.8a2 2 0 0 0-1.6-.8H2a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1zM2 0a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2.5a1 1 0 0 1 .8.4l1.9 2.533a1 1 0 0 0 1.6 0l1.9-2.533a1 1 0 0 1 .8-.4H14a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2z"/><path d="M3 3.5a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9a.5.5 0 0 1-.5-.5M3 6a.5.5 0 0 1 .5-.5h9a.5.5 0 0 1 0 1h-9A.5.5 0 0 1 3 6m0 2.5a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5"/></svg>
            <span>Nouveau mémo</span>
          </button>
          <button class="btn small business-action-btn" type="button" @click="startDashboardMessage" aria-label="Envoyer message">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-send-plus" viewBox="0 0 16 16"><path d="M15.964.686a.5.5 0 0 0-.65-.65L.767 5.855a.75.75 0 0 0-.124 1.329l4.995 3.178 1.531 2.406a.5.5 0 0 0 .844-.536L6.637 10.07l7.494-7.494-1.895 4.738a.5.5 0 1 0 .928.372zm-2.54 1.183L5.93 9.363 1.591 6.602z"/><path d="M16 12.5a3.5 3.5 0 1 1-7 0 3.5 3.5 0 0 1 7 0m-3.5-2a.5.5 0 0 0-.5.5v1h-1a.5.5 0 0 0 0 1h1v1a.5.5 0 0 0 1 0v-1h1a.5.5 0 0 0 0-1h-1v-1a.5.5 0 0 0-.5-.5"/></svg>
            <span>Envoyer message</span>
          </button>
        </div>
      </div>

      <div v-if="dashboardSearchResults.length" class="business-dashboard-results">
        <button v-for="item in dashboardSearchResults" :key="`${item.group}-${item.id}`" type="button" @click="openDashboardSearchItem(item)">
          <strong>{{ item.title || item.subtitle || `#${item.id}` }}</strong>
          <span>{{ [item.group, item.subtitle, item.status].filter(Boolean).join(' · ') }}</span>
          <small v-if="item.excerpt">{{ item.excerpt }}</small>
        </button>
      </div>

      <div class="business-dashboard-counters">
        <article v-for="status in ['prospect', 'client', 'supplier', 'former_client', 'other']" :key="status">
          <span>{{ statusLabel(status) }}</span>
          <strong>{{ dashboard?.counters?.[status] ?? 0 }}</strong>
        </article>
      </div>

      <div v-if="dashboard?.alerts?.length" class="business-dashboard-alerts">
        <article v-for="alert in dashboard.alerts" :key="`${alert.code}-${alert.channel || alert.count || ''}`">
          <StatusBadge :status="alert.level || 'info'" />
          <span>{{ alert.message }}</span>
        </article>
      </div>

      <div class="business-dashboard-grid">
        <section class="business-panel">
          <header class="business-panel-head"><div><p class="eyebrow">Récent</p><h2>Relations</h2></div></header>
          <div class="business-list">
            <article v-for="relation in dashboard?.recent_relations || []" :key="`${relation.type}-${relation.id}`" class="business-list-row">
              <strong><button class="business-link" type="button" @click="openDashboardRelation(relation)">{{ relation.display_name }}</button></strong>
              <span>{{ relation.company?.name || relation.primary_email || relation.phone || '-' }}</span>
              <StatusBadge :status="relation.status || 'other'" />
            </article>
            <p v-if="!(dashboard?.recent_relations || []).length" class="business-empty">Aucune relation récente.</p>
          </div>
        </section>

        <section class="business-panel">
          <header class="business-panel-head"><div><p class="eyebrow">Récent</p><h2>Mémos</h2></div></header>
          <div class="business-list">
            <MemoCard
              v-for="memo in dashboard?.latest_memos || []"
              :key="memo.id"
              :title="memo.title"
              :context="String(memo.relation_name || '')"
              :body="memo.body"
              :date="String(memo.activity_at || memo.created_at || '')"
            />
            <p v-if="!(dashboard?.latest_memos || []).length" class="business-empty">Aucun mémo récent.</p>
          </div>
        </section>

        <section class="business-panel">
          <header class="business-panel-head"><div><p class="eyebrow">Récent</p><h2>Messages</h2></div></header>
          <div class="business-list">
            <MessageCard
              v-for="message in dashboard?.latest_messages || []"
              :key="message.id"
              :title="String(message.subject || `Message ${message.channel || ''}`)"
              :context="String(message.relation_name || message.recipient_value || '')"
              :status="message.status || 'pending'"
            />
            <p v-if="!(dashboard?.latest_messages || []).length" class="business-empty">Aucun message récent.</p>
          </div>
        </section>
      </div>
    </section>

    <section v-if="activeTab === 'relations' && relationsPanel === 'main'" class="business-relations-unified">
      <BusinessRelationsView
        ref="relationsView"
        @new-relation="startNewRelation"
        @open-import="openImportModal"
        @open-export="openExportModal"
        @open-memos-global="openRelationsMemos"
        @open-comments="openCommentsList"
        @open-messages-list="openMessages"
        @edit-company="editRelationCompany"
        @edit-contact="editRelationContact"
        @new-memo="startRelationMemo"
        @open-memos="openRelationMemos"
        @new-message="openRelationMessage"
        @open-consent="openRelationConsent"
        @archive-company="archiveRelationCompany"
        @archive-contact="archiveRelationContact"
      />
    </section>


    <Teleport to="body">
      <div v-if="activeModal" class="business-modal-backdrop" role="presentation" @click.self="closeModal()">
        <section ref="modalPanel" class="business-modal" role="dialog" aria-modal="true" aria-labelledby="business-modal-title" tabindex="-1" @keydown.escape.stop.prevent="closeModal()">
          <header class="business-modal-head">
            <div>
              <p class="eyebrow">Business CRM</p>
              <h2 v-if="activeModal === 'relation'" id="business-modal-title">Fiche relation</h2>
              <h2 v-else-if="activeModal === 'memo'" id="business-modal-title">{{ memoForm.id ? 'Éditer mémo' : 'Nouveau mémo' }}</h2>
              <h2 v-else-if="activeModal === 'import'" id="business-modal-title">Importer des contacts</h2>
              <h2 v-else-if="activeModal === 'export'" id="business-modal-title">Exporter les relations</h2>
              <h2 v-else-if="activeModal === 'message'" id="business-modal-title">Nouveau message</h2>
              <h2 v-else-if="activeModal === 'consent'" id="business-modal-title">Consentements</h2>
              <h2 v-else id="business-modal-title">Confirmation</h2>
            </div>
            <div class="business-modal-head-actions">
              <button v-if="activeModal === 'relation' && relationModalMode === 'edit' && canEditCurrentRelation" class="btn primary small" type="button" :disabled="busy === 'contact.save' || busy === 'company.save'" @click="saveRelationEdit">Enregistrer</button>
              <button class="business-modal-close" type="button" aria-label="Fermer" @click="closeModal()">×</button>
            </div>
          </header>

          <div class="business-modal-body">
          <template v-if="activeModal === 'relation'">
            <QuickCreateRelationDrawer
              v-if="relationModalMode === 'create'"
              v-model:draft-type="relationDraftType"
              v-model:inline-company-name="quickRelation.inline_company_name"
              v-model:tags="quickRelation.tags"
              :contact-form="contactForm"
              :company-form="companyForm"
              :companies="editableCompanies"
              :statuses="crmStatuses"
              :can-manage="canCrmManage"
              :busy="busy"
              @save-contact="saveQuickRelationContact"
              @save-company="saveQuickRelationCompany"
            />

            <template v-else-if="relationModalMode === 'view'">
              <RelationHeader
                :name="currentRelation.display_name"
                :type-label="relationDraftType === 'company' ? 'Entreprise' : 'Individu'"
                :status="currentRelation.status"
                :context-line="relationHeaderContext"
                :email="currentRelation.primary_email || undefined"
                :phone="[currentRelation.phone, currentRelation.mobile].filter(Boolean).join(' / ')"
                :memo-count="Number(currentRelation.memo_count || 0)"
                :shared-memo-count="Number(currentRelation.shared_memo_count || 0)"
                :can-message="relationDraftType === 'contact' && Boolean(currentRelation.id) && canMessagingSend"
                :can-memo="Boolean(currentRelation.id) && canMemoManage"
                :can-edit="canEditCurrentRelation"
                @message="openRelationMessage(currentRelation)"
                @memo="startRelationMemo(currentRelation)"
                @share="openRelationMemos(currentRelation)"
                @edit="beginRelationEdit"
                @open-memos="openRelationMemos(currentRelation)"
              />

              <div class="business-modal-grid">
                <RelationInfoCard title="Informations clés" :rows="relationInfoRows" />
                <RelationInfoCard title="Coordonnées" :rows="relationContactRows" />
                <RelationInfoCard title="Audit" :rows="relationAuditRows" />
                <RelationInfoCard title="À venir" :rows="[{ label: 'Commandes', value: 'Placeholder' }, { label: 'Factures', value: 'Placeholder' }]" />
              </div>

              <RelationLinkedEntities
                v-if="relationDraftType === 'company' && !selectedCompany?.is_system"
                title="Contacts liés"
                empty-label="Aucun contact lié."
                :entities="linkedRelationEntities"
                @open="(entity) => editRelationContact({ type: 'contact', id: entity.id, display_name: entity.label })"
                @open-memos="(entity) => openRelationMemos({ type: 'contact', id: entity.id, display_name: entity.label })"
              />

              <RelationLinkedEntities
                v-if="relationDraftType === 'contact'"
                title="Organisation liée"
                empty-label="Relation rattachée à Individus."
                :entities="contactLinkedCompany"
                @open="(entity) => editRelationCompany({ type: 'company', id: entity.id, display_name: entity.label })"
                @open-memos="(entity) => openRelationMemos({ type: 'company', id: entity.id, display_name: entity.label })"
              />

              <RelationTimeline
                title="Derniers mémos et activité"
                empty-label="Aucune activité récente pour cette relation."
                :items="relationTimelineItems"
                :can-add-memo="Boolean(currentRelation.id) && canMemoRead"
                action-label="Voir tous les mémos"
                @add-memo="openRelationMemos(currentRelation)"
              />
            </template>

            <template v-else>
              <nav class="business-relation-type-tabs" aria-label="Type de relation">
                <button type="button" :class="['editor-tab', { active: relationDraftType === 'contact' }]" @click="changeRelationDraftType('contact')">Individu</button>
                <button type="button" :class="['editor-tab', { active: relationDraftType === 'company' }]" @click="changeRelationDraftType('company')">Entreprise</button>
              </nav>

              <form v-if="relationDraftType === 'contact'" class="business-form" @submit.prevent="saveRelationEdit">
                <label>Entreprise<select v-model="contactForm.company_id" :disabled="!canCrmManage"><option value="">Individus</option><option v-for="company in editableCompanies" :key="company.id" :value="company.id">{{ company.name }}</option></select></label>
                <label>Statut<select v-model="contactForm.status" :disabled="!canCrmManage"><option v-for="status in crmStatuses" :key="status" :value="status">{{ statusLabel(status) }}</option></select></label>
                <label>Prénom<input v-model="contactForm.first_name" type="text" :disabled="!canCrmManage"></label>
                <label>Nom<input v-model="contactForm.last_name" type="text" :disabled="!canCrmManage"></label>
                <label class="business-span">Nom affiché<input v-model="contactForm.display_name" type="text" :disabled="!canCrmManage" required></label>
                <label>Email<input v-model="contactForm.email" type="email" :disabled="!canCrmManage"></label>
                <label>Téléphone<input v-model="contactForm.phone" type="tel" :disabled="!canCrmManage"></label>
                <label>WhatsApp / mobile<input v-model="contactForm.mobile" type="tel" :disabled="!canCrmManage"></label>
                <label>Langue<input v-model="contactForm.preferred_language" type="text" :disabled="!canCrmManage" placeholder="fr"></label>
                <label v-if="iamUsers.length">Utilisateur IAM<select v-model="contactForm.iam_user_id" :disabled="!canCrmManage"><option value="">Aucun compte lié</option><option v-for="user in availableIamUsers" :key="user.id" :value="user.id">{{ user.name || user.email }} (#{{ user.id }})</option></select></label>
                <label v-else>Utilisateur IAM<input v-model="contactForm.iam_user_id" type="number" min="1" :disabled="!canCrmManage" placeholder="optionnel"></label>
                <label>Fonction<input v-model="contactForm.job_title" type="text" :disabled="!canCrmManage"></label>
                <label class="business-span">Notes<textarea v-model="contactForm.notes" rows="3" :disabled="!canCrmManage"></textarea></label>
                <div class="business-meta business-span">
                  <span><strong>Créé par</strong>{{ iamUserLabel(selectedContact?.created_by_iam_user_id) }}</span>
                  <span><strong>Modifié par</strong>{{ iamUserLabel(selectedContact?.updated_by_iam_user_id) }}</span>
                  <span><strong>Créé le</strong>{{ auditDate(selectedContact?.created_at) }}</span>
                  <span><strong>Modifié le</strong>{{ auditDate(selectedContact?.updated_at) }}</span>
                </div>
              </form>

              <form v-else class="business-form" @submit.prevent="saveRelationEdit">
                <p v-if="isSystemCompanyForm" class="business-empty business-span">Entreprise système utilisée comme rattachement par défaut, non éditable.</p>
                <label class="business-span">Nom<input v-model="companyForm.name" type="text" :disabled="!canEditCurrentRelation" required></label>
                <label>Statut<select v-model="companyForm.status" :disabled="!canEditCurrentRelation"><option v-for="status in crmStatuses" :key="status" :value="status">{{ statusLabel(status) }}</option></select></label>
                <label>Email<input v-model="companyForm.email" type="email" :disabled="!canEditCurrentRelation"></label>
                <label>Téléphone<input v-model="companyForm.phone" type="tel" :disabled="!canEditCurrentRelation"></label>
                <label>Site web<input v-model="companyForm.website_url" type="url" :disabled="!canEditCurrentRelation"></label>
                <label class="business-span">Notes<textarea v-model="companyForm.notes" rows="4" :disabled="!canEditCurrentRelation"></textarea></label>
                <div class="business-meta business-span">
                  <span><strong>Créé par</strong>{{ iamUserLabel(selectedCompany?.created_by_iam_user_id) }}</span>
                  <span><strong>Modifié par</strong>{{ iamUserLabel(selectedCompany?.updated_by_iam_user_id) }}</span>
                  <span><strong>Créé le</strong>{{ auditDate(selectedCompany?.created_at) }}</span>
                  <span><strong>Modifié le</strong>{{ auditDate(selectedCompany?.updated_at) }}</span>
                </div>
                <div v-if="selectedCompany && !selectedCompany.is_system" class="business-linked business-span">
                  <h3>Contacts liés</h3>
                  <p>{{ linkedContacts.length }} contact(s), {{ linkedCompanyMemos.length }} mémo(s).</p>
                  <div class="business-preview">
                    <span v-for="contact in linkedContacts.slice(0, 8)" :key="contact.id">{{ contact.display_name }}</span>
                  </div>
                </div>
              </form>
            </template>
          </template>

          <template v-else-if="activeModal === 'memo'">
            <QuickCreateMemoDrawer
              v-if="!memoForm.id"
              v-model:public-share="quickMemo.public_share"
              :memo-form="memoForm"
              :companies="companies"
              :contacts="contacts"
              :can-manage="canMemoManage"
              :can-share="canMemoShare"
              :busy="busy"
              @save="saveQuickMemo"
            />
            <template v-else>
            <form class="business-form" @submit.prevent="saveMemo">
              <label class="business-span">Titre<input v-model="memoForm.title" type="text" :disabled="!canMemoManage" required></label>
              <label>Entreprise<select v-model="memoForm.company_id" :disabled="!canMemoManage"><option value="">—</option><option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}</option></select></label>
              <label>Contact<select v-model="memoForm.contact_id" :disabled="!canMemoManage"><option value="">—</option><option v-for="contact in contacts" :key="contact.id" :value="contact.id">{{ contact.display_name }}</option></select></label>
              <label>Visibilité<select v-model="memoForm.visibility" :disabled="!canMemoManage"><option v-for="visibility in memoVisibilities" :key="visibility" :value="visibility">{{ statusLabel(visibility) }}</option></select></label>
              <label class="business-span">Texte<textarea v-model="memoForm.body" rows="7" :disabled="!canMemoManage"></textarea></label>
              <div class="business-actions business-span"><button v-if="canMemoManage" class="btn primary small" type="submit" :disabled="busy === 'memo.save'">Enregistrer</button></div>
            </form>
            <div class="business-modal-grid">
              <section>
                <h3>Commentaires</h3>
                <p v-if="!memoForm.id" class="business-empty">Enregistrez le mémo avant d'ajouter des commentaires.</p>
                <div v-else class="business-list">
                  <p v-if="!memoComments.length" class="business-empty">Aucun commentaire.</p>
                  <article v-for="comment in memoComments" :key="comment.id" class="business-list-row">
                    <strong>#{{ comment.id }}</strong>
                    <span>{{ comment.body }}</span>
                    <small>{{ comment.updated_at ? `modifié ${comment.updated_at}` : comment.created_at }}</small>
                    <button v-if="canMemoManage" class="btn ghost small" type="button" @click="startEditMemoComment(comment)">Éditer</button>
                  </article>
                </div>
                <form v-if="memoForm.id && canMemoManage" class="business-inline-form" @submit.prevent="addMemoComment">
                  <input v-model="memoForm.comment" type="text" placeholder="Ajouter un commentaire">
                  <button class="btn small" type="submit" :disabled="busy === 'memo.comment'">Ajouter</button>
                </form>
                <form v-if="memoCommentEdit.id && canMemoManage" class="business-inline-form" @submit.prevent="updateMemoComment">
                  <input v-model="memoCommentEdit.body" type="text" placeholder="Modifier le commentaire">
                  <button class="btn small" type="submit" :disabled="busy === 'memo.comment.update'">Enregistrer</button>
                  <button class="btn ghost small" type="button" @click="Object.assign(memoCommentEdit, { id: 0, body: '' })">Annuler</button>
                </form>
              </section>
              <section>
                <h3>Partages</h3>
                <p v-if="!canMemoShare" class="business-empty">Permission partage mémo requise.</p>
                <template v-else-if="memoForm.id">
                  <form class="business-inline-form" @submit.prevent="shareMemoInternally">
                    <input v-model="memoForm.share_iam_user_ids" type="text" placeholder="IDs utilisateurs IAM">
                    <button class="btn small" type="submit" :disabled="busy === 'memo.share.internal'">Partager</button>
                  </form>
                  <form class="business-form business-form--single" @submit.prevent="createPublicMemoShare">
                    <label>Libellé public<input v-model="memoForm.public_label" type="text" placeholder="Client, partenaire..."></label>
                    <label>Expiration<input v-model="memoForm.public_expires_at" type="datetime-local" step="60"></label>
                    <button class="btn small" type="submit" :disabled="busy === 'memo.share.public'">Créer lien public</button>
                  </form>
                  <div v-if="oneTimeShareUrl" class="business-copy-box">
                    <strong>Lien affiché une seule fois</strong>
                    <code>{{ oneTimeShareUrl }}</code>
                    <button class="btn small" type="button" @click="copyShareUrl">Copier</button>
                  </div>
                  <div class="business-list">
                    <article v-for="share in memoShares" :key="share.id" class="business-list-row business-list-row--share">
                      <strong>{{ shareTypeLabel(share) }}</strong>
                      <span>
                        {{ shareMainLabel(share) }}
                        <small>{{ shareDetailLabel(share) }}</small>
                      </span>
                      <small>{{ shareStatusLabel(share) }}</small>
                      <div class="business-share-actions">
                        <form v-if="share.share_type === 'public_link' && !share.revoked_at" class="business-share-edit" @submit.prevent="updateMemoPublicShare(share)">
                          <input v-model="shareLabelDrafts[share.id]" type="text" placeholder="Libellé">
                          <input v-model="shareExpiryDrafts[share.id]" type="datetime-local" step="60" aria-label="Expiration du lien public">
                          <button class="btn ghost small" type="submit" :disabled="busy === `memo.share.update.${share.id}`">Enregistrer</button>
                        </form>
                        <button v-if="!share.revoked_at" class="btn danger small" type="button" :disabled="busy === `memo.share.revoke.${share.id}`" @click="revokeMemoShare(share)">Révoquer</button>
                      </div>
                    </article>
                  </div>
                </template>
              </section>
            </div>
            </template>
          </template>

          <template v-else-if="activeModal === 'import'">
            <div class="business-import">
              <input type="file" accept=".csv,text/csv" @change="onContactImportFile">
              <label class="business-check"><input v-model="contactImportOptions.create_companies" type="checkbox"> Créer les entreprises absentes</label>
              <label class="business-check"><input v-model="contactImportOptions.update_existing" type="checkbox"> Mettre à jour les contacts existants</label>
              <button class="btn small" type="button" :disabled="!contactImportFile || busy === 'contacts.import.dry-run'" @click="runContactImport(false)">Dry-run</button>
              <button class="btn primary small" type="button" :disabled="!contactImportFile || !contactImportReport || contactImportErrors.length > 0 || busy === 'contacts.import.real'" @click="runContactImport(true)">Importer</button>
            </div>
            <div v-if="contactImportReport" class="business-import-report">
              <strong>{{ contactImportReport.dry_run ? 'Dry-run' : 'Import' }} : {{ contactImportReport.valid_rows ?? 0 }} valide(s), {{ contactImportReport.skipped ?? 0 }} erreur(s)</strong>
              <div v-if="contactImportErrors.length" class="business-list">
                <article v-for="entry in contactImportErrors.slice(0, 8)" :key="`${entry.line}-${entry.message}`" class="business-list-row">
                  <strong>Ligne {{ entry.line }}</strong><span>{{ entry.message }}</span>
                </article>
              </div>
            </div>
          </template>

          <template v-else-if="activeModal === 'export'">
            <div class="business-actions">
              <a v-if="canCrmManage" class="btn small" :href="contactsExportHref">Exporter contacts CSV</a>
              <a v-if="canCrmManage" class="btn small" :href="companiesExportHref">Exporter entreprises CSV</a>
            </div>
          </template>

          <SendMessageDrawer
            v-else-if="activeModal === 'message'"
            :form="messageTestForm"
            :relation-name="messageRelation?.display_name || ''"
            :channels="relationMessageChannelRows"
            :providers="relationMessageProviders"
            :preview="messagePreview"
            :messages="relationMessages"
            :can-send="canSendRelationMessage"
            :busy="busy"
            :preview-notice="messagePreviewNotice()"
            @save="sendRelationMessage"
            @open-consent="messageRelation && openRelationConsent(messageRelation)"
          />

          <template v-else-if="activeModal === 'consent'">
            <p v-if="!contactForm.id" class="business-empty">Ouvrez un contact pour gérer ses consentements.</p>
            <div v-else class="business-consents">
              <form v-for="channel in channels" :key="channel" class="business-consent-row" @submit.prevent="saveConsent(channel)">
                <strong>{{ channel }}</strong>
                <input v-model="consentForm[channel].value" type="text" :placeholder="channel === 'email' ? 'adresse@example.test' : 'identifiant ou numéro'" :disabled="!canCrmManage">
                <select v-model="consentForm[channel].consent_status" :disabled="!canCrmManage">
                  <option v-for="status in consentStatuses" :key="status" :value="status">{{ statusLabel(status) }}</option>
                </select>
                <input v-model="consentForm[channel].evidence" type="text" placeholder="preuve / source" :disabled="!canCrmManage">
                <label class="business-check"><input v-model="consentForm[channel].is_verified" type="checkbox" :disabled="!canCrmManage"> Vérifié</label>
                <button v-if="canCrmManage" class="btn small" type="submit" :disabled="busy === `consent.${channel}`">OK</button>
              </form>
            </div>
          </template>

          <template v-else-if="activeModal === 'archive'">
            <p>Archiver {{ archiveRelation?.display_name || 'cette relation' }} ?</p>
            <div class="business-actions">
              <button class="btn ghost small" type="button" @click="closeModal()">Annuler</button>
              <button class="btn primary small" type="button" @click="confirmArchiveRelation">Archiver</button>
            </div>
          </template>
          </div>
        </section>
      </div>
    </Teleport>

    <section v-if="activeTab === 'relations' && relationsPanel === 'memos'" class="business-grid">
      <div class="business-panel business-panel--wide">
        <header class="business-panel-head">
          <div>
            <p class="eyebrow">{{ relationMemoFilter ? 'CRM · relation filtrée' : 'CRM' }}</p>
            <h2>{{ relationMemoFilter ? `Mémos · ${relationMemoFilter.display_name}` : 'Mémos' }}</h2>
          </div>
          <form class="business-filters" @submit.prevent="loadMemos">
            <input v-model="memoFilter.q" type="search" placeholder="Recherche titre ou texte">
            <select v-model="memoFilter.company_id" :disabled="Boolean(relationMemoFilter)"><option value="">Toutes entreprises</option><option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}</option></select>
            <select v-model="memoFilter.contact_id" :disabled="Boolean(relationMemoFilter)"><option value="">Tous contacts</option><option v-for="contact in contacts" :key="contact.id" :value="contact.id">{{ contact.display_name }}</option></select>
            <button class="btn small" type="submit">Filtrer</button>
            <button v-if="relationMemoFilter" class="btn ghost small" type="button" @click="openRelationsMemos()">Tous les mémos</button>
          </form>
        </header>
        <div v-if="!canMemoRead" class="business-empty">Permission lecture mémos requise.</div>
        <div v-else-if="!memos.length" class="business-empty">Aucun mémo trouvé.</div>
        <table v-else class="business-table">
          <thead><tr><th>Titre</th><th>Cible</th><th>Visibilité</th><th>Date</th><th></th></tr></thead>
          <tbody>
            <tr v-for="memo in memos" :key="memo.id" :class="{ selected: memo.id === memoForm.id }">
              <td><button class="business-link" type="button" @click="editMemo(memo)">{{ memo.title }}</button></td>
              <td>{{ memo.contact_id ? contactName(memo.contact_id) : companyName(memo.company_id) }}</td>
              <td><StatusBadge :status="memo.visibility" /></td>
              <td>{{ memo.created_at || '—' }}</td>
              <td class="text-end"><button v-if="canMemoManage" class="btn ghost small" type="button" :disabled="busy === `memo.archive.${memo.id}`" @click="archiveMemo(memo)">Archiver</button></td>
            </tr>
          </tbody>
        </table>
      </div>

      <form class="business-panel" @submit.prevent="saveMemo">
        <header class="business-panel-head">
          <div><p class="eyebrow">{{ memoForm.id ? `#${memoForm.id}` : 'Nouveau' }}</p><h2>Mémo</h2></div>
          <button v-if="canMemoManage" class="btn primary small" type="submit" :disabled="busy === 'memo.save'">Enregistrer</button>
        </header>
        <div class="business-form">
          <label class="business-span">Titre<input v-model="memoForm.title" type="text" :disabled="!canMemoManage" required></label>
          <label>Entreprise<select v-model="memoForm.company_id" :disabled="!canMemoManage"><option value="">—</option><option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}</option></select></label>
          <label>Contact<select v-model="memoForm.contact_id" :disabled="!canMemoManage"><option value="">—</option><option v-for="contact in contacts" :key="contact.id" :value="contact.id">{{ contact.display_name }}</option></select></label>
          <label>Visibilité<select v-model="memoForm.visibility" :disabled="!canMemoManage"><option v-for="visibility in memoVisibilities" :key="visibility" :value="visibility">{{ statusLabel(visibility) }}</option></select></label>
          <label class="business-span">Texte<textarea v-model="memoForm.body" rows="8" :disabled="!canMemoManage"></textarea></label>
        </div>
        <div class="business-actions"><button class="btn ghost small" type="button" @click="resetMemoForm">Nouveau mémo</button></div>
      </form>

      <div class="business-panel">
        <header class="business-panel-head"><div><p class="eyebrow">Commentaires</p><h2>{{ selectedMemo?.title || 'Mémo' }}</h2></div></header>
        <div v-if="!memoForm.id" class="business-empty">Ouvrez un mémo pour voir les commentaires.</div>
        <template v-else>
          <div class="business-list">
            <p v-if="!memoComments.length" class="business-empty">Aucun commentaire.</p>
            <article v-for="comment in memoComments" :key="comment.id" class="business-list-row"><strong>#{{ comment.id }}</strong><span>{{ comment.body }}</span><small>{{ comment.created_at }}</small></article>
          </div>
          <form v-if="canMemoManage" class="business-inline-form" @submit.prevent="addMemoComment">
            <input v-model="memoForm.comment" type="text" placeholder="Ajouter un commentaire">
            <button class="btn small" type="submit" :disabled="busy === 'memo.comment'">Ajouter</button>
          </form>
        </template>
      </div>

      <div class="business-panel">
        <header class="business-panel-head"><div><p class="eyebrow">Partage</p><h2>Interne et lien public</h2></div></header>
        <div v-if="!canMemoShare" class="business-empty">Permission partage mémo requise.</div>
        <div v-else-if="!memoForm.id" class="business-empty">Ouvrez un mémo pour gérer les partages.</div>
        <template v-else>
          <form class="business-inline-form" @submit.prevent="shareMemoInternally">
            <input v-model="memoForm.share_iam_user_ids" type="text" placeholder="IDs utilisateurs IAM séparés par virgule">
            <button class="btn small" type="submit" :disabled="busy === 'memo.share.internal'">Partager</button>
          </form>
          <form class="business-form business-form--single" @submit.prevent="createPublicMemoShare">
            <label>Libellé public<input v-model="memoForm.public_label" type="text" placeholder="Client, partenaire..."></label>
            <label>Expiration<input v-model="memoForm.public_expires_at" type="datetime-local" step="60"></label>
            <button class="btn small" type="submit" :disabled="busy === 'memo.share.public'">Créer lien public</button>
          </form>
          <div v-if="oneTimeShareUrl" class="business-copy-box">
            <strong>Lien affiché une seule fois</strong>
            <code>{{ oneTimeShareUrl }}</code>
            <button class="btn small" type="button" @click="copyShareUrl">Copier</button>
          </div>
          <div class="business-actions"><button class="btn ghost small" type="button" :disabled="busy === 'memo.share.public.revoke'" @click="revokePublicMemoShares">Révoquer liens publics</button></div>
          <div class="business-list">
            <article v-for="share in memoShares" :key="share.id" class="business-list-row business-list-row--share">
              <strong>{{ shareTypeLabel(share) }}</strong>
              <span>
                {{ shareMainLabel(share) }}
                <small>{{ shareDetailLabel(share) }}</small>
              </span>
              <small>{{ shareStatusLabel(share) }}</small>
              <div class="business-share-actions">
                <form v-if="share.share_type === 'public_link' && !share.revoked_at" class="business-share-edit" @submit.prevent="updateMemoPublicShare(share)">
                  <input v-model="shareLabelDrafts[share.id]" type="text" placeholder="Libellé">
                  <input v-model="shareExpiryDrafts[share.id]" type="datetime-local" step="60" aria-label="Expiration du lien public">
                  <button class="btn ghost small" type="submit" :disabled="busy === `memo.share.update.${share.id}`">Enregistrer</button>
                </form>
                <button v-if="!share.revoked_at" class="btn danger small" type="button" :disabled="busy === `memo.share.revoke.${share.id}`" @click="revokeMemoShare(share)">Révoquer</button>
              </div>
            </article>
          </div>
        </template>
      </div>
    </section>

    <section v-if="activeTab === 'messages'" class="business-messages">
      <div class="business-messages-hero">
        <div>
          <p class="eyebrow">Communication</p>
          <h2>Messages, listes et campagnes</h2>
        </div>
        <div class="business-message-stats" aria-label="Résumé messages">
          <article><strong>{{ mailingLists.length }}</strong><span>listes</span></article>
          <article><strong>{{ campaigns.length }}</strong><span>campagnes</span></article>
          <article><strong>{{ outbox.length }}</strong><span>messages</span></article>
        </div>
      </div>

      <template v-if="canMailingRead">
        <section class="business-message-section">
          <header class="business-section-head">
            <div><p class="eyebrow">Mailing</p><h2>Listes de diffusion</h2></div>
            <button v-if="canMailingManage" class="btn ghost small" type="button" @click="resetMailingListForm">Nouvelle liste</button>
          </header>
          <div class="business-message-layout business-message-layout--lists">
            <div class="business-panel">
              <header class="business-panel-head"><div><p class="eyebrow">Sélection</p><h2>Listes</h2></div></header>
              <div class="business-list">
                <p v-if="!mailingLists.length" class="business-empty">Aucune liste.</p>
                <button v-for="list in mailingLists" :key="list.id" type="button" class="business-list-button business-list-button--stacked" :class="{ selected: list.id === mailingListForm.id }" @click="editMailingList(list)">
                  <strong>{{ list.name }}</strong><span>{{ list.channel }} · {{ list.status }}</span>
                </button>
              </div>
            </div>

            <form class="business-panel" @submit.prevent="saveMailingList">
              <header class="business-panel-head"><div><p class="eyebrow">{{ mailingListForm.id ? `#${mailingListForm.id}` : 'Nouvelle' }}</p><h2>Détails de la liste</h2></div><button v-if="canMailingManage" class="btn primary small" type="submit">Enregistrer</button></header>
              <div class="business-form">
                <label>Nom<input v-model="mailingListForm.name" type="text" :disabled="!canMailingManage" required></label>
                <label>Clé<input v-model="mailingListForm.list_key" type="text" :disabled="!canMailingManage" placeholder="auto"></label>
                <label>Canal<select v-model="mailingListForm.channel" :disabled="!canMailingManage"><option v-for="channel in channels" :key="channel" :value="channel">{{ channel }}</option></select></label>
                <label>Statut<select v-model="mailingListForm.status" :disabled="!canMailingManage"><option value="active">active</option><option value="archived">archived</option></select></label>
                <label class="business-span">Description<textarea v-model="mailingListForm.description" rows="3" :disabled="!canMailingManage"></textarea></label>
              </div>
            </form>

            <div class="business-panel">
              <header class="business-panel-head"><div><p class="eyebrow">Membres</p><h2>{{ selectedList?.name || 'Liste' }}</h2></div></header>
              <form v-if="mailingListForm.id && canMailingManage" class="business-inline-form" @submit.prevent="addMailingMember">
                <select v-model="mailingListForm.member_contact_id"><option value="">Choisir un contact</option><option v-for="contact in contacts" :key="contact.id" :value="contact.id">{{ contact.display_name }}</option></select>
                <button class="btn small" type="submit">Ajouter</button>
              </form>
              <div class="business-list">
                <p v-if="!mailingListForm.id" class="business-empty">Sélectionnez une liste.</p>
                <p v-else-if="!mailingMembers.length" class="business-empty">Aucun membre abonné.</p>
                <article v-for="member in mailingMembers" :key="member.contact_id" class="business-list-row">
                  <strong>{{ member.display_name || contactName(member.contact_id) }}</strong>
                  <span>{{ member.email || member.mobile || '—' }}</span>
                  <button v-if="canMailingManage" class="btn ghost small" type="button" @click="removeMailingMember(member)">Retirer</button>
                </article>
              </div>
            </div>
          </div>
        </section>

        <section class="business-message-section">
          <header class="business-section-head">
            <div><p class="eyebrow">Campagnes</p><h2>Préparation et envoi</h2></div>
            <button v-if="canMailingManage" class="btn ghost small" type="button" @click="resetCampaignForm">Nouvelle campagne</button>
          </header>
          <div class="business-message-layout">
            <div class="business-panel">
              <header class="business-panel-head"><div><p class="eyebrow">Sélection</p><h2>Brouillons</h2></div></header>
              <div class="business-list">
                <p v-if="!campaigns.length" class="business-empty">Aucune campagne.</p>
                <button v-for="campaign in campaigns" :key="campaign.id" type="button" class="business-list-button business-list-button--stacked" :class="{ selected: campaign.id === campaignForm.id }" @click="editCampaign(campaign)">
                  <strong>{{ campaign.name }}</strong><span>{{ campaign.channel }} · {{ campaign.status }}</span>
                </button>
              </div>
            </div>
            <form class="business-panel business-message-compose" @submit.prevent="saveCampaign">
              <header class="business-panel-head"><div><p class="eyebrow">{{ campaignForm.id ? `#${campaignForm.id}` : 'Nouvelle' }}</p><h2>Campagne</h2></div><button v-if="canMailingManage" class="btn primary small" type="submit">Enregistrer</button></header>
              <div class="business-form">
                <label>Liste<select v-model="campaignForm.list_id" :disabled="!canMailingManage"><option value="">—</option><option v-for="list in mailingLists" :key="list.id" :value="list.id">{{ list.name }}</option></select></label>
                <label>Canal<select v-model="campaignForm.channel" :disabled="!canMailingManage"><option v-for="channel in channels" :key="channel" :value="channel">{{ channel }}</option></select></label>
                <label>Nom<input v-model="campaignForm.name" type="text" :disabled="!canMailingManage" required></label>
                <label>Sujet<input v-model="campaignForm.subject" type="text" :disabled="!canMailingManage"></label>
                <label class="business-span">Texte<textarea v-model="campaignForm.body_text" rows="5" :disabled="!canMailingManage"></textarea></label>
              </div>
              <div class="business-actions">
                <button class="btn small" type="button" :disabled="!campaignForm.id || busy === 'mailing.campaign.preview'" @click="previewCampaignRecipients">Prévisualiser destinataires</button>
                <button v-if="canMailingManage" class="btn small" type="button" :disabled="!campaignForm.id || busy === 'mailing.campaign.enqueue'" @click="enqueueCampaign">Mettre en file</button>
              </div>
              <div v-if="campaignPreview.length" class="business-preview">
                <strong>{{ campaignPreview.length }} destinataire(s)</strong>
                <span v-for="recipient in campaignPreview.slice(0, 8)" :key="String(recipient.contact_id)">{{ recipient.display_name || recipient.recipient_value }}</span>
              </div>
            </form>
          </div>
        </section>
      </template>
      <div v-else class="business-panel business-empty">Permission mailing lecture requise.</div>

      <section v-if="canMessagingAdmin" class="business-panel business-outbox-panel">
        <header class="business-panel-head business-outbox-head">
          <div><p class="eyebrow">Messages envoyés</p><h2>Outbox</h2></div>
          <form class="business-filters business-filters--wide business-outbox-filters" @submit.prevent="loadMessaging">
            <input v-model="messageFilter.q" type="search" placeholder="Rechercher destinataire, sujet, contact...">
            <select v-model="messageFilter.channel"><option value="">Tous canaux</option><option v-for="channel in channels" :key="channel" :value="channel">{{ channel }}</option></select>
            <select v-model="messageFilter.status"><option v-for="status in messageStatuses" :key="status" :value="status">{{ status || 'Tous statuts' }}</option></select>
            <button class="btn small" type="submit">Filtrer</button>
          </form>
        </header>
        <div v-if="!outbox.length" class="business-empty">Aucun message trouvé.</div>
        <div v-else class="business-message-cards">
          <article v-for="message in outbox" :key="message.id" class="business-message-card">
            <div class="business-message-card__main">
              <div>
                <p class="eyebrow">{{ message.channel || 'message' }} · #{{ message.id }}</p>
                <h3>{{ message.subject || 'Sans sujet' }}</h3>
              </div>
              <StatusBadge :status="message.status" />
            </div>
            <dl>
              <div><dt>Destinataire</dt><dd>{{ message.recipient_value || '—' }}</dd></div>
              <div><dt>Relation</dt><dd>{{ messageContext(message) }}</dd></div>
              <div><dt>Date</dt><dd>{{ messageDate(message) }}</dd></div>
              <div v-if="message.last_error"><dt>Erreur</dt><dd>{{ message.last_error }}</dd></div>
            </dl>
          </article>
        </div>
      </section>
    </section>

    <section v-if="activeTab === 'settings'" class="business-grid">
      <div v-if="!canMessagingAdmin" class="business-panel business-panel--wide business-empty">Permission administration messaging requise.</div>
      <template v-else>
        <div class="business-panel business-panel--wide">
          <header class="business-panel-head">
            <div><p class="eyebrow">Providers</p><h2>Configuration</h2></div>
            <button class="btn ghost small" type="button" @click="newProviderFromRuntime()">Nouveau provider</button>
          </header>
          <div class="business-list">
            <article v-for="provider in messagingProviders.runtime" :key="`${provider.key}-${provider.channel}`" class="business-list-row business-list-row--provider">
              <div>
                <strong>{{ provider.key }}</strong>
                <span>{{ provider.channel }} · runtime</span>
              </div>
              <ProviderStatusBadge :enabled="provider.enabled !== false" :errors="Array.isArray(provider.errors) ? provider.errors : []" />
              <button class="btn ghost small" type="button" @click="newProviderFromRuntime(provider)">Configurer</button>
            </article>
            <p v-if="!messagingProviders.runtime.length" class="business-empty">Aucun provider runtime déclaré.</p>
          </div>
          <div class="business-provider-editor">
            <form v-for="(draft, key) in providerDrafts" :key="key" class="business-provider-card" @submit.prevent="saveProviderDraft(String(key))">
              <div class="business-provider-card__head">
                <strong>{{ draft.id ? `#${draft.id}` : 'Nouveau' }} · {{ draft.provider_key || 'provider' }}</strong>
                <div class="business-actions business-actions--inline">
                  <button class="btn primary small" type="submit" :disabled="busy === `messaging.provider.${key}`">Enregistrer</button>
                  <button class="btn ghost small" type="button" @click="deleteProviderDraft(String(key))">{{ draft.id ? 'Supprimer' : 'Annuler' }}</button>
                </div>
              </div>
              <div class="business-form">
                <label>Clé<input v-model="draft.provider_key" type="text" placeholder="smtp_principal" required></label>
                <label>Nom<input v-model="draft.name" type="text" required></label>
                <label>Canal<select v-model="draft.channel"><option v-for="channel in channels" :key="channel" :value="channel">{{ channel }}</option></select></label>
                <label>Type<select v-model="draft.provider_type"><option v-for="type in providerTypes" :key="type" :value="type">{{ type }}</option></select></label>
                <label class="business-check"><input v-model="draft.is_enabled" type="checkbox"> Actif</label>
                <label class="business-check"><input v-model="draft.is_default" type="checkbox"> Par défaut</label>
                <label class="business-span">Secret ref<input v-model="draft.secret_ref" type="text" placeholder="env:BUSINESS_SMTP_PASSWORD"></label>
                <label class="business-span">Configuration JSON<textarea v-model="draft.config_json" rows="5" spellcheck="false" placeholder='{"host":"smtp.example.test","port":587}'></textarea></label>
              </div>
            </form>
            <p v-if="!Object.keys(providerDrafts).length" class="business-empty">Aucun provider configuré. Utilisez “Configurer” sur un provider runtime ou “Nouveau provider”.</p>
          </div>
        </div>
        <form class="business-panel" @submit.prevent="sendTestMessage">
          <header class="business-panel-head"><div><p class="eyebrow">Test</p><h2>Envoi log_only</h2></div><button class="btn primary small" type="submit" :disabled="busy === 'messaging.send-test'">Envoyer</button></header>
          <div class="business-form">
            <label>Canal<select v-model="messageTestForm.channel"><option v-for="channel in channels" :key="channel" :value="channel">{{ channel }}</option></select></label>
            <label>Provider<input v-model="messageTestForm.provider_key" type="text" placeholder="log_only"></label>
            <label class="business-span">Destinataire<input v-model="messageTestForm.recipient_value" type="text" required></label>
            <label class="business-span">Sujet<input v-model="messageTestForm.subject" type="text"></label>
            <label class="business-span">Message<textarea v-model="messageTestForm.body_text" rows="4"></textarea></label>
            <label class="business-check business-span"><input v-model="messageTestForm.confirm_external_test" type="checkbox"> Confirmer un test provider externe</label>
          </div>
        </form>
      </template>
    </section>

    <section v-if="activeTab === 'products'" class="business-catalog-tab">
      <BusinessCatalogView :key="`products-${catalogRefreshKey}`" embedded fixed-tab="products" />
    </section>

    <section v-if="activeTab === 'offers'" class="business-catalog-tab">
      <BusinessCatalogView :key="`offers-${catalogRefreshKey}`" embedded fixed-tab="offers" />
    </section>
  </section>
</template>

<style scoped>
.business-crm {
  --business-border: #d7dee8;
  --business-muted: #667085;
  --business-surface: #fff;
}

.business-crm :where(button, a, input, select, textarea, summary):focus-visible,
.business-modal-backdrop :where(button, a, input, select, textarea, summary):focus-visible {
  outline: 3px solid rgba(37, 99, 235, .35);
  outline-offset: 2px;
}

.business-tabs {
  flex-wrap: wrap;
  overflow-x: visible;
  scrollbar-width: none;
}

.business-tabs .editor-tab {
  flex: 0 1 auto;
}

.business-catalog-tab {
  min-width: 0;
}

.business-dashboard {
  display: grid;
  gap: 1rem;
}

.business-dashboard-hero,
.business-dashboard-actions,
.business-dashboard-alerts,
.business-dashboard-results,
.business-dashboard-counters {
  border: 1px solid var(--business-border);
  border-radius: 8px;
  background: var(--business-surface);
  padding: .85rem 1rem;
}

.business-dashboard-hero {
  display: flex;
  gap: 1rem;
  align-items: center;
  justify-content: space-between;
}

.business-dashboard-hero h2 {
  margin: 0;
  font-size: 1.15rem;
}

.business-dashboard-search,
.business-dashboard-actions,
.business-dashboard-alerts {
  display: flex;
  flex-wrap: wrap;
  gap: .55rem;
  align-items: center;
}

.business-dashboard-search {
  min-width: min(520px, 100%);
}

.business-dashboard-search-control {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: center;
  gap: .55rem;
  min-width: min(360px, 100%);
  flex: 1 1 260px;
  min-height: 2.75rem;
  border: 1px solid #cbd5e1;
  border-radius: 999px;
  background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
  box-shadow: 0 1px 2px rgba(15, 23, 42, .04), inset 0 1px 0 rgba(255, 255, 255, .85);
  padding: .25rem .9rem;
  transition: border-color .16s ease, box-shadow .16s ease, background .16s ease;
}

.business-dashboard-search-control:focus-within {
  border-color: #2563eb;
  background: #fff;
  box-shadow: 0 8px 20px rgba(15, 23, 42, .08);
}

.business-dashboard-search-control svg {
  width: 1.05rem;
  height: 1.05rem;
  color: #64748b;
}

.business-dashboard-search-control input {
  appearance: none;
  -webkit-appearance: none;
  width: 100%;
  min-width: 0;
  min-height: 2.1rem;
  border: 0;
  outline: 0 !important;
  box-shadow: none !important;
  background: transparent;
  color: #0f172a;
  font: inherit;
}

.business-dashboard-search-control input:focus,
.business-dashboard-search-control input:focus-visible {
  outline: 0 !important;
  box-shadow: none !important;
}

.business-dashboard-search-control input::placeholder {
  color: #64748b;
}

.business-dashboard-results {
  display: grid;
  gap: .25rem;
}

.business-dashboard-results button {
  display: grid;
  gap: .1rem;
  border: 0;
  border-radius: 6px;
  background: transparent;
  padding: .5rem;
  text-align: left;
  cursor: pointer;
}

.business-dashboard-results button:hover {
  background: #f2f4f7;
}

.business-dashboard-results span,
.business-dashboard-results small {
  color: var(--business-muted);
}

.business-dashboard-counters {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: .65rem;
}

.business-dashboard-counters article {
  display: grid;
  gap: .15rem;
  border-left: 3px solid #d0d5dd;
  padding-left: .65rem;
}

.business-dashboard-counters span {
  color: var(--business-muted);
  font-size: .82rem;
}

.business-dashboard-counters strong {
  font-size: 1.35rem;
}

.business-dashboard-alerts article {
  display: inline-flex;
  gap: .45rem;
  align-items: center;
}

.business-dashboard-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 1rem;
}

.business-relations-shell {
  display: grid;
  gap: .75rem;
}

.business-relations-head,
.business-secondary-head {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: center;
  background: var(--business-surface);
  border: 1px solid var(--business-border);
  border-radius: 8px;
  padding: .85rem 1rem;
}

.business-relations-head h2 {
  margin: 0;
  font-size: 1.1rem;
}

.business-relations-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: .45rem;
  align-items: center;
  justify-content: flex-end;
}

.business-relations-toolbar input,
.business-relations-toolbar select {
  min-height: 2.25rem;
  border: 1px solid var(--business-border);
  border-radius: 6px;
  padding: .4rem .55rem;
}

.business-relations-toolbar input {
  min-width: min(320px, 42vw);
}

.business-relations-unified {
  margin-bottom: 1rem;
}

.business-messages {
  display: grid;
  gap: 1rem;
}

.business-messages-hero,
.business-message-section {
  border: 1px solid var(--business-border);
  border-radius: 8px;
  background: var(--business-surface);
  padding: 1rem;
}

.business-messages-hero {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: center;
}

.business-messages-hero h2,
.business-section-head h2,
.business-message-card h3 {
  margin: 0;
}

.business-message-stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(84px, 1fr));
  gap: .55rem;
}

.business-message-stats article {
  display: grid;
  gap: .1rem;
  min-width: 0;
  border: 1px solid #e4e7ec;
  border-radius: 8px;
  background: #f8fafc;
  padding: .55rem .7rem;
}

.business-message-stats strong {
  font-size: 1.25rem;
  line-height: 1;
}

.business-message-stats span,
.business-message-card dt {
  color: var(--business-muted);
  font-size: .78rem;
  text-transform: uppercase;
}

.business-section-head {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: center;
  margin-bottom: .85rem;
}

.business-message-layout {
  display: grid;
  grid-template-columns: minmax(260px, .7fr) minmax(0, 1.3fr);
  gap: .85rem;
  align-items: start;
}

.business-message-layout--lists {
  grid-template-columns: minmax(250px, .65fr) minmax(0, 1fr) minmax(260px, .75fr);
}

.business-message-section .business-panel {
  box-shadow: none;
}

.business-list-button--stacked {
  grid-template-columns: 1fr;
  gap: .15rem;
}

.business-message-compose {
  min-height: 100%;
}

.business-outbox-panel {
  display: grid;
  gap: .85rem;
}

.business-outbox-head {
  margin-bottom: 0;
}

.business-outbox-filters {
  justify-content: flex-end;
}

.business-message-cards {
  display: grid;
  gap: .65rem;
}

.business-message-card {
  display: grid;
  gap: .65rem;
  border: 1px solid #e4e7ec;
  border-radius: 8px;
  background: #fff;
  padding: .85rem;
}

.business-message-card__main {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: flex-start;
}

.business-message-card dl {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: .6rem;
  margin: 0;
}

.business-message-card div:has(> dt) {
  display: grid;
  gap: .12rem;
  min-width: 0;
}

.business-message-card dd {
  margin: 0;
  min-width: 0;
  overflow-wrap: anywhere;
}

.business-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.25fr) minmax(320px, .75fr);
  gap: 1rem;
  align-items: start;
}

.business-panel {
  background: var(--business-surface);
  border: 1px solid var(--business-border);
  border-radius: 8px;
  padding: 1rem;
  min-width: 0;
}

.business-panel--wide {
  grid-column: span 2;
}

.business-panel-head {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: flex-start;
  margin-bottom: .85rem;
}

.business-panel-head h2 {
  margin: 0;
  font-size: 1.05rem;
}

.business-filters,
.business-inline-form {
  display: flex;
  flex-wrap: wrap;
  gap: .45rem;
  align-items: center;
  justify-content: flex-end;
}

.business-filters--wide input[type="search"] {
  min-width: min(280px, 100%);
}

.business-filters input,
.business-filters select,
.business-inline-form input,
.business-inline-form select {
  min-height: 2.25rem;
  border: 1px solid var(--business-border);
  border-radius: 6px;
  padding: .4rem .55rem;
}

.business-table {
  width: 100%;
  border-collapse: collapse;
  font-size: .92rem;
}

.business-table th,
.business-table td {
  border-top: 1px solid #eef2f6;
  padding: .55rem .5rem;
  vertical-align: middle;
}

.business-table th {
  color: var(--business-muted);
  font-size: .78rem;
  text-transform: uppercase;
}

.business-table tr.selected,
.business-list-button.selected {
  background: #ecfdf3;
}

.business-link {
  border: 0;
  background: transparent;
  color: #0f5132;
  font-weight: 700;
  padding: 0;
  text-align: left;
  text-decoration-thickness: .08em;
  text-underline-offset: .16em;
}

.business-link:hover,
.business-link:focus-visible {
  color: #064e3b;
  text-decoration: underline;
}

.business-form {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: .7rem;
}

.business-relation-type-tabs {
  display: inline-flex;
  gap: .35rem;
  align-items: center;
  margin: 0 0 .9rem;
  padding: .35rem;
  border: 1px solid #dbe3ef;
  border-radius: 1rem;
  background: rgba(248, 250, 252, .96);
  box-shadow: 0 10px 28px rgba(15, 23, 42, .04);
}

.business-form--single {
  margin-top: .75rem;
}

.business-form label {
  display: flex;
  flex-direction: column;
  gap: .25rem;
  color: #344054;
  font-size: .82rem;
  font-weight: 700;
}

.business-form input,
.business-form select,
.business-form textarea {
  width: 100%;
  border: 1px solid var(--business-border);
  border-radius: 6px;
  padding: .5rem .55rem;
  font: inherit;
  font-weight: 400;
  color: #101828;
  min-height: 2.4rem;
}

.business-span {
  grid-column: 1 / -1;
}

.business-actions {
  display: flex;
  flex-wrap: wrap;
  gap: .5rem;
  margin-top: .85rem;
}

.business-meta {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: .55rem;
  margin-top: .35rem;
  border: 1px solid #e4e7ec;
  border-radius: 8px;
  background: #f8fafc;
  padding: .75rem;
}

.business-meta span {
  display: grid;
  gap: .15rem;
  min-width: 0;
  color: #475467;
  font-size: .86rem;
}

.business-meta strong {
  color: #667085;
  font-size: .75rem;
  text-transform: uppercase;
}

.business-modal-backdrop {
  --business-border: #d7dee8;
  --business-muted: #667085;
  --business-surface: #fff;
  position: fixed;
  inset: 0;
  z-index: 1080;
  display: grid;
  align-items: start;
  justify-items: center;
  background: rgba(15, 23, 42, .56);
  padding: clamp(.75rem, 3vw, 2rem);
  overflow-y: auto;
  overscroll-behavior: contain;
}

.business-modal {
  width: min(1040px, 100%);
  height: auto;
  max-height: none;
  display: flex;
  flex-direction: column;
  overflow: visible;
  border: 1px solid rgba(16, 24, 40, .12);
  border-radius: 8px;
  background: var(--business-surface, #fff);
  box-shadow: 0 22px 70px rgba(15, 23, 42, .28);
  outline: none;
  scroll-margin: 1rem;
}

.business-modal:focus-visible {
  box-shadow: 0 22px 70px rgba(15, 23, 42, .28), 0 0 0 3px rgba(37, 99, 235, .32);
}

.business-modal-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  border-bottom: 1px solid var(--business-border);
  padding: .9rem 1rem;
}

.business-modal-head h2 {
  margin: .1rem 0 0;
  font-size: 1.12rem;
  line-height: 1.25;
}

.business-modal-body {
  min-width: 0;
  overflow: visible;
  padding: 1rem;
  background: var(--business-surface, #fff);
}

.business-modal-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: .75rem;
}

.business-empty {
  color: var(--business-muted);
  padding: 1rem;
  text-align: center;
}

.business-linked,
.business-copy-box,
.business-preview {
  margin-top: .85rem;
  border-top: 1px solid #eef2f6;
  padding-top: .85rem;
}

.business-linked h3,
.business-linked p {
  margin: 0;
}

.business-list {
  display: grid;
  gap: .45rem;
}

.business-list-row,
.business-list-button {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto auto;
  gap: .6rem;
  align-items: center;
  border: 1px solid #eef2f6;
  border-radius: 6px;
  padding: .55rem .65rem;
  background: #fff;
  text-align: left;
}

.business-list-row--share {
  grid-template-columns: minmax(110px, .55fr) minmax(180px, 1fr) minmax(150px, .8fr) minmax(260px, 1.35fr);
  align-items: start;
}

.business-list-row--share span {
  display: grid;
  gap: .15rem;
}

.business-list-row--provider {
  grid-template-columns: minmax(0, 1fr) auto auto;
}

.business-provider-editor {
  display: grid;
  gap: .75rem;
  margin-top: .85rem;
}

.business-provider-card {
  border: 1px solid #e4e7ec;
  border-radius: 8px;
  background: #f8fafc;
  padding: .85rem;
}

.business-provider-card__head {
  display: flex;
  justify-content: space-between;
  gap: .75rem;
  align-items: center;
  margin-bottom: .7rem;
}

.business-actions--inline {
  margin-top: 0;
  justify-content: flex-end;
}

.business-provider-card textarea {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
  font-size: .84rem;
  min-height: 7.5rem;
  resize: vertical;
}

.business-share-actions {
  display: flex;
  gap: .45rem;
  align-items: flex-start;
  justify-content: flex-end;
  flex-wrap: wrap;
}

.business-share-edit {
  display: grid;
  grid-template-columns: minmax(110px, .75fr) minmax(180px, 1fr) auto;
  gap: .45rem;
  align-items: center;
}

.business-share-edit input {
  min-height: 2.25rem;
  border: 1px solid var(--business-border);
  border-radius: 6px;
  padding: .4rem .55rem;
  min-width: 0;
}

.business-list-button {
  width: 100%;
}

.business-list-row span,
.business-list-button span,
.business-list-row small {
  color: var(--business-muted);
  min-width: 0;
}

.business-consents {
  display: grid;
  gap: .55rem;
}

.business-consent-row {
  display: grid;
  grid-template-columns: 90px minmax(180px, 1fr) 130px minmax(160px, 1fr) auto auto;
  gap: .45rem;
  align-items: center;
}

.business-consent-row input,
.business-consent-row select {
  border: 1px solid var(--business-border);
  border-radius: 6px;
  min-height: 2.25rem;
  padding: .4rem .55rem;
}

.business-check {
  display: inline-flex;
  align-items: center;
  gap: .35rem;
  color: #475467;
  font-size: .85rem;
  white-space: nowrap;
}

.business-copy-box {
  display: grid;
  gap: .5rem;
}

.business-copy-box code {
  display: block;
  overflow-wrap: anywhere;
  background: #f2f4f7;
  border-radius: 6px;
  padding: .55rem;
}

.business-preview {
  display: flex;
  flex-wrap: wrap;
  gap: .4rem;
  align-items: center;
}

.business-preview span {
  border: 1px solid var(--business-border);
  border-radius: 999px;
  padding: .2rem .5rem;
  color: #344054;
}

.business-import {
  display: flex;
  flex-wrap: wrap;
  gap: .55rem;
  align-items: center;
}

.business-import input[type="file"] {
  border: 1px solid var(--business-border);
  border-radius: 6px;
  padding: .42rem .55rem;
  max-width: 100%;
}

.business-import-report {
  display: grid;
  gap: .65rem;
  margin-top: .85rem;
  border-top: 1px solid #eef2f6;
  padding-top: .85rem;
}

@media (max-width: 1100px) {
  .business-grid,
  .business-panel--wide,
  .business-dashboard-grid,
  .business-message-layout,
  .business-message-layout--lists {
    display: block;
  }

  .business-panel {
    margin-bottom: 1rem;
  }

  .business-panel-head {
    flex-direction: column;
  }

  .business-filters,
  .business-relations-head,
  .business-relations-toolbar {
    justify-content: flex-start;
  }

  .business-relations-head {
    align-items: flex-start;
    flex-direction: column;
  }

  .business-dashboard-hero {
    align-items: flex-start;
    flex-direction: column;
  }

  .business-messages-hero,
  .business-section-head,
  .business-message-card__main {
    align-items: flex-start;
    flex-direction: column;
  }

  .business-message-stats {
    width: 100%;
  }
}


.business-dashboard-commandbar {
  display: flex;
  justify-content: space-between;
  gap: .75rem;
  align-items: center;
  flex-wrap: wrap;
  border: 1px solid var(--business-border);
  border-radius: 10px;
  background: var(--business-surface);
  padding: .75rem;
}

.business-dashboard-commandbar .business-dashboard-search {
  flex: 1 1 320px;
}

.business-action-btn {
  display: inline-flex;
  align-items: center;
  gap: .4rem;
}

.business-action-btn svg {
  flex: 0 0 auto;
}

.business-modal-head-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: .45rem;
  flex-wrap: wrap;
}

.business-modal-close {
  width: 2.25rem;
  height: 2.25rem;
  display: grid;
  place-items: center;
  border: 1px solid #dbe3ef;
  border-radius: .75rem;
  background: #fff;
  color: #0f172a;
  font-size: 1.35rem;
  line-height: 1;
  cursor: pointer;
}

.business-modal-close:hover {
  background: #f8fafc;
}

.business-modal-close:focus-visible {
  outline: 3px solid rgba(37, 99, 235, .35);
  outline-offset: 2px;
}

@media (max-width: 760px) {
  .business-tabs {
    margin-inline: 0;
    padding-bottom: 0;
  }

  .business-tabs .editor-tab {
    min-height: 2.5rem;
  }

  .business-form,
  .business-modal-grid,
  .business-meta,
  .business-consent-row,
  .business-list-row,
  .business-share-edit,
  .business-list-button {
    grid-template-columns: 1fr;
  }

  .business-modal-backdrop {
    align-items: stretch;
    padding: .5rem;
  }

  .business-modal {
    max-height: none;
    width: 100%;
    border-radius: 8px;
  }

  .business-modal-head {
    align-items: center;
    flex-direction: row;
    position: sticky;
    top: 0;
    z-index: 2;
    background: var(--business-surface, #fff);
  }

  .business-modal-head h2 {
    font-size: 1rem;
  }

  .business-modal-body {
    padding: .85rem;
  }

  .business-table {
    display: block;
    overflow-x: auto;
    white-space: nowrap;
  }

  .business-relations-toolbar,
  .business-relations-toolbar input,
  .business-relations-toolbar select,
  .business-relations-toolbar button,
  .business-menu {
    width: 100%;
  }

  .business-menu-panel {
    left: 0;
    right: auto;
    width: 100%;
  }

  .business-dashboard-counters {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .business-message-stats,
  .business-message-card dl {
    grid-template-columns: 1fr;
  }

  .business-messages-hero,
  .business-message-section {
    padding: .85rem;
  }

  .business-dashboard-search,
  .business-dashboard-search-control,
  .business-dashboard-search button {
    width: 100%;
  }

  .business-dashboard-actions,
  .business-dashboard-actions .business-action-btn,
  .business-actions,
  .business-actions .btn {
    width: 100%;
  }

  .business-dashboard-actions .business-action-btn,
  .business-actions .btn {
    justify-content: center;
  }

  .business-consent-row {
    align-items: stretch;
  }
}
</style>
