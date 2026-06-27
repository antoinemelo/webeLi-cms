<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import { useAdminContextStore } from '@/stores/adminContext';

type BusinessTab = 'companies' | 'contacts' | 'memos' | 'mailing' | 'messaging';
type IdValue = number | string | null | undefined;
type Company = Record<string, unknown> & { id: number; name: string; status?: string; email?: string | null; phone?: string | null; website_url?: string | null; notes?: string | null; is_system?: boolean };
type Contact = Record<string, unknown> & { id: number; company_id?: number; company_name?: string; display_name: string; status?: string; email?: string | null; phone?: string | null; mobile?: string | null; iam_user_id?: number | null };
type Memo = Record<string, unknown> & { id: number; company_id?: number | null; contact_id?: number | null; title: string; body?: string; visibility?: string; created_at?: string };
type MemoShare = Record<string, unknown> & { id: number; share_type?: string; public_label?: string | null; revoked_at?: string | null };
type MemoComment = Record<string, unknown> & { id: number; body: string; created_at?: string };
type Consent = Record<string, unknown> & { channel: string; consent_status?: string };
type ChannelRow = Record<string, unknown> & { channel: string; channel_value?: string; is_primary?: boolean; is_verified?: boolean };
type MailingList = Record<string, unknown> & { id: number; name: string; list_key?: string; channel?: string; status?: string; description?: string };
type MailingMember = Record<string, unknown> & { contact_id: number; display_name?: string; email?: string | null; mobile?: string | null; status?: string };
type Campaign = Record<string, unknown> & { id: number; name: string; list_id?: number | null; channel?: string; status?: string; subject?: string | null; body_text?: string };
type MessageRow = Record<string, unknown> & { id: number; channel?: string; recipient_value?: string; subject?: string | null; status?: string; last_error?: string | null; created_at?: string };
type MessagingProvider = Record<string, unknown> & { key?: string; provider_key?: string; channel?: string; enabled?: boolean; errors?: string[] };

const context = useAdminContextStore();
const activeTab = ref<BusinessTab>('companies');
const loading = ref(false);
const busy = ref('');
const error = ref('');
const success = ref('');
const oneTimeShareUrl = ref('');
const contactImportFile = ref<File | null>(null);
const contactImportInput = ref<HTMLInputElement | null>(null);
const contactImportReport = ref<Record<string, unknown> | null>(null);

const canCrmRead = computed(() => context.can('business.crm.read'));
const canCrmManage = computed(() => context.can('business.crm.manage'));
const canMemoRead = computed(() => context.can('business.memo.read'));
const canMemoManage = computed(() => context.can('business.memo.manage'));
const canMemoShare = computed(() => context.can('business.memo.share'));
const canMailingRead = computed(() => context.can('business.mailing.read'));
const canMailingManage = computed(() => context.can('business.mailing.manage'));
const canMessagingAdmin = computed(() => context.can('business.messaging.admin'));

const tabs: Array<{ key: BusinessTab; label: string; permission: () => boolean }> = [
  { key: 'companies', label: 'Entreprises', permission: () => canCrmRead.value },
  { key: 'contacts', label: 'Contacts', permission: () => canCrmRead.value },
  { key: 'memos', label: 'Mémos', permission: () => canMemoRead.value },
  { key: 'mailing', label: 'Mailing', permission: () => canMailingRead.value },
  { key: 'messaging', label: 'Messaging', permission: () => canMessagingAdmin.value },
];

const crmStatuses = ['prospect', 'client', 'supplier', 'former_client', 'other'];
const channels = ['email', 'whatsapp', 'telegram'];
const consentStatuses = ['unknown', 'opt_in', 'opt_out'];
const memoVisibilities = ['private', 'internal', 'public_link'];
const messageStatuses = ['', 'pending', 'queued', 'sent', 'failed', 'cancelled', 'skipped'];

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

const companyFilter = reactive({ q: '', status: '', archived: false });
const contactFilter = reactive({ q: '', status: '', company_id: '' });
const contactImportOptions = reactive({ create_companies: false, update_existing: false });
const memoFilter = reactive({ q: '', company_id: '', contact_id: '' });
const messageFilter = reactive({ status: '' });

const companyForm = reactive({ id: 0, name: '', status: 'prospect', email: '', phone: '', website_url: '', notes: '' });
const contactForm = reactive({ id: 0, company_id: '', iam_user_id: '', first_name: '', last_name: '', display_name: '', status: 'prospect', preferred_language: '', email: '', phone: '', mobile: '', job_title: '', notes: '' });
const memoForm = reactive({ id: 0, company_id: '', contact_id: '', title: '', body: '', visibility: 'private', comment: '', share_iam_user_ids: '', public_label: '', public_expires_at: '' });
const consentForm = reactive<Record<string, { value: string; consent_status: string; evidence: string; is_verified: boolean }>>({});
const mailingListForm = reactive({ id: 0, name: '', list_key: '', channel: 'email', description: '', status: 'active', member_contact_id: '' });
const campaignForm = reactive({ id: 0, list_id: '', name: '', channel: 'email', subject: '', body_text: '', scheduled_at: '' });
const messageTestForm = reactive({ channel: 'email', provider_key: 'log_only', recipient_value: 'test@example.test', subject: 'Test Business messaging', body_text: 'Message de test Business.', confirm_external_test: false });

const selectedCompany = computed(() => companies.value.find((company) => company.id === companyForm.id) || null);
const selectedContact = computed(() => contacts.value.find((contact) => contact.id === contactForm.id) || null);
const selectedMemo = computed(() => memos.value.find((memo) => memo.id === memoForm.id) || null);
const selectedList = computed(() => mailingLists.value.find((list) => list.id === mailingListForm.id) || null);
const selectedCampaign = computed(() => campaigns.value.find((campaign) => campaign.id === campaignForm.id) || null);
const linkedContacts = computed(() => selectedCompany.value ? contacts.value.filter((contact) => Number(contact.company_id || 0) === selectedCompany.value?.id) : []);
const linkedCompanyMemos = computed(() => selectedCompany.value ? memos.value.filter((memo) => Number(memo.company_id || 0) === selectedCompany.value?.id) : []);
const linkedContactMemos = computed(() => selectedContact.value ? memos.value.filter((memo) => Number(memo.contact_id || 0) === selectedContact.value?.id) : []);
const allProviders = computed(() => [...messagingProviders.value.runtime, ...messagingProviders.value.configured]);
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

function publicUrl(path: string): string {
  return new URL(path, window.location.origin).toString();
}

function resetCompanyForm(): void {
  Object.assign(companyForm, { id: 0, name: '', status: 'prospect', email: '', phone: '', website_url: '', notes: '' });
}

function resetContactForm(): void {
  Object.assign(contactForm, { id: 0, company_id: '', iam_user_id: '', first_name: '', last_name: '', display_name: '', status: 'prospect', preferred_language: '', email: '', phone: '', mobile: '', job_title: '', notes: '' });
  contactChannels.value = [];
  contactConsents.value = [];
  Object.keys(consentForm).forEach((key) => delete consentForm[key]);
}

function resetMemoForm(): void {
  Object.assign(memoForm, { id: 0, company_id: '', contact_id: '', title: '', body: '', visibility: 'private', comment: '', share_iam_user_ids: '', public_label: '', public_expires_at: '' });
  memoComments.value = [];
  memoShares.value = [];
  oneTimeShareUrl.value = '';
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
      adminApi.get<{ messages: MessageRow[] }>('/business/messaging/outbox', { status: messageFilter.status || undefined, limit: 100 }),
    ]);
    messagingProviders.value = {
      runtime: providersResponse.data.runtime || [],
      configured: providersResponse.data.configured || [],
    };
    outbox.value = outboxResponse.data.messages || [];
  } catch (err) {
    setError(err, 'Messaging indisponible.');
  } finally {
    loading.value = false;
  }
}

async function loadCurrentTab(): Promise<void> {
  if (activeTab.value === 'companies') await loadCompanies();
  if (activeTab.value === 'contacts') await loadContacts();
  if (activeTab.value === 'memos') await loadMemos();
  if (activeTab.value === 'mailing') await loadMailing();
  if (activeTab.value === 'messaging') await loadMessaging();
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

async function saveCompany(): Promise<void> {
  if (!canCrmManage.value) return;
  busy.value = 'company.save';
  try {
    const payload = { name: companyForm.name, status: companyForm.status, email: companyForm.email, phone: companyForm.phone, website_url: companyForm.website_url, notes: companyForm.notes };
    const response = companyForm.id
      ? await adminApi.patch<{ company: Company }>(`/business/companies/${companyForm.id}`, payload)
      : await adminApi.post<{ company: Company }>('/business/companies', payload);
    editCompany(response.data.company);
    await loadCompanies();
    setNotice(companyForm.id ? 'Entreprise enregistrée.' : 'Entreprise créée.');
  } catch (err) {
    setError(err, 'Enregistrement entreprise impossible.');
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

async function saveContact(): Promise<void> {
  if (!canCrmManage.value) return;
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
    editContact(response.data.contact);
    await loadContacts();
    setNotice(contactForm.id ? 'Contact enregistré.' : 'Contact créé.');
  } catch (err) {
    setError(err, 'Enregistrement contact impossible.');
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
  void Promise.all([loadMemoComments(memo.id), canMemoShare.value ? loadMemoShares(memo.id) : Promise.resolve()]);
}

async function saveMemo(): Promise<void> {
  if (!canMemoManage.value) return;
  busy.value = 'memo.save';
  try {
    const payload = { company_id: intOrNull(memoForm.company_id), contact_id: intOrNull(memoForm.contact_id), title: memoForm.title, body: memoForm.body, visibility: memoForm.visibility };
    const response = memoForm.id
      ? await adminApi.patch<{ memo: Memo }>(`/business/memos/${memoForm.id}`, payload)
      : await adminApi.post<{ memo: Memo }>('/business/memos', payload);
    editMemo(response.data.memo);
    await loadMemos();
    setNotice(memoForm.id ? 'Mémo enregistré.' : 'Mémo créé.');
  } catch (err) {
    setError(err, 'Enregistrement mémo impossible.');
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
    setNotice('Commentaire ajouté.');
  } catch (err) {
    setError(err, 'Ajout de commentaire impossible.');
  } finally {
    busy.value = '';
  }
}

async function loadMemoShares(memoId: number): Promise<void> {
  try {
    const response = await adminApi.get<{ shares: MemoShare[] }>(`/business/memos/${memoId}/shares`);
    memoShares.value = response.data.shares || [];
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
    const response = await adminApi.post<{ public_path: string; message?: string }>(`/business/memos/${memoForm.id}/public-share`, { label: memoForm.public_label, expires_at: memoForm.public_expires_at });
    oneTimeShareUrl.value = publicUrl(response.data.public_path);
    await loadMemoShares(memoForm.id);
    setNotice(response.data.message || 'Lien public créé.');
  } catch (err) {
    setError(err, 'Création du lien public impossible.');
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

watch(activeTab, () => { void loadCurrentTab(); });
watch(() => context.siteId, () => {
  void Promise.all([loadCompanies(), loadContacts(), loadMemos(), loadMailing(), loadMessaging()]);
});

onMounted(async () => {
  await Promise.all([loadCompanies(), loadContacts(), loadTags(), loadMemos()]);
});
</script>

<template>
  <section class="page-stack business-crm">
    <PageHeader title="Business / CRM" intro="CRM léger : entreprises, contacts, mémos, mailing simple et outbox messaging.">
      <template #actions>
        <button class="btn ghost" type="button" :disabled="loading" @click="loadCurrentTab">Rafraîchir</button>
      </template>
    </PageHeader>

    <ApiFeedback :error="error" :success="success" />

    <nav class="business-tabs" aria-label="Sections Business CRM">
      <button v-for="tab in tabs" :key="tab.key" type="button" :class="{ active: activeTab === tab.key, locked: !tab.permission() }" @click="activeTab = tab.key">
        {{ tab.label }}
      </button>
    </nav>

    <section v-if="activeTab === 'companies'" class="business-grid">
      <div class="business-panel business-panel--wide">
        <header class="business-panel-head">
          <div>
            <p class="eyebrow">CRM</p>
            <h2>Entreprises</h2>
          </div>
          <form class="business-filters" @submit.prevent="loadCompanies">
            <input v-model="companyFilter.q" type="search" placeholder="Recherche nom, email, téléphone">
            <select v-model="companyFilter.status" aria-label="Statut entreprise">
              <option value="">Tous statuts</option>
              <option v-for="status in crmStatuses" :key="status" :value="status">{{ statusLabel(status) }}</option>
            </select>
            <label class="business-check"><input v-model="companyFilter.archived" type="checkbox"> Archives</label>
            <button class="btn small" type="submit">Filtrer</button>
            <a v-if="canCrmManage" class="btn ghost small" :href="companiesExportHref">Export CSV</a>
          </form>
        </header>
        <div v-if="!canCrmRead" class="business-empty">Permission CRM lecture requise.</div>
        <div v-else-if="!companies.length" class="business-empty">Aucune entreprise trouvée.</div>
        <table v-else class="business-table">
          <thead><tr><th>Nom</th><th>Statut</th><th>Contact</th><th>Type</th><th></th></tr></thead>
          <tbody>
            <tr v-for="company in companies" :key="company.id" :class="{ selected: company.id === companyForm.id }">
              <td><button type="button" class="business-link" @click="editCompany(company)">{{ company.name }}</button></td>
              <td><StatusBadge :status="company.status" /></td>
              <td><span>{{ company.email || company.phone || '—' }}</span></td>
              <td>{{ company.is_system ? 'Individus' : 'Organisation' }}</td>
              <td class="text-end"><button v-if="canCrmManage && !company.is_system" class="btn ghost small" type="button" :disabled="busy === `company.archive.${company.id}`" @click="archiveCompany(company)">Archiver</button></td>
            </tr>
          </tbody>
        </table>
      </div>

      <form class="business-panel" @submit.prevent="saveCompany">
        <header class="business-panel-head">
          <div>
            <p class="eyebrow">{{ companyForm.id ? `#${companyForm.id}` : 'Nouvelle' }}</p>
            <h2>Fiche entreprise</h2>
          </div>
          <button v-if="canCrmManage" class="btn primary small" type="submit" :disabled="busy === 'company.save'">Enregistrer</button>
        </header>
        <div class="business-form">
          <label>Nom<input v-model="companyForm.name" type="text" :disabled="!canCrmManage" required></label>
          <label>Statut<select v-model="companyForm.status" :disabled="!canCrmManage"><option v-for="status in crmStatuses" :key="status" :value="status">{{ statusLabel(status) }}</option></select></label>
          <label>Email<input v-model="companyForm.email" type="email" :disabled="!canCrmManage"></label>
          <label>Téléphone<input v-model="companyForm.phone" type="tel" :disabled="!canCrmManage"></label>
          <label>Site web<input v-model="companyForm.website_url" type="url" :disabled="!canCrmManage"></label>
          <label class="business-span">Notes<textarea v-model="companyForm.notes" rows="4" :disabled="!canCrmManage"></textarea></label>
        </div>
        <div class="business-actions">
          <button class="btn ghost small" type="button" @click="resetCompanyForm">Nouvelle entreprise</button>
        </div>
        <div v-if="selectedCompany" class="business-linked">
          <h3>Liens chargés</h3>
          <p>{{ linkedContacts.length }} contact(s), {{ linkedCompanyMemos.length }} mémo(s).</p>
        </div>
      </form>
    </section>

    <section v-if="activeTab === 'contacts'" class="business-grid">
      <div class="business-panel business-panel--wide">
        <header class="business-panel-head">
          <div><p class="eyebrow">CRM</p><h2>Contacts</h2></div>
          <form class="business-filters" @submit.prevent="loadContacts">
            <input v-model="contactFilter.q" type="search" placeholder="Recherche nom, email, mobile">
            <select v-model="contactFilter.company_id" aria-label="Entreprise">
              <option value="">Toutes entreprises</option>
              <option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}</option>
            </select>
            <select v-model="contactFilter.status" aria-label="Statut contact">
              <option value="">Tous statuts</option>
              <option v-for="status in crmStatuses" :key="status" :value="status">{{ statusLabel(status) }}</option>
            </select>
            <button class="btn small" type="submit">Filtrer</button>
            <a v-if="canCrmManage" class="btn ghost small" :href="contactsExportHref">Export CSV</a>
          </form>
        </header>
        <div v-if="!canCrmRead" class="business-empty">Permission CRM lecture requise.</div>
        <div v-else-if="!contacts.length" class="business-empty">Aucun contact trouvé.</div>
        <table v-else class="business-table">
          <thead><tr><th>Nom</th><th>Entreprise</th><th>Canaux</th><th>Statut</th><th></th></tr></thead>
          <tbody>
            <tr v-for="contact in contacts" :key="contact.id" :class="{ selected: contact.id === contactForm.id }">
              <td><button class="business-link" type="button" @click="editContact(contact)">{{ contact.display_name }}</button></td>
              <td>{{ contact.company_name || companyName(contact.company_id) }}</td>
              <td>{{ [contact.email, contact.mobile, contact.phone].filter(Boolean).join(' · ') || '—' }}</td>
              <td><StatusBadge :status="contact.status" /></td>
              <td class="text-end"><button v-if="canCrmManage" class="btn ghost small" type="button" :disabled="busy === `contact.archive.${contact.id}`" @click="archiveContact(contact)">Archiver</button></td>
            </tr>
          </tbody>
        </table>
      </div>

      <form class="business-panel" @submit.prevent="saveContact">
        <header class="business-panel-head">
          <div><p class="eyebrow">{{ contactForm.id ? `#${contactForm.id}` : 'Nouveau' }}</p><h2>Fiche contact</h2></div>
          <button v-if="canCrmManage" class="btn primary small" type="submit" :disabled="busy === 'contact.save'">Enregistrer</button>
        </header>
        <div class="business-form">
          <label>Entreprise<select v-model="contactForm.company_id" :disabled="!canCrmManage"><option value="">Individus</option><option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}</option></select></label>
          <label>Statut<select v-model="contactForm.status" :disabled="!canCrmManage"><option v-for="status in crmStatuses" :key="status" :value="status">{{ statusLabel(status) }}</option></select></label>
          <label>Prénom<input v-model="contactForm.first_name" type="text" :disabled="!canCrmManage"></label>
          <label>Nom<input v-model="contactForm.last_name" type="text" :disabled="!canCrmManage"></label>
          <label class="business-span">Nom affiché<input v-model="contactForm.display_name" type="text" :disabled="!canCrmManage" required></label>
          <label>Email<input v-model="contactForm.email" type="email" :disabled="!canCrmManage"></label>
          <label>Téléphone<input v-model="contactForm.phone" type="tel" :disabled="!canCrmManage"></label>
          <label>WhatsApp / mobile<input v-model="contactForm.mobile" type="tel" :disabled="!canCrmManage"></label>
          <label>Langue<input v-model="contactForm.preferred_language" type="text" :disabled="!canCrmManage" placeholder="fr"></label>
          <label>Utilisateur IAM<input v-model="contactForm.iam_user_id" type="number" min="1" :disabled="!canCrmManage" placeholder="optionnel"></label>
          <label>Fonction<input v-model="contactForm.job_title" type="text" :disabled="!canCrmManage"></label>
          <label class="business-span">Notes<textarea v-model="contactForm.notes" rows="3" :disabled="!canCrmManage"></textarea></label>
        </div>
        <div class="business-actions"><button class="btn ghost small" type="button" @click="resetContactForm">Nouveau contact</button></div>
      </form>

      <div class="business-panel business-panel--wide">
        <header class="business-panel-head"><div><p class="eyebrow">Consentements</p><h2>{{ selectedContact?.display_name || 'Sélectionnez un contact' }}</h2></div></header>
        <div v-if="!contactForm.id" class="business-empty">Ouvrez un contact pour gérer ses canaux et consentements.</div>
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
      </div>

      <div v-if="canCrmManage" class="business-panel business-panel--wide">
        <header class="business-panel-head">
          <div>
            <p class="eyebrow">Import CSV</p>
            <h2>Contacts</h2>
          </div>
          <button class="btn ghost small" type="button" @click="resetContactImport">Réinitialiser</button>
        </header>
        <div class="business-import">
          <input ref="contactImportInput" type="file" accept=".csv,text/csv" @change="onContactImportFile">
          <label class="business-check"><input v-model="contactImportOptions.create_companies" type="checkbox"> Créer les entreprises absentes</label>
          <label class="business-check"><input v-model="contactImportOptions.update_existing" type="checkbox"> Mettre à jour les contacts existants</label>
          <button class="btn small" type="button" :disabled="!contactImportFile || busy === 'contacts.import.dry-run'" @click="runContactImport(false)">Dry-run</button>
          <button class="btn primary small" type="button" :disabled="!contactImportFile || !contactImportReport || contactImportErrors.length > 0 || busy === 'contacts.import.real'" @click="runContactImport(true)">Importer</button>
        </div>
        <div v-if="contactImportReport" class="business-import-report">
          <strong>
            {{ contactImportReport.dry_run ? 'Dry-run' : 'Import' }} :
            {{ contactImportReport.valid_rows ?? 0 }} valide(s),
            {{ contactImportReport.skipped ?? 0 }} erreur(s),
            {{ contactImportReport.created ?? 0 }} créé(s),
            {{ contactImportReport.updated ?? 0 }} mis à jour
          </strong>
          <div v-if="contactImportErrors.length" class="business-list">
            <article v-for="entry in contactImportErrors.slice(0, 8)" :key="`${entry.line}-${entry.message}`" class="business-list-row">
              <strong>Ligne {{ entry.line }}</strong>
              <span>{{ entry.message }}</span>
            </article>
          </div>
          <div v-else-if="contactImportRows.length" class="business-preview">
            <span v-for="row in contactImportRows.slice(0, 8)" :key="`${row.line}-${row.display_name}`">
              Ligne {{ row.line }} · {{ row.action }} · {{ row.display_name }}
            </span>
          </div>
        </div>
      </div>
    </section>

    <section v-if="activeTab === 'memos'" class="business-grid">
      <div class="business-panel business-panel--wide">
        <header class="business-panel-head">
          <div><p class="eyebrow">CRM</p><h2>Mémos</h2></div>
          <form class="business-filters" @submit.prevent="loadMemos">
            <input v-model="memoFilter.q" type="search" placeholder="Recherche titre ou texte">
            <select v-model="memoFilter.company_id"><option value="">Toutes entreprises</option><option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}</option></select>
            <select v-model="memoFilter.contact_id"><option value="">Tous contacts</option><option v-for="contact in contacts" :key="contact.id" :value="contact.id">{{ contact.display_name }}</option></select>
            <button class="btn small" type="submit">Filtrer</button>
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
            <label>Expiration<input v-model="memoForm.public_expires_at" type="text" placeholder="YYYY-MM-DD HH:MM:SS"></label>
            <button class="btn small" type="submit" :disabled="busy === 'memo.share.public'">Créer lien public</button>
          </form>
          <div v-if="oneTimeShareUrl" class="business-copy-box">
            <strong>Lien affiché une seule fois</strong>
            <code>{{ oneTimeShareUrl }}</code>
            <button class="btn small" type="button" @click="copyShareUrl">Copier</button>
          </div>
          <div class="business-actions"><button class="btn ghost small" type="button" :disabled="busy === 'memo.share.public.revoke'" @click="revokePublicMemoShares">Révoquer liens publics</button></div>
          <div class="business-list">
            <article v-for="share in memoShares" :key="share.id" class="business-list-row"><strong>{{ share.share_type }}</strong><span>{{ share.public_label || `#${share.id}` }}</span><small>{{ share.revoked_at ? 'révoqué' : 'actif' }}</small></article>
          </div>
        </template>
      </div>
    </section>

    <section v-if="activeTab === 'mailing'" class="business-grid">
      <div v-if="!canMailingRead" class="business-panel business-panel--wide business-empty">Permission mailing lecture requise.</div>
      <template v-else>
        <div class="business-panel">
          <header class="business-panel-head"><div><p class="eyebrow">Mailing</p><h2>Listes</h2></div></header>
          <div class="business-list">
            <p v-if="!mailingLists.length" class="business-empty">Aucune liste.</p>
            <button v-for="list in mailingLists" :key="list.id" type="button" class="business-list-button" :class="{ selected: list.id === mailingListForm.id }" @click="editMailingList(list)">
              <strong>{{ list.name }}</strong><span>{{ list.channel }} · {{ list.status }}</span>
            </button>
          </div>
        </div>
        <form class="business-panel" @submit.prevent="saveMailingList">
          <header class="business-panel-head"><div><p class="eyebrow">{{ mailingListForm.id ? `#${mailingListForm.id}` : 'Nouvelle' }}</p><h2>Liste</h2></div><button v-if="canMailingManage" class="btn primary small" type="submit">Enregistrer</button></header>
          <div class="business-form">
            <label>Nom<input v-model="mailingListForm.name" type="text" :disabled="!canMailingManage" required></label>
            <label>Clé<input v-model="mailingListForm.list_key" type="text" :disabled="!canMailingManage" placeholder="auto"></label>
            <label>Canal<select v-model="mailingListForm.channel" :disabled="!canMailingManage"><option v-for="channel in channels" :key="channel" :value="channel">{{ channel }}</option></select></label>
            <label>Statut<select v-model="mailingListForm.status" :disabled="!canMailingManage"><option value="active">active</option><option value="archived">archived</option></select></label>
            <label class="business-span">Description<textarea v-model="mailingListForm.description" rows="3" :disabled="!canMailingManage"></textarea></label>
          </div>
          <div class="business-actions"><button class="btn ghost small" type="button" @click="resetMailingListForm">Nouvelle liste</button></div>
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
        <div class="business-panel">
          <header class="business-panel-head"><div><p class="eyebrow">Campagnes</p><h2>Brouillons</h2></div></header>
          <div class="business-list">
            <p v-if="!campaigns.length" class="business-empty">Aucune campagne.</p>
            <button v-for="campaign in campaigns" :key="campaign.id" type="button" class="business-list-button" :class="{ selected: campaign.id === campaignForm.id }" @click="editCampaign(campaign)">
              <strong>{{ campaign.name }}</strong><span>{{ campaign.channel }} · {{ campaign.status }}</span>
            </button>
          </div>
        </div>
        <form class="business-panel business-panel--wide" @submit.prevent="saveCampaign">
          <header class="business-panel-head"><div><p class="eyebrow">{{ campaignForm.id ? `#${campaignForm.id}` : 'Nouvelle' }}</p><h2>Campagne</h2></div><button v-if="canMailingManage" class="btn primary small" type="submit">Enregistrer</button></header>
          <div class="business-form">
            <label>Liste<select v-model="campaignForm.list_id" :disabled="!canMailingManage"><option value="">—</option><option v-for="list in mailingLists" :key="list.id" :value="list.id">{{ list.name }}</option></select></label>
            <label>Canal<select v-model="campaignForm.channel" :disabled="!canMailingManage"><option v-for="channel in channels" :key="channel" :value="channel">{{ channel }}</option></select></label>
            <label>Nom<input v-model="campaignForm.name" type="text" :disabled="!canMailingManage" required></label>
            <label>Sujet<input v-model="campaignForm.subject" type="text" :disabled="!canMailingManage"></label>
            <label class="business-span">Texte<textarea v-model="campaignForm.body_text" rows="5" :disabled="!canMailingManage"></textarea></label>
          </div>
          <div class="business-actions">
            <button class="btn ghost small" type="button" @click="resetCampaignForm">Nouvelle campagne</button>
            <button class="btn small" type="button" :disabled="!campaignForm.id || busy === 'mailing.campaign.preview'" @click="previewCampaignRecipients">Prévisualiser destinataires</button>
            <button v-if="canMailingManage" class="btn small" type="button" :disabled="!campaignForm.id || busy === 'mailing.campaign.enqueue'" @click="enqueueCampaign">Mettre en file</button>
          </div>
          <div v-if="campaignPreview.length" class="business-preview">
            <strong>{{ campaignPreview.length }} destinataire(s)</strong>
            <span v-for="recipient in campaignPreview.slice(0, 8)" :key="String(recipient.contact_id)">{{ recipient.display_name || recipient.recipient_value }}</span>
          </div>
        </form>
      </template>
    </section>

    <section v-if="activeTab === 'messaging'" class="business-grid">
      <div v-if="!canMessagingAdmin" class="business-panel business-panel--wide business-empty">Permission administration messaging requise.</div>
      <template v-else>
        <div class="business-panel">
          <header class="business-panel-head"><div><p class="eyebrow">Providers</p><h2>Disponibles</h2></div></header>
          <div class="business-list">
            <article v-for="provider in allProviders" :key="`${provider.key || provider.provider_key}-${provider.channel}`" class="business-list-row">
              <strong>{{ provider.provider_key || provider.key }}</strong>
              <span>{{ provider.channel }}</span>
              <StatusBadge :status="provider.enabled === false ? 'disabled' : 'enabled'" />
            </article>
            <p v-if="!allProviders.length" class="business-empty">Aucun provider déclaré.</p>
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
        <div class="business-panel business-panel--wide">
          <header class="business-panel-head">
            <div><p class="eyebrow">Outbox</p><h2>Messages</h2></div>
            <form class="business-filters" @submit.prevent="loadMessaging">
              <select v-model="messageFilter.status"><option v-for="status in messageStatuses" :key="status" :value="status">{{ status || 'Tous statuts' }}</option></select>
              <button class="btn small" type="submit">Filtrer</button>
            </form>
          </header>
          <div v-if="!outbox.length" class="business-empty">Aucun message outbox.</div>
          <table v-else class="business-table">
            <thead><tr><th>#</th><th>Canal</th><th>Destinataire</th><th>Statut</th><th>Erreur</th></tr></thead>
            <tbody>
              <tr v-for="message in outbox" :key="message.id">
                <td>{{ message.id }}</td>
                <td>{{ message.channel }}</td>
                <td>{{ message.recipient_value }}</td>
                <td><StatusBadge :status="message.status" /></td>
                <td>{{ message.last_error || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </section>
  </section>
</template>

<style scoped>
.business-crm {
  --business-border: #d7dee8;
  --business-muted: #667085;
  --business-surface: #fff;
}

.business-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: .4rem;
  border-bottom: 1px solid var(--business-border);
}

.business-tabs button {
  border: 0;
  border-bottom: 3px solid transparent;
  background: transparent;
  color: #344054;
  padding: .7rem .85rem;
  font-weight: 700;
}

.business-tabs button.active {
  border-color: #14532d;
  color: #14532d;
}

.business-tabs button.locked {
  color: #98a2b3;
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
}

.business-form {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: .7rem;
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
  .business-panel--wide {
    display: block;
  }

  .business-panel {
    margin-bottom: 1rem;
  }

  .business-panel-head {
    flex-direction: column;
  }

  .business-filters {
    justify-content: flex-start;
  }
}

@media (max-width: 760px) {
  .business-form,
  .business-consent-row,
  .business-list-row,
  .business-list-button {
    grid-template-columns: 1fr;
  }

  .business-table {
    display: block;
    overflow-x: auto;
    white-space: nowrap;
  }
}
</style>
