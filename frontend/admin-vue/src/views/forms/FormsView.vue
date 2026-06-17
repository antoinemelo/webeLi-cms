<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { adminApi, apiErrorMessage } from '@/api/client';
import { useAdminContextStore } from '@/stores/adminContext';
import type { FormDefinition, FormField, FormFieldTranslation, FormSubmission, FormTranslation } from '@/api/contracts';
import PageHeader from '@/components/ui/PageHeader.vue';
import ApiFeedback from '@/components/feedback/ApiFeedback.vue';

type LanguageLite = { code: string; name?: string; is_default?: boolean; is_enabled?: boolean };
type FormsBlueprintSchema = { label?: string; description?: string; admin?: { title?: string }; blueprint_key?: string; resource?: string };

const context = useAdminContextStore();
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const notice = ref('');
const forms = ref<FormDefinition[]>([]);
const selectedId = ref<number | null>(null);
const editingNew = ref(false);
const submissions = ref<FormSubmission[]>([]);
const activeLanguage = ref(normalizeCode(context.contentLanguageCode || 'fr'));
const formsBlueprintSchema = ref<FormsBlueprintSchema | null>(null);

const languages = computed<LanguageLite[]>(() => {
  const fromContext = context.contentLanguages
    .filter((lang) => lang.is_enabled !== false)
    .map((lang) => ({ code: normalizeCode(lang.code), name: lang.name, is_default: lang.is_default, is_enabled: lang.is_enabled }));
  return fromContext.length ? fromContext : [{ code: 'fr', name: 'Français', is_default: true, is_enabled: true }];
});
const defaultLanguage = computed(() => languages.value.find((lang) => lang.is_default)?.code || languages.value[0]?.code || 'fr');
const isDefaultLanguage = computed(() => activeLanguage.value === defaultLanguage.value);
const selected = computed(() => selectedId.value ? forms.value.find((f) => f.id === selectedId.value) : null);
const hasOpenDraft = computed(() => selectedId.value !== null || editingNew.value);
const exportHref = computed(() => selectedId.value ? adminApi.href(`/forms/${selectedId.value}/export.csv`) : '#');
const activeLanguageName = computed(() => languageName(activeLanguage.value));
const defaultLanguageName = computed(() => languageName(defaultLanguage.value));
const formsBlueprintFromContext = computed<FormsBlueprintSchema | null>(() => {
  const formsModule = context.activeModules.find((module) => module.key === 'forms');
  const blueprints = Array.isArray(formsModule?.blueprints) ? formsModule.blueprints : [];
  const blueprint = blueprints.find((item) => item.blueprint_key === 'forms_form' || item.resource === 'form');
  return blueprint ? blueprint as FormsBlueprintSchema : null;
});
const formsResolvedBlueprint = computed(() => formsBlueprintSchema.value || formsBlueprintFromContext.value);
const formsPageTitle = computed(() => nonEmpty(formsResolvedBlueprint.value?.label) || 'Formulaire');
const formsPageInfo = computed(() => nonEmpty(formsResolvedBlueprint.value?.description) || 'Créer les formulaires, gérer les champs, consulter les soumissions et exporter les données.');

function fallbackSubmitLabel(code: string): string { return code.startsWith('en') ? 'Send' : code.startsWith('de') ? 'Senden' : 'Envoyer'; }
function fallbackSuccessMessage(code: string): string {
  if (code.startsWith('en')) return 'Thank you, your message has been sent.';
  if (code.startsWith('de')) return 'Vielen Dank, Ihre Nachricht wurde gesendet.';
  return 'Merci, votre message a été envoyé.';
}
function languageName(code: string): string { return languages.value.find((lang) => lang.code === normalizeCode(code))?.name || code.toUpperCase(); }
function normalizeCode(code: string): string { return String(code || 'fr').trim().toLowerCase() || 'fr'; }
function nonEmpty(value: unknown): string { return typeof value === 'string' ? value.trim() : ''; }

function makeTranslation(code: string, seed?: Partial<FormTranslation>): FormTranslation {
  const normalized = normalizeCode(code);
  return {
    language_code: normalized,
    name: seed?.name ?? (normalized === defaultLanguage.value ? 'Contact' : ''),
    description_text: seed?.description_text ?? '',
    submit_label: seed?.submit_label ?? fallbackSubmitLabel(normalized),
    success_message: seed?.success_message ?? fallbackSuccessMessage(normalized)
  };
}
function makeFieldTranslation(code: string, label = '', seed?: Partial<FormFieldTranslation>): FormFieldTranslation {
  const normalized = normalizeCode(code);
  return {
    language_code: normalized,
    label: seed?.label ?? (normalized === defaultLanguage.value ? label : ''),
    placeholder: seed?.placeholder ?? '',
    help_text: seed?.help_text ?? '',
    options: seed?.options ?? []
  };
}
function field(key: string, type: FormField['field_type'], label: string, required = false, width: FormField['width'] = 'full', validation: Record<string, unknown> = {}): FormField {
  return { field_key: key, field_type: type, is_required: required, is_active: true, width, validation, translations: languages.value.map((lang) => makeFieldTranslation(lang.code, label)) };
}
const defaultForm = (): FormDefinition => ({
  form_key: 'contact', status: 'draft', is_active: true, store_submissions: true, notification_enabled: false,
  notification_recipients: [], notification_subject: 'Nouvelle demande de contact', honeypot_field: 'website', min_submit_seconds: 2,
  rate_limit_max_attempts: 5, rate_limit_window_seconds: 900,
  translations: languages.value.map((lang) => makeTranslation(lang.code, { name: lang.code === defaultLanguage.value ? 'Contact' : '' })),
  fields: [
    field('name', 'text', 'Nom', true, 'half'),
    field('email', 'email', 'Email', true, 'half'),
    field('message', 'textarea', 'Message', true, 'full', { max: 5000 })
  ]
});
const draft = ref<FormDefinition>(defaultForm());

function ensureTranslations(form: FormDefinition): FormDefinition {
  const translations = [...(form.translations || [])].map((tr) => ({ ...tr, language_code: normalizeCode(tr.language_code) }));
  for (const lang of languages.value) {
    if (!translations.some((tr) => tr.language_code === lang.code)) {
      const fallback = translations.find((tr) => tr.language_code === defaultLanguage.value) || translations[0];
      translations.push(makeTranslation(lang.code, lang.code === defaultLanguage.value ? fallback : undefined));
    }
  }
  const fields = (form.fields || []).map((fieldDef) => {
    const fieldTranslations: FormFieldTranslation[] = [...(fieldDef.translations || [])].map((tr) => ({ ...tr, language_code: normalizeCode(tr.language_code), options: tr.options || [] }));
    const fallback = fieldTranslations.find((tr) => tr.language_code === defaultLanguage.value) || fieldTranslations.find((tr) => nonEmpty(tr.label) !== '') || fieldTranslations[0];
    const fallbackSeed: Partial<FormFieldTranslation> | undefined = fallback ? { ...fallback, options: fallback.options || [] } : undefined;
    for (const lang of languages.value) {
      if (!fieldTranslations.some((tr) => tr.language_code === lang.code)) {
        fieldTranslations.push(makeFieldTranslation(lang.code, lang.code === defaultLanguage.value ? (fallback?.label || fieldDef.field_key) : '', lang.code === defaultLanguage.value ? fallbackSeed : undefined));
      }
    }
    return { ...fieldDef, translations: fieldTranslations };
  });
  return { ...form, translations, fields };
}
function normalizeForm(form: FormDefinition): FormDefinition {
  return ensureTranslations({ ...defaultForm(), ...form, notification_recipients: form.notification_recipients ?? [], fields: form.fields?.length ? form.fields : [] });
}
function translationFor(code: string): FormTranslation { return draft.value.translations.find((tr) => tr.language_code === normalizeCode(code)) ?? makeTranslation(code); }
function setTranslation(code: string, patch: Partial<FormTranslation>) {
  const normalized = normalizeCode(code);
  const existing = translationFor(normalized);
  const next = { ...existing, ...patch, language_code: normalized };
  draft.value.translations = [...draft.value.translations.filter((tr) => tr.language_code !== normalized), next].sort((a, b) => a.language_code.localeCompare(b.language_code));
}
function fieldTranslation(fieldDef: FormField, code: string): FormFieldTranslation { return fieldDef.translations?.find((tr) => tr.language_code === normalizeCode(code)) ?? makeFieldTranslation(code, fieldDef.field_key); }
function translatedLabelPlaceholder(fieldDef: FormField, code: string): string {
  const current = fieldTranslation(fieldDef, code);
  if (nonEmpty(current.label) !== '') return 'Libellé';
  const fallback = fieldDef.translations?.find((tr) => tr.language_code === defaultLanguage.value) || fieldDef.translations?.find((tr) => nonEmpty(tr.label) !== '');
  const fallbackLabel = nonEmpty(fallback?.label) || fieldDef.field_key;
  return code === defaultLanguage.value ? 'Libellé' : `Fallback : ${fallbackLabel}`;
}
function updateFieldTranslation(index: number, code: string, patch: Partial<FormFieldTranslation>) {
  const normalized = normalizeCode(code);
  draft.value.fields = draft.value.fields.map((fieldDef, i) => {
    if (i !== index) return fieldDef;
    const existing = fieldTranslation(fieldDef, normalized);
    const next = { ...existing, ...patch, language_code: normalized, options: patch.options ?? existing.options ?? [] };
    return { ...fieldDef, translations: [...(fieldDef.translations || []).filter((tr) => tr.language_code !== normalized), next].sort((a, b) => a.language_code.localeCompare(b.language_code)) };
  });
}
function optionsText(fieldDef: FormField, code: string): string {
  return (fieldTranslation(fieldDef, code).options || []).map((option) => `${option.value}|${option.label}`).join('\n');
}
function updateOptions(index: number, code: string, raw: string) {
  const options = raw.split('\n').map((line) => line.trim()).filter(Boolean).map((line) => {
    const [value, ...labelParts] = line.split('|');
    const cleanValue = value.trim();
    const label = labelParts.join('|').trim() || cleanValue;
    return { value: cleanValue, label };
  });
  updateFieldTranslation(index, code, { options });
}
function normalizeRecipients(value: string) { draft.value.notification_recipients = value.split(/[;,\n]/).map((v) => v.trim()).filter(Boolean); }

async function loadFormsBlueprintSchema() {
  formsBlueprintSchema.value = formsBlueprintFromContext.value;
  try {
    const res = await adminApi.get<{ schema?: FormsBlueprintSchema }>('/modules/forms/resources/form/schema');
    const schema = res.data.schema || null;
    if (schema?.blueprint_key === 'forms_form' || schema?.resource === 'form') {
      formsBlueprintSchema.value = schema;
    }
  } catch (_) {
    formsBlueprintSchema.value = formsBlueprintFromContext.value;
  }
}

async function loadForms() {
  loading.value = true; error.value = '';
  try { const res = await adminApi.get<{ forms: FormDefinition[] }>('/forms', { lang: activeLanguage.value }); forms.value = res.data.forms; }
  catch (e) { error.value = apiErrorMessage(e); }
  finally { loading.value = false; }
}
async function openForm(id: number) {
  selectedId.value = id; editingNew.value = false; submissions.value = []; error.value = ''; notice.value = '';
  try {
    const res = await adminApi.get<{ form: FormDefinition }>(`/forms/${id}`, { lang: activeLanguage.value });
    draft.value = normalizeForm(res.data.form);
    const sub = await adminApi.get<{ submissions: FormSubmission[] }>(`/forms/${id}/submissions`, { limit: 50 });
    submissions.value = sub.data.submissions;
  } catch (e) { error.value = apiErrorMessage(e); }
}
function closeDraft() { selectedId.value = null; editingNew.value = false; submissions.value = []; draft.value = defaultForm(); }
function newForm() { selectedId.value = null; editingNew.value = true; submissions.value = []; notice.value = ''; error.value = ''; draft.value = defaultForm(); }
function addField() { draft.value.fields = [...draft.value.fields, field(`champ_${draft.value.fields.length + 1}`, 'text', 'Nouveau champ')]; }
function removeField(index: number) { draft.value.fields = draft.value.fields.filter((_, i) => i !== index); }
function updateField(index: number, patch: Partial<FormField>) { draft.value.fields = draft.value.fields.map((f, i) => i === index ? ensureTranslations({ ...draft.value, fields: [{ ...f, ...patch }] }).fields[0] : f); }
async function save() {
  if (!hasOpenDraft.value) return;
  saving.value = true; error.value = ''; notice.value = '';
  try {
    const payload = ensureTranslations({ ...draft.value, fields: draft.value.fields.map((f, i) => ({ ...f, sort_order: i })) });
    const res = selectedId.value ? await adminApi.patch<{ form: FormDefinition }>(`/forms/${selectedId.value}`, payload) : await adminApi.post<{ form: FormDefinition }>('/forms', payload);
    draft.value = normalizeForm(res.data.form); selectedId.value = res.data.form.id ?? null; editingNew.value = false; notice.value = 'Formulaire enregistré.'; await loadForms();
  } catch (e) { error.value = apiErrorMessage(e); }
  finally { saving.value = false; }
}
async function destroyForm() {
  if (!selectedId.value) return;
  if (!confirm('Supprimer ce formulaire et ses soumissions ?')) return;
  try { await adminApi.delete(`/forms/${selectedId.value}`); closeDraft(); await loadForms(); notice.value = 'Formulaire supprimé.'; }
  catch (e) { error.value = apiErrorMessage(e); }
}

watch(() => context.contentLanguageCode, (code) => {
  if (!code) return;
  activeLanguage.value = normalizeCode(code);
  draft.value = ensureTranslations(draft.value);
});
watch(languages, () => { draft.value = ensureTranslations(draft.value); });
onMounted(async () => { await loadFormsBlueprintSchema(); await loadForms(); });
</script>

<template>
  <section class="page-stack">
    <PageHeader :title="formsPageTitle" :intro="formsPageInfo" />
    <ApiFeedback :error="error" :notice="notice" />

    <div class="row g-3 align-items-start forms-admin">
      <aside class="col-12 col-xl-3">
        <div class="card forms-sidebar-card">
          <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
            <div>
              <h2 class="h5 mb-1">Formulaires</h2>
              <p class="text-muted small mb-0">Langue active : {{ activeLanguageName }}</p>
            </div>
            <button class="btn primary btn-sm flex-shrink-0" type="button" @click="newForm">+ Nouveau</button>
          </div>

          <p v-if="loading" class="text-muted mb-0">Chargement…</p>
          <div v-else class="list-group forms-list">
            <button
              v-for="form in forms"
              :key="form.id"
              type="button"
              class="list-group-item list-group-item-action forms-list-item"
              :class="{ active: form.id === selectedId }"
              @click="openForm(Number(form.id))"
            >
              <span class="fw-bold d-block text-truncate">{{ form.name || form.form_key }}</span>
              <span class="small d-block">{{ form.form_key }} · {{ form.status }} · {{ form.submission_count ?? 0 }} soumissions</span>
            </button>
          </div>
        </div>
      </aside>

      <main class="col-12 col-xl-9">
        <div v-if="!hasOpenDraft" class="card empty-state-card text-center py-5">
          <h2 class="h4 mb-2">Sélectionnez un formulaire</h2>
          <p class="text-muted mb-3">Ouvrez un formulaire existant dans la liste, ou créez-en un avec le bouton Nouveau.</p>
          <button class="btn primary mx-auto" type="button" @click="newForm">Créer un formulaire</button>
        </div>

        <form v-else class="forms-editor-shell" @submit.prevent="save">
          <div class="card forms-editor-toolbar">
            <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
              <div>
                <h2 class="h4 mb-1">{{ selectedId ? 'Modifier le formulaire' : 'Nouveau formulaire' }}</h2>
                <p class="text-muted mb-0">
                  <strong>{{ activeLanguageName }}</strong> est éditée dans la colonne des textes. Les paramètres communs restent identiques pour toutes les langues.
                </p>
              </div>
              <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
                <a v-if="selectedId" class="btn ghost btn-sm" :href="exportHref">Export CSV</a>
                <button v-if="selectedId" class="btn danger btn-sm" type="button" @click="destroyForm">Supprimer</button>
                <button class="btn ghost btn-sm" type="button" @click="closeDraft">Fermer</button>
                <button class="btn primary btn-sm" :disabled="saving">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
              </div>
            </div>
          </div>

          <div class="row g-3 forms-editor-grid">
            <section class="col-12 col-xxl-5">
              <div class="card forms-editor-panel h-100">
                <div class="forms-section-title">
                  <div>
                    <h3>Éléments communs</h3>
                    <p class="text-muted small mb-0">Réglages invariables, appliqués à toutes les langues.</p>
                  </div>
                </div>

                <div class="row g-3">
                  <div class="col-12">
                    <label class="form-label fw-semibold">Clé technique du formulaire</label>
                    <input class="form-control" v-model="draft.form_key" placeholder="contact" :disabled="!isDefaultLanguage">
                    <div class="form-text" v-if="!isDefaultLanguage">Modifiable uniquement en {{ defaultLanguageName }}.</div>
                  </div>
                  <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold">Statut</label>
                    <select class="form-select" v-model="draft.status"><option value="draft">Brouillon</option><option value="published">Publié</option><option value="archived">Archivé</option></select>
                  </div>
                  <div class="col-12 col-md-6 d-flex align-items-end gap-3 flex-wrap">
                    <label class="form-check mb-2"><input class="form-check-input" type="checkbox" v-model="draft.is_active"> <span class="form-check-label">Actif</span></label>
                    <label class="form-check mb-2"><input class="form-check-input" type="checkbox" v-model="draft.store_submissions"> <span class="form-check-label">Stocker</span></label>
                  </div>
                </div>

                <hr class="my-4">

                <div class="forms-section-title forms-section-title--compact"><h3>Notifications</h3></div>
                <div class="row g-3">
                  <div class="col-12">
                    <label class="form-check mb-0"><input class="form-check-input" type="checkbox" v-model="draft.notification_enabled"> <span class="form-check-label">Notifications email</span></label>
                  </div>
                  <div class="col-12">
                    <label class="form-label fw-semibold">Destinataires</label>
                    <textarea class="form-control" rows="2" :value="(draft.notification_recipients || []).join('\n')" @input="normalizeRecipients(($event.target as HTMLTextAreaElement).value)" />
                  </div>
                  <div class="col-12">
                    <label class="form-label fw-semibold">Sujet notification</label>
                    <input class="form-control" v-model="draft.notification_subject">
                  </div>
                </div>

                <hr class="my-4">

                <div class="forms-section-title forms-section-title--compact"><h3>Anti-spam</h3></div>
                <div class="row g-3">
                  <div class="col-12 col-md-6"><label class="form-label fw-semibold">Honeypot</label><input class="form-control" v-model="draft.honeypot_field"></div>
                  <div class="col-12 col-md-6"><label class="form-label fw-semibold">Délai minimum</label><input class="form-control" type="number" v-model.number="draft.min_submit_seconds"></div>
                  <div class="col-12 col-md-6"><label class="form-label fw-semibold">Max tentatives</label><input class="form-control" type="number" v-model.number="draft.rate_limit_max_attempts"></div>
                  <div class="col-12 col-md-6"><label class="form-label fw-semibold">Fenêtre secondes</label><input class="form-control" type="number" v-model.number="draft.rate_limit_window_seconds"></div>
                </div>
              </div>
            </section>

            <section class="col-12 col-xxl-7">
              <div class="card forms-editor-panel h-100">
                <div class="forms-section-title">
                  <div>
                    <h3>Textes traduisibles</h3>
                    <p class="text-muted small mb-0">Contenu édité pour la langue active : {{ activeLanguageName }}.</p>
                  </div>
                </div>

                <div class="row g-3">
                  <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold">Nom affiché</label>
                    <input class="form-control" :value="translationFor(activeLanguage).name" @input="setTranslation(activeLanguage, { name: ($event.target as HTMLInputElement).value })">
                  </div>
                  <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold">Libellé bouton</label>
                    <input class="form-control" :value="translationFor(activeLanguage).submit_label" @input="setTranslation(activeLanguage, { submit_label: ($event.target as HTMLInputElement).value })">
                  </div>
                  <div class="col-12">
                    <label class="form-label fw-semibold">Description</label>
                    <textarea class="form-control" rows="2" :value="translationFor(activeLanguage).description_text || ''" @input="setTranslation(activeLanguage, { description_text: ($event.target as HTMLTextAreaElement).value })" />
                  </div>
                  <div class="col-12">
                    <label class="form-label fw-semibold">Message de succès</label>
                    <input class="form-control" :value="translationFor(activeLanguage).success_message" @input="setTranslation(activeLanguage, { success_message: ($event.target as HTMLInputElement).value })">
                  </div>
                </div>
              </div>
            </section>
          </div>

          <section class="card forms-editor-panel forms-fields-panel">
            <div class="forms-section-title">
              <div>
                <h3>Champs</h3>
                <p class="text-muted small mb-0">Chaque champ distingue ses réglages communs et ses libellés localisés.</p>
              </div>
              <button class="btn ghost btn-sm" type="button" @click="addField">+ Champ</button>
            </div>

            <div class="forms-field-stack">
              <article v-for="(f, index) in draft.fields" :key="index" class="card forms-field-card">
                <div class="row g-3">
                  <div class="col-12 col-xl-5 forms-field-column forms-field-column--common">
                    <h4 class="forms-field-subtitle">Éléments communs</h4>
                    <div class="row g-2">
                      <div class="col-12 col-md-6 col-xl-12">
                        <label class="form-label fw-semibold">Clé technique</label>
                        <input class="form-control" :value="f.field_key" placeholder="field_key" :disabled="!isDefaultLanguage" @input="updateField(index, { field_key: ($event.target as HTMLInputElement).value })">
                      </div>
                      <div class="col-12 col-md-6 col-xl-12">
                        <label class="form-label fw-semibold">Type</label>
                        <select class="form-select" :value="f.field_type" @change="updateField(index, { field_type: ($event.target as HTMLSelectElement).value as FormField['field_type'] })"><option>text</option><option>textarea</option><option>email</option><option>tel</option><option>url</option><option>number</option><option>select</option><option>radio</option><option>checkbox</option><option>checkboxes</option><option>date</option><option>consent</option><option>hidden</option></select>
                      </div>
                      <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold">Largeur</label>
                        <select class="form-select" :value="f.width || 'full'" @change="updateField(index, { width: ($event.target as HTMLSelectElement).value as FormField['width'] })"><option value="full">100%</option><option value="half">50%</option><option value="third">33%</option></select>
                      </div>
                      <div class="col-12 col-md-6 d-flex align-items-end gap-3 flex-wrap">
                        <label class="form-check mb-2"><input class="form-check-input" type="checkbox" :checked="f.is_required" @change="updateField(index, { is_required: ($event.target as HTMLInputElement).checked })"> <span class="form-check-label">Requis</span></label>
                        <label class="form-check mb-2"><input class="form-check-input" type="checkbox" :checked="f.is_active !== false" @change="updateField(index, { is_active: ($event.target as HTMLInputElement).checked })"> <span class="form-check-label">Actif</span></label>
                      </div>
                    </div>
                  </div>

                  <div class="col-12 col-xl-7 forms-field-column">
                    <h4 class="forms-field-subtitle">Textes — {{ activeLanguageName }}</h4>
                    <div class="row g-2">
                      <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold">Libellé</label>
                        <input class="form-control" :value="fieldTranslation(f, activeLanguage).label" :placeholder="translatedLabelPlaceholder(f, activeLanguage)" @input="updateFieldTranslation(index, activeLanguage, { label: ($event.target as HTMLInputElement).value })">
                      </div>
                      <div class="col-12 col-md-6">
                        <label class="form-label fw-semibold">Placeholder</label>
                        <input class="form-control" :value="fieldTranslation(f, activeLanguage).placeholder || ''" placeholder="Placeholder" @input="updateFieldTranslation(index, activeLanguage, { placeholder: ($event.target as HTMLInputElement).value })">
                      </div>
                      <div class="col-12">
                        <label class="form-label fw-semibold">Aide</label>
                        <input class="form-control" :value="fieldTranslation(f, activeLanguage).help_text || ''" placeholder="Texte d’aide facultatif" @input="updateFieldTranslation(index, activeLanguage, { help_text: ($event.target as HTMLInputElement).value })">
                      </div>
                      <div v-if="['select','radio','checkboxes'].includes(f.field_type)" class="col-12">
                        <label class="form-label fw-semibold">Options localisées</label>
                        <textarea class="form-control" rows="3" placeholder="Options : valeur|libellé, une option par ligne" :value="optionsText(f, activeLanguage)" @input="updateOptions(index, activeLanguage, ($event.target as HTMLTextAreaElement).value)" />
                      </div>
                    </div>
                  </div>
                </div>
                <div class="d-flex justify-content-end mt-3">
                  <button class="btn danger btn-sm" type="button" @click="removeField(index)">Retirer le champ</button>
                </div>
              </article>
            </div>
          </section>

          <section v-if="selectedId" class="card forms-editor-panel submissions-panel">
            <div class="forms-section-title"><h3>Dernières soumissions</h3></div>
            <p v-if="submissions.length === 0" class="text-muted">Aucune soumission.</p>
            <article v-for="submission in submissions" :key="submission.id" class="submission-card">
              <strong>#{{ submission.id }} · {{ submission.created_at }} · {{ submission.language_code || '—' }}</strong>
              <pre>{{ submission.payload }}</pre>
            </article>
          </section>
        </form>
      </main>
    </div>
  </section>
</template>
