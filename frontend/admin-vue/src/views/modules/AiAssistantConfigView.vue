<script setup lang="ts">
import {
  computed,
  onBeforeUnmount,
  onMounted,
  reactive,
  ref,
  watch,
} from "vue";
import ApiFeedback from "@/components/feedback/ApiFeedback.vue";
import StatusBadge from "@/components/ui/StatusBadge.vue";
import InfoHint from "@/components/ui/InfoHint.vue";
import AiDiagnosticResultCard from "@/components/ai/AiDiagnosticResultCard.vue";
import { adminApi, apiErrorMessage } from "@/api/client";
import { useAdminContextStore } from "@/stores/adminContext";

type AiSetting = { value: unknown; description?: string; enabled?: boolean };
type AiProviderType = {
  key: string;
  name: string;
  description: string;
  sort_order: number;
  enabled: boolean;
  is_system: boolean;
};
type AiProvider = {
  id?: number;
  type_key: string;
  type_name?: string;
  key: string;
  name: string;
  provider_type: string;
  base_url?: string | null;
  api_key_ref?: string | null;
  enabled: boolean;
  is_default: boolean;
  options?: Record<string, unknown>;
};
type AiTestKind = "connection_provider" | "usage_configured" | "real_prompt";
type AiTestNature = AiTestKind | "streaming";
type DiagnosticBadge = { key?: string; label: string; tone?: string };
type AiModel = {
  id: number;
  provider_key: string;
  provider_name?: string;
  type_key?: string;
  usage_key?: string;
  usage_keys?: string[];
  usage_name?: string;
  usage_names?: string[];
  key: string;
  name: string;
  model_type: string;
  context_window: number;
  input_price: number;
  output_price: number;
  currency: string;
  price_unit?: string;
  api_key_ref?: string | null;
  effective_api_key_ref?: string | null;
  max_monthly_budget?: {
    amount?: number;
    currency?: string;
    mode?: "warn" | "block";
    warning_threshold?: number;
  };
  enabled: boolean;
  provider_enabled?: boolean;
  base_url?: string | null;
};
type AiUsage = {
  id: number;
  provider_key: string;
  model_key: string;
  task_type: string;
  input_tokens?: number;
  output_tokens?: number;
  total_tokens: number;
  duration_ms: number;
  estimated_cost: number;
  currency: string;
  status?: "success" | "failed" | "blocked" | string;
  details?: AiTestDiagnostic | Record<string, unknown>;
  created_at: string;
};
type AiSiteUsageSetting = {
  id?: number | null;
  site_id: number;
  usage_key: string;
  usage_name?: string;
  mode: "inherit" | "enabled" | "disabled";
  model_id?: number | null;
  provider_key?: string | null;
  model_key?: string | null;
  resolved_provider_name?: string | null;
  resolved_model_name?: string | null;
  translation_enabled?: boolean;
  seo_enabled?: boolean;
  notes?: string;
  updated_at?: string | null;
};
type AiStatus = {
  module?: Record<string, unknown>;
  database?: Record<string, unknown>;
};
type AiBudgetSummary = {
  site_id?: number;
  scope?: string;
  scope_label?: string;
  month?: string;
  total_cost: number;
  currency: string;
  input_tokens?: number;
  output_tokens?: number;
  total_tokens?: number;
  successful_calls: number;
  failed_calls: number;
  calls: number;
  cost_by_usage?: Array<{
    usage_key: string;
    cost: number;
    calls: number;
    success_calls: number;
    failed_calls: number;
    input_tokens?: number;
    output_tokens?: number;
    currency: string;
  }>;
  spend_by_model?: Array<{
    provider_key: string;
    model_key: string;
    calls: number;
    success_calls: number;
    failed_calls: number;
    input_tokens: number;
    output_tokens: number;
    total_tokens: number;
    currencies: Array<{ currency: string; cost: number; calls: number }>;
  }>;
};
// Marqueurs UX détail simplifié: Explication · Correction
type AiTestDiagnostic = {
  success?: boolean;
  ok?: boolean;
  error_type?: string | null;
  error_message?: string | null;
  human_message?: string | null;
  suggested_fix?: string | null;
  status_code?: number;
  duration_ms?: number;
  site_id?: number;
  site_label?: string;
  endpoint?: string;
  provider?: string;
  provider_key?: string;
  provider_label?: string;
  model?: string;
  model_key?: string;
  model_label?: string;
  usage_key?: string;
  usage_label?: string;
  action_key?: string | null;
  prompt_key?: string | null;
  prompt_name?: string | null;
  prompt_source?: string | null;
  system_message?: string | null;
  user_message?: string | null;
  variables_used?: string[];
  variables_missing?: string[];
  variables_unknown?: string[];
  prompt?: string;
  content?: string;
  response_preview?: string;
  provider_response_id?: string | null;
  body_preview?: string | null;
  json?: unknown;
  usage?: Record<string, unknown>;
  input_tokens?: number;
  output_tokens?: number;
  total_tokens?: number;
  external_calls?: boolean;
  test_kind?: string;
  estimated_cost?: number;
  currency?: string;
  price_unit?: string;
  budget?: Record<string, unknown>;
  diagnostic_badges?: DiagnosticBadge[];
};
type AiBlueprintField = {
  key?: string;
  field_key?: string;
  handle?: string;
  label?: string;
  help_text?: string;
  config?: { help_text?: string };
};
type AiBlueprint = {
  resource?: string;
  blueprint_key?: string;
  fields?: AiBlueprintField[];
};
type SectionKey = "site" | "catalog" | "operations";
type CatalogTabKey = "types" | "providers" | "models";
type OperationsTabKey = "expenses" | "tests" | "streaming" | "activation" | "suggestions";
type AiStreamState = "idle" | "connecting" | "generating" | "done" | "error";
type AiSuggestionStatus =
  | "draft"
  | "proposed"
  | "accepted"
  | "rejected"
  | "applied"
  | "expired";
type AiSuggestion = {
  id: number;
  site_id?: number | null;
  target_type: string;
  target_id: string;
  target_label?: string;
  suggestion_type: string;
  status: AiSuggestionStatus | string;
  source_field?: string | null;
  target_field?: string | null;
  preview_text: string;
  reason?: string;
  created_at: string;
  accepted_at?: string | null;
  rejected_at?: string | null;
  applied_at?: string | null;
  expired_at?: string | null;
  applied_action_run_id?: number | null;
  history?: Array<Record<string, unknown>>;
  allowed_transitions?: string[];
  suggestion?: unknown;
};

type AiWizardObjectiveKey = "editorial" | "seo" | "translation" | "media" | "embeddings" | "local";
type AiWizardStep = "objective" | "provider" | "secret" | "model" | "test" | "activate" | "summary";
type AiWizardObjective = {
  key: AiWizardObjectiveKey;
  label: string;
  usage_key: string;
  action_key: string;
  recommended_provider_type: string;
  description: string;
  prompt: string;
};

const context = useAdminContextStore();
const loading = ref(false);
const saving = ref(false);
const testing = ref(false);
const streaming = ref(false);
const streamState = ref<AiStreamState>("idle");
const streamText = ref("");
const streamError = ref("");
const streamResult = ref<AiTestDiagnostic | null>(null);
const streamSource = ref<EventSource | null>(null);
const error = ref("");
const success = ref("");
const activeSection = ref<SectionKey>("site");
const activeCatalogTab = ref<CatalogTabKey>("models");
const activeOperationsTab = ref<OperationsTabKey>("expenses");
const status = ref<AiStatus>({});
const settings = ref<Record<string, AiSetting>>({});
const providerTypes = ref<AiProviderType[]>([]);
const providers = ref<AiProvider[]>([]);
const models = ref<AiModel[]>([]);
const usage = ref<AiUsage[]>([]);
const budgetSummary = ref<AiBudgetSummary | null>(null);
const suggestions = ref<AiSuggestion[]>([]);
const selectedSuggestionId = ref<number | null>(null);
const suggestionStatusFilter = ref<"all" | AiSuggestionStatus>("all");
const suggestionApplyRunId = ref(0);
const selectedUsageEventId = ref<number | null>(null);
const activeHistoryFilter = ref<"all" | "success" | "failed" | "streaming" | "content" | "hidden">("hidden");
const hiddenUsageEventIds = ref<Set<number>>(new Set());
const hiddenUsageStorageKey = "ai-assistant.hidden-test-events.v1";
const siteUsageSettings = ref<AiSiteUsageSetting[]>([]);
const blueprints = ref<AiBlueprint[]>([]);
const selectedProviderKey = ref("");
const selectedProviderUsageKey = ref("");
const testResult = ref<AiTestDiagnostic | null>(null);
const testKind = ref<AiTestNature>("connection_provider");
const selectedUsageKey = ref("editorial");
const testUsageKey = ref("");
const testPrompt = ref("Répondez uniquement par : OK CMS");
const catalogModal = ref<CatalogTabKey | null>(null);
const catalogModalMode = ref<"new" | "edit">("new");

const wizardStepOrder: AiWizardStep[] = [
  "objective",
  "provider",
  "secret",
  "model",
  "test",
  "activate",
  "summary",
];
const wizardObjectives: AiWizardObjective[] = [
  {
    key: "editorial",
    label: "Rédaction éditoriale",
    usage_key: "editorial",
    action_key: "rewrite",
    recommended_provider_type: "openai_compatible",
    description: "Réécriture, résumé, brouillon et aide éditoriale.",
    prompt: "Réécris ce court texte de manière claire : Bonjour, voici un test CMS.",
  },
  {
    key: "seo",
    label: "SEO",
    usage_key: "editorial",
    action_key: "meta_description",
    recommended_provider_type: "openai_compatible",
    description: "Métadonnées, description de page et amélioration de visibilité.",
    prompt: "Rédige une méta-description de 150 caractères pour une page de test CMS.",
  },
  {
    key: "translation",
    label: "Traduction",
    usage_key: "editorial",
    action_key: "draft",
    recommended_provider_type: "openai_compatible",
    description: "Préparation de brouillons de traduction multilingue.",
    prompt: "Traduis en allemand : Bonjour, ceci est un test de traduction CMS.",
  },
  {
    key: "media",
    label: "Médias / alt text",
    usage_key: "image",
    action_key: "alt_text",
    recommended_provider_type: "openai_compatible",
    description: "Textes alternatifs, description et assistance médias.",
    prompt: "Propose un texte alternatif court pour une photo de montagne au lever du soleil.",
  },
  {
    key: "embeddings",
    label: "Embeddings / recherche sémantique",
    usage_key: "embeddings",
    action_key: "embed",
    recommended_provider_type: "openai_compatible",
    description: "Préparation de la recherche sémantique et du RAG.",
    prompt: "Test de configuration embeddings pour le CMS.",
  },
  {
    key: "local",
    label: "Local / privé",
    usage_key: "local",
    action_key: "rewrite",
    recommended_provider_type: "ollama",
    description: "Configuration locale ou privée, utile pour tests et données sensibles.",
    prompt: "Réponds uniquement par : OK local CMS.",
  },
];
const wizard = reactive({
  open: false,
  step: "objective" as AiWizardStep,
  objective_key: "editorial" as AiWizardObjectiveKey,
  provider_key: "",
  api_key_ref: "env:OPENAI_API_KEY",
  model_id: 0,
  test_result: null as AiTestDiagnostic | null,
  completed: false,
});

const typeForm = reactive({
  key: "",
  name: "",
  description: "",
  sort_order: 100,
  enabled: true,
  is_system: false,
});
const providerForm = reactive({
  key: "",
  name: "",
  type_key: "editorial",
  provider_type: "openai_compatible",
  base_url: "",
  api_key_ref: "",
  enabled: false,
  is_default: false,
});
const modelForm = reactive({
  provider_key: "",
  usage_keys: ["editorial"] as string[],
  key: "",
  name: "",
  model_type: "chat",
  context_window: 0,
  input_price: 0,
  output_price: 0,
  price_unit: "1m_tokens",
  currency: "CHF",
  api_key_ref: "",
  budget_amount: 0,
  budget_currency: "CHF",
  budget_mode: "block" as "warn" | "block",
  enabled: false,
});
const usageForm = reactive({
  site_id: 0,
  usage_key: "editorial",
  mode: "enabled" as "inherit" | "enabled" | "disabled",
  model_id: 0,
  translation_enabled: false,
  seo_enabled: false,
  notes: "",
});
const suggestionFilterOptions: Array<"all" | AiSuggestionStatus> = [
  "all",
  "draft",
  "proposed",
  "accepted",
  "rejected",
  "applied",
  "expired",
];
const aiTestKindOptions: Array<{
  key: AiTestNature;
  label: string;
  help: string;
}> = [
  {
    key: "connection_provider",
    label: "Connexion",
    help: "Vérifie que le fournisseur IA répond avec la configuration actuelle.",
  },
  {
    key: "real_prompt",
    label: "Prompt",
    help: "Teste le prompt envoyé avec le modèle actif pour l’usage choisi.",
  },
  {
    key: "streaming",
    label: "Streaming",
    help: "Teste la réception progressive de la réponse du modèle.",
  },
];

const canManage = computed(() => context.can("ai.provider.manage"));
const canReadLogs = computed(() => context.can("ai.logs.read"));
const canReadSuggestions = computed(() => context.can("ai.suggestions.read"));
const canManageSuggestions = computed(() =>
  context.can("ai.suggestions.manage"),
);
const canApplySuggestions = computed(() => context.can("ai.actions.apply"));
const aiEnabled = computed({
  get: () => Boolean(settings.value["ai.enabled"]?.value),
  set: (value: boolean) => {
    settings.value["ai.enabled"] = {
      ...(settings.value["ai.enabled"] ?? {}),
      value,
    };
  },
});
const logPrompts = computed({
  get: () => Boolean(settings.value["ai.log_prompts"]?.value),
  set: (value: boolean) => {
    settings.value["ai.log_prompts"] = {
      ...(settings.value["ai.log_prompts"] ?? {}),
      value,
    };
  },
});
const logResponses = computed({
  get: () => Boolean(settings.value["ai.log_responses"]?.value),
  set: (value: boolean) => {
    settings.value["ai.log_responses"] = {
      ...(settings.value["ai.log_responses"] ?? {}),
      value,
    };
  },
});
const currentSite = computed(() => context.context?.site ?? null);
const mainSite = computed(
  () => context.availableSites[0] ?? context.context?.site ?? null,
);
const isMainSite = computed(() =>
  Boolean(
    currentSite.value &&
    mainSite.value &&
    currentSite.value.id === mainSite.value.id,
  ),
);
const sortByName = <T extends { name?: string; key?: string }>(
  items: T[],
): T[] =>
  [...items].sort((a, b) =>
    String(a.name || a.key || "").localeCompare(
      String(b.name || b.key || ""),
      "fr",
      { sensitivity: "base" },
    ),
  );
const sortedProviderTypes = computed(() => sortByName(providerTypes.value));
const sortedProviders = computed(() => sortByName(providers.value));
const sortedModels = computed(() => sortByName(models.value));
const enabledUsages = computed(() =>
  sortedProviderTypes.value.filter((type) => type.enabled),
);
const usageSettingsByKey = computed(
  () => new Map(siteUsageSettings.value.map((item) => [item.usage_key, item])),
);
const effectiveUsageSettings = computed(() =>
  enabledUsages.value.map(
    (type) =>
      usageSettingsByKey.value.get(type.key) ?? defaultUsageSetting(type.key),
  ),
);
const activeModels = computed(() =>
  sortedModels.value.filter((model) => model.enabled && model.provider_enabled),
);
const modelsForUsage = computed(
  () => (usageKey: string) =>
    sortedModels.value.filter((model) =>
      modelUsageKeys(model).includes(usageKey),
    ),
);
const selectedUsage = computed(
  () =>
    enabledUsages.value.find((item) => item.key === selectedUsageKey.value) ??
    enabledUsages.value[0] ??
    null,
);
const selectedTestUsage = computed(
  () =>
    enabledUsages.value.find((item) => item.key === testUsageKey.value) ??
    enabledUsages.value[0] ??
    null,
);
const selectedTestUsageSetting = computed(
  () =>
    effectiveUsageSettings.value.find(
      (item) =>
        item.usage_key === (selectedTestUsage.value?.key ?? testUsageKey.value),
    ) ?? null,
);
const selectedTestModel = computed(() => {
  const setting = selectedTestUsageSetting.value;
  const configured = setting ? usageModel(setting) : null;
  if (configured) return configured;
  const usageKey = selectedTestUsage.value?.key || testUsageKey.value;
  return (
    activeModels.value.find((model) =>
      modelUsageKeys(model).includes(usageKey),
    ) ?? null
  );
});
const selectedBudgetConfig = computed(
  () =>
    selectedTestModel.value?.max_monthly_budget ?? {
      amount: 0,
      currency: selectedTestModel.value?.currency || "CHF",
      mode: "block",
    },
);
const selectedUsageBudgetCost = computed(() => {
  const usageKey =
    selectedTestUsage.value?.key || testUsageKey.value || "editorial";
  return Number(
    budgetSummary.value?.cost_by_usage?.find(
      (item) => item.usage_key === usageKey,
    )?.cost ?? 0,
  );
});
const selectedBudgetPercentage = computed(() => {
  const amount = Number(selectedBudgetConfig.value?.amount ?? 0);
  return amount > 0
    ? Math.round((selectedUsageBudgetCost.value / amount) * 1000) / 10
    : 0;
});
const selectedBudgetStatus = computed(() => {
  const amount = Number(selectedBudgetConfig.value?.amount ?? 0);
  if (amount <= 0) return "unlimited";
  if (selectedUsageBudgetCost.value >= amount) return "blocked";
  if (selectedBudgetPercentage.value >= 80) return "warning";
  return "ok";
});
const visibleUsageEvents = computed(() =>
  usage.value.filter((event) => !hiddenUsageEventIds.value.has(event.id)),
);
const hiddenUsageEvents = computed(() =>
  usage.value.filter((event) => hiddenUsageEventIds.value.has(event.id)),
);
const nonStreamingUsageEvents = computed(() =>
  visibleUsageEvents.value.filter((event) => !isStreamingUsageEvent(event)),
);
const streamingUsageEvents = computed(() =>
  visibleUsageEvents.value.filter((event) => isStreamingUsageEvent(event)),
);
const allVisibleUsageEvents = computed(() =>
  [...visibleUsageEvents.value].sort((a, b) =>
    String(b.created_at || "").localeCompare(String(a.created_at || "")),
  ),
);
const usageEventsForActiveTestTab = computed(() => {
  if (activeHistoryFilter.value === "hidden") return [];
  let events = allVisibleUsageEvents.value;
  if (activeHistoryFilter.value === "success") {
    events = events.filter((event) => String(event.status || "success") === "success");
  } else if (activeHistoryFilter.value === "failed") {
    events = events.filter((event) => ["failed", "blocked"].includes(String(event.status || "")));
  } else if (activeHistoryFilter.value === "streaming") {
    events = events.filter((event) => isStreamingUsageEvent(event));
  } else if (activeHistoryFilter.value === "content") {
    events = events.filter((event) => usageEventHasContent(event));
  }
  return events;
});
const selectedUsageEvent = computed(
  () =>
    usageEventsForActiveTestTab.value.find((event) => event.id === selectedUsageEventId.value) ??
    null,
);
const selectedUsageEventResult = computed(() =>
  usageEventDiagnostic(selectedUsageEvent.value),
);
const displayedTestResult = computed(() =>
  selectedUsageEventResult.value || streamResult.value || testResult.value,
);
const filteredSuggestions = computed(() =>
  suggestionStatusFilter.value === "all"
    ? suggestions.value
    : suggestions.value.filter(
        (item) => item.status === suggestionStatusFilter.value,
      ),
);
const selectedSuggestion = computed(
  () =>
    suggestions.value.find((item) => item.id === selectedSuggestionId.value) ??
    filteredSuggestions.value[0] ??
    null,
);
const activeProvider = computed(
  () =>
    sortedProviders.value.find((provider) => provider.is_default) ??
    sortedProviders.value.find((provider) => provider.enabled) ??
    sortedProviders.value[0] ??
    null,
);
const activeSiteUsages = computed(() =>
  effectiveUsageSettings.value.filter((setting) => {
    if (setting.mode === "disabled") return false;
    return Boolean(setting.resolved_model_name || usageModel(setting));
  }),
);
const activeSiteUsageSummary = computed(() => {
  if (activeSiteUsages.value.length) {
    return activeSiteUsages.value
      .map(
        (setting) =>
          `${typeName.value(setting.usage_key)} : ${setting.resolved_model_name || usageModel(setting)?.name || "modèle hérité"}`,
      )
      .join(" · ");
  }
  if (!isMainSite.value && effectiveUsageSettings.value.some((setting) => setting.mode === "inherit")) {
    return "Configuration héritée du site principal";
  }
  return "Aucun modèle activé";
});
const filteredProviders = computed(() =>
  sortedProviders.value.filter(
    (provider) =>
      !selectedProviderUsageKey.value ||
      provider.type_key === selectedProviderUsageKey.value,
  ),
);
const filteredActiveProviders = computed(() =>
  filteredProviders.value.filter((provider) => provider.enabled),
);
const filteredModels = computed(() =>
  sortedModels.value.filter(
    (model) =>
      !selectedProviderKey.value ||
      model.provider_key === selectedProviderKey.value,
  ),
);
const filteredActiveModels = computed(() =>
  filteredModels.value.filter(
    (model) => model.enabled && model.provider_enabled,
  ),
);
const providerModels = computed(
  () => (providerKey: string) =>
    sortedModels.value.filter((model) => model.provider_key === providerKey),
);
const providerName = computed(
  () => (providerKey: string) =>
    sortedProviders.value.find((provider) => provider.key === providerKey)
      ?.name || providerKey,
);
const typeName = computed(
  () => (typeKey: string) =>
    sortedProviderTypes.value.find((type) => type.key === typeKey)?.name ||
    typeKey,
);
const statusCards = computed(() => [
  {
    label: "IA globale",
    value: aiEnabled.value ? "Activée" : "Désactivée",
    status: aiEnabled.value ? "actif" : "désactivé",
  },
  {
    label: "Tokens IA",
    value: budgetSummary.value
      ? `${integer(budgetSummary.value.input_tokens)} in / ${integer(budgetSummary.value.output_tokens)} out`
      : "À charger",
    status: selectedBudgetStatus.value === "blocked"
      ? "alerte"
      : selectedBudgetStatus.value === "warning"
        ? "alerte"
        : "ok",
  },
  {
    label: "Configuration",
    value: activeSiteUsageSummary.value,
    status: activeSiteUsages.value.length ? "actif" : "désactivé",
  },
  {
    label: "Base IA",
    value: status.value.database?.available ? "OK" : "À vérifier",
    status: status.value.database?.available ? "ok" : "alerte",
  },
]);
const sections = computed(() => [
  {
    key: "site" as const,
    label: "Configuration",
  },
  {
    key: "catalog" as const,
    label: "Catalogue",
  },
  {
    key: "operations" as const,
    label: "Assistant IA",
  },
]);
const catalogTabs = computed(() => [
  {
    key: "types" as const,
    label: "Usages",
    hint: `${providerTypes.value.length}`,
  },
  {
    key: "providers" as const,
    label: "Fournisseurs",
    hint: `${providers.value.length}`,
  },
  {
    key: "models" as const,
    label: "Modèles",
    hint: `${activeModels.value.length}/${models.value.length}`,
  },
]);
const operationsTabs = computed(() => [
  {
    key: "expenses" as const,
    label: "Dépenses",
  },
  {
    key: "tests" as const,
    label: "Tests",
  },
  {
    key: "activation" as const,
    label: "Activation",
  },
]);
const wizardObjective = computed(
  () =>
    wizardObjectives.find((item) => item.key === wizard.objective_key) ??
    wizardObjectives[0],
);
const wizardUsageKey = computed(() => wizardObjective.value.usage_key);
const wizardProviderOptions = computed(() => {
  const usageKey = wizardUsageKey.value;
  const preferred = wizardObjective.value.recommended_provider_type;
  const rows = sortedProviders.value.filter((provider) => {
    if (provider.key === "null_provider") return true;
    if (provider.type_key === usageKey) return true;
    if (usageKey === "image") {
      return providerModels.value(provider.key).some((model) =>
        modelUsageKeys(model).includes("image"),
      );
    }
    if (usageKey === "local") {
      return ["local", "ollama", "null"].includes(provider.provider_type);
    }
    return [preferred, "openai_compatible", "ollama"].includes(
      provider.provider_type,
    );
  });
  return rows.sort((a, b) => {
    const score = (provider: AiProvider): number => {
      if (provider.provider_type === preferred) return 0;
      if (provider.key === "null_provider") return 1;
      if (provider.enabled) return 2;
      return 3;
    };
    return score(a) - score(b) || a.name.localeCompare(b.name, "fr");
  });
});
const wizardProvider = computed(
  () =>
    sortedProviders.value.find((provider) => provider.key === wizard.provider_key) ??
    wizardProviderOptions.value[0] ??
    null,
);
const wizardModelOptions = computed(() => {
  const providerKey = wizardProvider.value?.key || wizard.provider_key;
  const usageKey = wizardUsageKey.value;
  return sortedModels.value.filter(
    (model) =>
      model.provider_key === providerKey && modelUsageKeys(model).includes(usageKey),
  );
});
const wizardModel = computed(
  () => wizardModelOptions.value.find((model) => model.id === wizard.model_id) ?? wizardModelOptions.value[0] ?? null,
);
const wizardStepIndex = computed(() => wizardStepOrder.indexOf(wizard.step));
const wizardActiveUsages = computed(() =>
  effectiveUsageSettings.value.filter((setting) => setting.mode !== "disabled"),
);
const wizardMissingUsages = computed(() =>
  enabledUsages.value.filter(
    (type) =>
      !effectiveUsageSettings.value.some(
        (setting) => setting.usage_key === type.key && setting.mode !== "disabled",
      ),
  ),
);

function blueprintField(
  resource: string,
  fieldKey: string,
): AiBlueprintField | null {
  const normalizedResource = String(resource || "").trim();
  const normalizedField = String(fieldKey || "").trim();
  const blueprint = blueprints.value.find((item) => {
    const resourceValue = String(item.resource || "").trim();
    const keyValue = String(item.blueprint_key || "").trim();
    return (
      resourceValue === normalizedResource ||
      keyValue === `ai_assistant_${normalizedResource}`
    );
  });
  return (
    blueprint?.fields?.find((item) =>
      [item.key, item.field_key, item.handle]
        .map((value) => String(value || "").trim())
        .includes(normalizedField),
    ) ?? null
  );
}
function blueprintFieldHelp(resource: string, fieldKey: string): string {
  const field = blueprintField(resource, fieldKey);
  return String(field?.help_text || field?.config?.help_text || "").trim();
}
function blueprintFieldLabel(resource: string, fieldKey: string): string {
  const field = blueprintField(resource, fieldKey);
  return String(field?.label || fieldKey).trim();
}
function blueprintHasHelp(resource: string, fieldKey: string): boolean {
  return blueprintFieldHelp(resource, fieldKey) !== "";
}

function defaultUsageSetting(usageKey: string): AiSiteUsageSetting {
  const siteId = Number(currentSite.value?.id ?? context.siteId ?? 0);
  return {
    id: null,
    site_id: siteId,
    usage_key: usageKey,
    usage_name: typeName.value(usageKey),
    mode: usageKey === "editorial" ? "enabled" : "disabled",
    model_id: null,
    translation_enabled: false,
    seo_enabled: false,
    notes: "",
  };
}
function money(value: number, currency: string): string {
  return `${String(currency || "CHF").toUpperCase()} ${Number(value || 0).toFixed(2)}`;
}
function modelBudgetCurrency(model: AiModel): string {
  return String(
    model.currency || model.max_monthly_budget?.currency || "CHF",
  ).toUpperCase();
}
function usageEventCurrency(event: AiUsage): string {
  const model = models.value.find(
    (item) =>
      item.key === event.model_key && item.provider_key === event.provider_key,
  );
  return String(model?.currency || event.currency || "CHF").toUpperCase();
}

function testSucceeded(result: AiTestDiagnostic | null): boolean {
  return Boolean(result?.success ?? result?.ok ?? false);
}
function testStatusLabel(result: AiTestDiagnostic | null): string {
  if (!result) return "Aucun test";
  return testSucceeded(result)
    ? "Succès"
    : `Échec · ${result.error_type || "unknown_error"}`;
}
function testStatusClass(result: AiTestDiagnostic | null): string {
  if (!result) return "is-idle";
  return testSucceeded(result) ? "is-success" : "is-error";
}
function testHumanMessage(result: AiTestDiagnostic | null): string {
  if (!result) return "Le résultat apparaîtra ici.";
  return String(
    result.human_message ||
      result.error_message ||
      (testSucceeded(result)
        ? "Le provider IA a répondu correctement."
        : "Le test IA a échoué."),
  );
}
function testSuggestedFix(result: AiTestDiagnostic | null): string {
  if (!result) return "Choisissez un usage configuré puis lancez le test.";
  return String(
    result.suggested_fix ||
      (testSucceeded(result)
        ? "Aucune action corrective nécessaire."
        : "Vérifiez la configuration du fournisseur et du modèle."),
  );
}

function currentTestKindHelp(): string {
  return (
    aiTestKindOptions.find((item) => item.key === testKind.value)?.help || ""
  );
}
function testKindLabel(kind: string | undefined): string {
  return (
    aiTestKindOptions.find((item) => item.key === kind)?.label ||
    "Prompt"
  );
}
function actionKeyForTest(usageKey: string): string {
  const map: Record<string, string> = {
    editorial: "rewrite",
    seo: "meta_description",
    translation: "draft",
    media: "alt_text",
    image: "alt_text",
  };
  return map[usageKey] || "";
}
function diagnosticVariables(): Record<string, string> {
  return {
    input: testPrompt.value,
    text: testPrompt.value,
    source_text: testPrompt.value,
    locale: "fr",
    target_locale: "de",
    title: "Titre de test",
    image_context: testPrompt.value || "Image de test du CMS",
    site_name: currentSite.value?.name || "DEC CMS",
  };
}
function streamStatusLabel(): string {
  if (streamState.value === "connecting") return "Connexion au flux…";
  if (streamState.value === "generating") return "Génération en cours…";
  if (streamState.value === "done") return "Terminé";
  if (streamState.value === "error") return "Erreur streaming";
  return "Prêt";
}
function streamStatusClass(): string {
  return `is-${streamState.value}`;
}
function closeStream(): void {
  if (streamSource.value) {
    streamSource.value.close();
    streamSource.value = null;
  }
  streaming.value = false;
}
function testedUsageLabel(result: AiTestDiagnostic | null): string {
  return String(
    result?.usage_label ||
      typeName.value(result?.usage_key || testUsageKey.value || "") ||
      "—",
  );
}
function testedProviderLabel(result: AiTestDiagnostic | null): string {
  return String(
    result?.provider_label || result?.provider_key || result?.provider || "—",
  );
}
function testedModelLabel(result: AiTestDiagnostic | null): string {
  return String(
    result?.model_label || result?.model_key || result?.model || "—",
  );
}
function testedPrompt(result: AiTestDiagnostic | null): string {
  return String(
    result?.user_message ||
      result?.prompt ||
      testPrompt.value ||
      "Répondez uniquement par : OK CMS",
  );
}
function selectedTestModelLabel(): string {
  const setting = selectedTestUsageSetting.value;
  const model = selectedTestModel.value;
  if (!setting) return "Aucun usage sélectionné";
  if (model) return model.name;
  if (setting.mode === "disabled")
    return "Usage désactivé pour ce site, mais modèle compatible disponible si activé";
  return setting.resolved_model_name || "Aucun modèle configuré";
}
function testTechnicalDetails(
  result: AiTestDiagnostic | null,
): Record<string, unknown> {
  if (!result) return {};
  const {
    success,
    error_type,
    error_message,
    status_code,
    duration_ms,
    site_id,
    site_label,
    endpoint,
    diagnostic_badges,
    usage_key,
    usage_label,
    action_key,
    prompt_key,
    prompt_name,
    prompt_source,
    provider_key,
    provider_label,
    model_key,
    model_label,
    system_message,
    user_message,
    prompt,
    variables_used,
    variables_missing,
    variables_unknown,
    response_preview,
    provider_response_id,
    body_preview,
    json,
    usage,
    external_calls,
    content,
    test_kind,
    estimated_cost,
    currency,
    price_unit,
    budget,
  } = result;
  return {
    success,
    error_type,
    error_message,
    status_code,
    duration_ms,
    site_id,
    site_label,
    endpoint,
    diagnostic_badges,
    usage_key,
    usage_label,
    action_key,
    prompt_key,
    prompt_name,
    prompt_source,
    provider_key,
    provider_label,
    model_key,
    model_label,
    system_message,
    user_message,
    prompt,
    variables_used,
    variables_missing,
    variables_unknown,
    response_preview,
    provider_response_id,
    body_preview,
    json,
    usage,
    external_calls,
    content,
    test_kind,
    estimated_cost,
    currency,
    price_unit,
    budget,
  };
}
function applyTestResult(
  result: AiTestDiagnostic | null,
  successMessage: string,
): void {
  testResult.value = result;
  if (testSucceeded(result)) {
    success.value = successMessage;
    error.value = "";
  } else {
    success.value = "";
    error.value = "";
  }
}
function loadHiddenUsageEventIds(): void {
  try {
    const raw = window.localStorage.getItem(hiddenUsageStorageKey);
    const ids = JSON.parse(raw || "[]") as unknown;
    hiddenUsageEventIds.value = new Set(
      Array.isArray(ids)
        ? ids.map((id) => Number(id)).filter((id) => Number.isFinite(id) && id > 0)
        : [],
    );
  } catch {
    hiddenUsageEventIds.value = new Set();
  }
}
function saveHiddenUsageEventIds(): void {
  try {
    window.localStorage.setItem(
      hiddenUsageStorageKey,
      JSON.stringify(Array.from(hiddenUsageEventIds.value.values())),
    );
  } catch {
    /* localStorage peut être indisponible selon le navigateur. */
  }
}
function hideUsageEventsFromDisplay(eventsToHide: AiUsage[]): void {
  if (!eventsToHide.length) return;
  const next = new Set(hiddenUsageEventIds.value);
  for (const event of eventsToHide) next.add(event.id);
  hiddenUsageEventIds.value = next;
  saveHiddenUsageEventIds();
}
function restoreHiddenUsageEvents(): void {
  hiddenUsageEventIds.value = new Set();
  saveHiddenUsageEventIds();
  selectedUsageEventId.value = usageEventsForActiveTestTab.value[0]?.id ?? null;
  success.value = "Historique d’affichage des tests restauré. Les dépenses n’ont pas été modifiées.";
}

function selectUsageEvent(event: AiUsage): void {
  selectedUsageEventId.value = event.id;
}
function selectSuggestion(suggestion: AiSuggestion): void {
  selectedSuggestionId.value = suggestion.id;
}
function setSuggestionFilter(status: "all" | AiSuggestionStatus): void {
  suggestionStatusFilter.value = status;
  void load();
}
function suggestionStatusLabel(status: string): string {
  const labels: Record<string, string> = {
    draft: "brouillon",
    proposed: "proposée",
    accepted: "acceptée",
    rejected: "rejetée",
    applied: "appliquée",
    expired: "expirée",
  };
  return labels[status] || status;
}
function suggestionCan(
  suggestion: AiSuggestion | null,
  transition: string,
): boolean {
  return Boolean(suggestion?.allowed_transitions?.includes(transition));
}
function suggestionActionRunLabel(suggestion: AiSuggestion | null): string {
  if (!suggestion) return "—";
  return suggestion.applied_action_run_id
    ? `Action #${suggestion.applied_action_run_id}`
    : "Non appliquée";
}
function priceUnitLabel(unit: string | undefined): string {
  const labels: Record<string, string> = {
    "1m_tokens": "par 1M tokens",
    "1k_tokens": "par 1k tokens",
    token: "par token",
  };
  return labels[String(unit || "1m_tokens")] || String(unit || "1m_tokens");
}
function budgetModeLabel(mode: string | undefined): string {
  return mode === "warn" ? "Avertir seulement" : "Bloquer au dépassement";
}
function usageStatusLabel(status: string | undefined): string {
  if (status === "blocked") return "bloqué";
  if (status === "failed") return "échec";
  return "succès";
}
function isStreamingUsageEvent(event: AiUsage | null): boolean {
  const taskType = String(event?.task_type || "");
  if (taskType.startsWith("stream.test.")) return true;
  const details = event?.details && !Array.isArray(event.details) ? event.details as Record<string, unknown> : {};
  return Boolean(
    details?.streaming === true ||
    String(details?.test_kind || "") === "stream_test" ||
    String(details?.endpoint || "").includes("/stream")
  );
}
function usageEventHasContent(event: AiUsage | null): boolean {
  const details = usageEventDiagnostic(event);
  return Boolean(
    String(details?.content || "").trim() ||
    String(details?.response_preview || "").trim() ||
    String(details?.body_preview || "").trim() ||
    String(details?.user_message || "").trim() ||
    String(details?.prompt || "").trim(),
  );
}
function setHistoryFilter(filter: "all" | "success" | "failed" | "streaming" | "content" | "hidden"): void {
  activeHistoryFilter.value = filter;
  if (filter === "hidden") {
    selectedUsageEventId.value = null;
    return;
  }
  const currentVisible = usageEventsForActiveTestTab.value.some((event) => event.id === selectedUsageEventId.value);
  if (!currentVisible) {
    selectedUsageEventId.value = usageEventsForActiveTestTab.value[0]?.id ?? null;
  }
}
function usageEventUsageLabel(event: AiUsage): string {
  const details = usageEventDiagnostic(event);
  const usageKey = String(details?.usage_label || details?.usage_key || event.task_type || "Usage");
  return typeName.value(usageKey);
}
function usageEventConfigurationLabel(event: AiUsage): string {
  const details = usageEventDiagnostic(event);
  const provider = String(details?.provider_label || details?.provider_key || event.provider_key || "—");
  const model = String(details?.model_label || details?.model_key || event.model_key || "—");
  return `${provider} · ${model}`;
}
function usageEventCardClass(event: AiUsage): string {
  if (String(event.status || "") === "failed" || String(event.status || "") === "blocked") return "ai-test-card--failed";
  if (isStreamingUsageEvent(event)) return "ai-test-card--streaming";
  return "ai-test-card--success";
}
function usageEventTitle(event: AiUsage): string {
  const details = usageEventDiagnostic(event);
  return String(
    details?.test_kind ? testKindLabel(String(details.test_kind)) :
    isStreamingUsageEvent(event) ? "Test avec streaming" : "Test sans streaming",
  );
}
function usageEventDiagnostic(event: AiUsage | null): AiTestDiagnostic | null {
  if (!event) return null;
  const details = event.details && !Array.isArray(event.details) ? event.details as AiTestDiagnostic : {};
  const usageDetails = details.usage && typeof details.usage === "object" && !Array.isArray(details.usage) ? details.usage : {};
  const inputTokens = Number(usageDetails.input_tokens ?? usageDetails.prompt_tokens ?? event.input_tokens ?? 0);
  const outputTokens = Number(usageDetails.output_tokens ?? usageDetails.completion_tokens ?? event.output_tokens ?? 0);
  const totalTokens = Number(usageDetails.total_tokens ?? event.total_tokens ?? inputTokens + outputTokens);
  return {
    ...details,
    status_code: Number(details.status_code ?? 0),
    duration_ms: Number(details.duration_ms ?? event.duration_ms ?? 0),
    estimated_cost: Number(details.estimated_cost ?? event.estimated_cost ?? 0),
    currency: String(details.currency || event.currency || "CHF"),
    input_tokens: inputTokens,
    output_tokens: outputTokens,
    total_tokens: totalTokens,
    usage: {
      ...usageDetails,
      input_tokens: inputTokens,
      output_tokens: outputTokens,
      total_tokens: totalTokens,
    },
    provider_key: details.provider_key || event.provider_key,
    provider_label: details.provider_label || providerName.value(event.provider_key),
    model_key: details.model_key || event.model_key,
    model_label: details.model_label || modelDisplayName(event.provider_key, event.model_key),
    test_kind: details.test_kind || (isStreamingUsageEvent(event) ? "stream_test" : "usage_configured"),
  };
}
function usageEventReadableResponse(event: AiUsage | null): string {
  const details = usageEventDiagnostic(event);
  return String(
    details?.content ||
      details?.response_preview ||
      details?.body_preview ||
      "Aucun résultat détaillé enregistré pour cet ancien test. Les nouveaux tests conserveront le diagnostic complet.",
  );
}
function usageEventPrompt(event: AiUsage | null): string {
  const details = usageEventDiagnostic(event);
  return String(details?.user_message || details?.prompt || "Prompt non enregistré pour cet ancien test.");
}
function budgetStatusText(): string {
  if (selectedBudgetStatus.value === "unlimited")
    return "Aucun plafond mensuel configuré.";
  if (selectedBudgetStatus.value === "blocked")
    return "Budget dépassé : les appels externes sont bloqués si le modèle est en mode blocage.";
  if (selectedBudgetStatus.value === "warning")
    return "Avertissement : plus de 80 % du budget est consommé.";
  return "Budget disponible.";
}

function integer(value: unknown): string {
  return Number(value || 0).toLocaleString('fr-CH');
}
function modelDisplayName(providerKey: string, modelKey: string): string {
  const model = models.value.find(
    (item) => item.provider_key === providerKey && item.key === modelKey,
  );
  return model?.name || modelKey;
}
function modelProviderDisplayName(providerKey: string): string {
  return providerName.value(providerKey);
}
function monthlyBudgetLabel(): string {
  if (!budgetSummary.value) return 'Budget mensuel partagé';
  return `${budgetSummary.value.scope_label || 'Tous les sites et sous-sites du CMS'} · ${budgetSummary.value.month || 'mois courant'}`;
}
function totalCostByCurrency(): Array<{ currency: string; cost: number; calls: number }> {
  const totals = new Map<string, { currency: string; cost: number; calls: number }>();
  for (const model of budgetSummary.value?.spend_by_model || []) {
    for (const currency of model.currencies || []) {
      const key = String(currency.currency || 'CHF').toUpperCase();
      const row = totals.get(key) || { currency: key, cost: 0, calls: 0 };
      row.cost += Number(currency.cost || 0);
      row.calls += Number(currency.calls || 0);
      totals.set(key, row);
    }
  }
  if (!totals.size && budgetSummary.value) {
    totals.set(String(budgetSummary.value.currency || 'CHF').toUpperCase(), {
      currency: String(budgetSummary.value.currency || 'CHF').toUpperCase(),
      cost: Number(budgetSummary.value.total_cost || 0),
      calls: Number(budgetSummary.value.calls || 0),
    });
  }
  return Array.from(totals.values());
}
function streamReadableMessage(): string {
  if (streamError.value) return streamError.value;
  if (streamState.value === 'idle') return 'Lancez un test pour vérifier si le provider peut renvoyer des tokens progressivement.';
  if (streamState.value === 'connecting') return 'Connexion SSE ouverte, attente du premier événement du provider.';
  if (streamState.value === 'generating') return 'Réponse en cours de génération. Les tokens reçus s’affichent ci-dessous.';
  if (streamState.value === 'done') return 'Streaming terminé. Le résultat peut être comparé au test sans streaming.';
  return 'Le streaming a échoué. Utilisez le fallback sans streaming ou vérifiez la configuration PHP/FPM.';
}
function streamMetaLabel(): string {
  const usageKey = streamResult.value?.usage_key || testUsageKey.value;
  const provider = streamResult.value?.provider_label || streamResult.value?.provider_key || selectedTestModel.value?.provider_name || '—';
  const model = streamResult.value?.model_label || streamResult.value?.model_key || selectedTestModel.value?.name || '—';
  return `${typeName.value(usageKey)} · ${provider} · ${model}`;
}
function frCount(
  count: number,
  singular: string,
  plural = `${singular}s`,
): string {
  const n = Number(count || 0);
  return `${n} ${n > 1 ? plural : singular}`;
}
function formatDate(value: string): string {
  const d = new Date((value || "").replace(" ", "T"));
  return Number.isNaN(d.getTime())
    ? "—"
    : d.toLocaleString("fr-CH", { dateStyle: "short", timeStyle: "short" });
}
function resetTypeForm(): void {
  Object.assign(typeForm, {
    key: "",
    name: "",
    description: "",
    sort_order: 100,
    enabled: true,
    is_system: false,
  });
}
function editType(type: AiProviderType): void {
  Object.assign(typeForm, { ...type });
}
function resetProviderForm(): void {
  Object.assign(providerForm, {
    key: "",
    name: "",
    type_key: enabledUsages.value[0]?.key || "editorial",
    provider_type: "openai_compatible",
    base_url: "",
    api_key_ref: "",
    enabled: false,
    is_default: false,
  });
}
function editProvider(provider: AiProvider): void {
  Object.assign(providerForm, {
    key: provider.key,
    name: provider.name,
    type_key: provider.type_key || "editorial",
    provider_type: provider.provider_type,
    base_url: provider.base_url || "",
    api_key_ref: provider.api_key_ref || "",
    enabled: provider.enabled,
    is_default: provider.is_default,
  });
}
function resetModelForm(
  providerKey = selectedProviderKey.value || activeProvider.value?.key || "",
): void {
  const usageKey = selectedUsageKey.value || "editorial";
  Object.assign(modelForm, {
    provider_key: providerKey,
    usage_keys: [usageKey],
    key: "",
    name: "",
    model_type: technicalTypeForUsage(usageKey),
    context_window: 0,
    input_price: 0,
    output_price: 0,
    price_unit: "1m_tokens",
    currency: "CHF",
    api_key_ref: "",
    budget_amount: 0,
    budget_currency: "CHF",
    budget_mode: "block",
    enabled: false,
  });
}
function editModel(model: AiModel): void {
  Object.assign(modelForm, {
    provider_key: model.provider_key,
    usage_keys: modelUsageKeys(model),
    key: model.key,
    name: model.name,
    model_type: model.model_type,
    context_window: model.context_window,
    input_price: model.input_price,
    output_price: model.output_price,
    price_unit: model.price_unit || "1m_tokens",
    currency: model.currency,
    api_key_ref: model.api_key_ref || "",
    budget_amount: Number(model.max_monthly_budget?.amount ?? 0),
    budget_currency: modelBudgetCurrency(model),
    budget_mode: model.max_monthly_budget?.mode || "block",
    enabled: model.enabled,
  });
}
function openNewType(): void {
  activeCatalogTab.value = "types";
  catalogModalMode.value = "new";
  resetTypeForm();
  catalogModal.value = "types";
}
function openEditType(type: AiProviderType): void {
  activeCatalogTab.value = "types";
  catalogModalMode.value = "edit";
  editType(type);
  catalogModal.value = "types";
}
function openNewProvider(): void {
  activeCatalogTab.value = "providers";
  catalogModalMode.value = "new";
  resetProviderForm();
  catalogModal.value = "providers";
}
function openEditProvider(provider: AiProvider): void {
  activeCatalogTab.value = "providers";
  catalogModalMode.value = "edit";
  editProvider(provider);
  catalogModal.value = "providers";
}
function openNewModel(
  providerKey = selectedProviderKey.value || activeProvider.value?.key || "",
): void {
  activeCatalogTab.value = "models";
  catalogModalMode.value = "new";
  resetModelForm(providerKey);
  catalogModal.value = "models";
}
function openEditModel(model: AiModel): void {
  activeCatalogTab.value = "models";
  catalogModalMode.value = "edit";
  editModel(model);
  catalogModal.value = "models";
}
function closeCatalogModal(): void {
  catalogModal.value = null;
}
const catalogModalTitle = computed(() => {
  const action = catalogModalMode.value === "new" ? "Nouvel" : "Modifier";
  if (catalogModal.value === "types")
    return catalogModalMode.value === "new"
      ? "Nouvel usage IA"
      : "Modifier l’usage IA";
  if (catalogModal.value === "providers") return `${action} fournisseur`;
  return catalogModalMode.value === "new"
    ? "Nouveau modèle"
    : "Modifier le modèle";
});
const catalogNewButtonLabel = computed(() => {
  if (activeCatalogTab.value === "types") return "Nouvel usage";
  if (activeCatalogTab.value === "providers") return "Nouveau fournisseur";
  return "Nouveau modèle";
});
const currentProviderForDelete = computed(
  () => providers.value.find((item) => item.key === providerForm.key) ?? null,
);
const currentModelForDelete = computed(
  () =>
    models.value.find(
      (item) =>
        item.provider_key === modelForm.provider_key &&
        item.key === modelForm.key,
    ) ?? null,
);
const canDeleteCurrentProvider = computed(() =>
  Boolean(
    currentProviderForDelete.value &&
    canDeleteProvider(currentProviderForDelete.value),
  ),
);
const canDeleteCurrentModel = computed(() =>
  Boolean(
    currentModelForDelete.value && canDeleteModel(currentModelForDelete.value),
  ),
);
function openNewCatalogItem(): void {
  if (activeCatalogTab.value === "types") {
    openNewType();
    return;
  }
  if (activeCatalogTab.value === "providers") {
    openNewProvider();
    return;
  }
  openNewModel();
}
function canDeleteProvider(provider: AiProvider): boolean {
  return provider.key !== "null_provider";
}
function canDeleteModel(model: AiModel): boolean {
  return !(
    model.provider_key === "null_provider" && model.key === "null_text_model"
  );
}
function modelStatus(model: AiModel): string {
  if (!model.enabled) return "désactivé";
  if (!model.provider_enabled) return "fournisseur inactif";
  return "ok";
}
function modelIsSelectable(model: AiModel): boolean {
  return Boolean(model.enabled && model.provider_enabled);
}
function modelUsageKeys(model: AiModel): string[] {
  const keys =
    Array.isArray(model.usage_keys) && model.usage_keys.length
      ? model.usage_keys
      : [model.usage_key || model.type_key || "editorial"];
  return keys.filter(Boolean);
}
function modelUsageLabel(model: AiModel): string {
  const names =
    Array.isArray(model.usage_names) && model.usage_names.length
      ? model.usage_names
      : modelUsageKeys(model).map((key) => typeName.value(key));
  return names.join(", ");
}
function toggleModelUsage(usageKey: string): void {
  const current = new Set(modelForm.usage_keys);
  if (current.has(usageKey)) current.delete(usageKey);
  else current.add(usageKey);
  modelForm.usage_keys = Array.from(current);
  modelForm.model_type = technicalTypeForUsages(modelForm.usage_keys);
}
function technicalTypeForUsages(usageKeys: string[]): string {
  const keys = Array.from(new Set(usageKeys.filter(Boolean)));
  if (
    ["editorial", "automation", "development"].some((item) =>
      keys.includes(item),
    )
  )
    return "chat";
  const key =
    [
      "embeddings",
      "reranking",
      "transcription",
      "voice",
      "image",
      "local",
    ].find((item) => keys.includes(item)) || "editorial";
  return technicalTypeForUsage(key);
}
function technicalTypeForUsage(usageKey: string): string {
  const map: Record<string, string> = {
    editorial: "chat",
    embeddings: "embedding",
    reranking: "reranker",
    transcription: "speech_to_text",
    voice: "audio",
    image: "image",
    development: "chat",
    automation: "chat",
    local: "local",
  };
  return map[usageKey] || "chat";
}
function technicalTypeLabel(value: string): string {
  const labels: Record<string, string> = {
    chat: "texte",
    embedding: "embeddings",
    reranker: "re-ranking",
    speech_to_text: "transcription",
    audio: "vocal",
    image: "image",
    code: "texte/code",
    local: "local",
  };
  return labels[value] || value;
}
function cleanedApiKeyRef(value: string): string {
  const ref = String(value || "").trim();
  return ref === "vide = clé du fournisseur" ? "" : ref;
}
function syncUsageForm(usageKey: string): void {
  const setting =
    usageSettingsByKey.value.get(usageKey) ?? defaultUsageSetting(usageKey);
  selectedUsageKey.value = usageKey;
  Object.assign(usageForm, {
    site_id: Number(currentSite.value?.id ?? context.siteId ?? 0),
    usage_key: usageKey,
    mode: setting.mode ?? (usageKey === "editorial" ? "enabled" : "disabled"),
    model_id: Number(setting.model_id ?? 0),
    translation_enabled: Boolean(setting.translation_enabled),
    seo_enabled: Boolean(setting.seo_enabled),
    notes: setting.notes ?? "",
  });
}
function usageModel(setting: AiSiteUsageSetting): AiModel | null {
  return models.value.find((model) => model.id === setting.model_id) ?? null;
}
function usageStatus(setting: AiSiteUsageSetting): string {
  if (setting.mode === "enabled") return "actif";
  if (setting.mode === "inherit") return "ok";
  return "désactivé";
}
function usageAvailabilityStatus(setting: AiSiteUsageSetting): string {
  const model = usageModel(setting);
  if (setting.mode !== "enabled" || !model) return "";
  if (!model.enabled) return "modèle désactivé";
  if (!model.provider_enabled) return "fournisseur inactif";
  return "";
}
function modeLabel(mode: "inherit" | "enabled" | "disabled"): string {
  if (mode === "inherit") return "Appliquer le choix principal";
  if (mode === "enabled") return "Choisir un modèle";
  return "Désactiver cet usage";
}
function modeDescription(mode: "inherit" | "enabled" | "disabled"): string {
  if (mode === "inherit")
    return "Le site courant reprend la configuration du site principal.";
  if (mode === "enabled")
    return "Le site courant utilise un modèle propre pour cet usage.";
  return "Aucun modèle IA ne sera utilisé pour cet usage.";
}
const ruleOptions = computed(() => {
  const base: Array<"inherit" | "enabled" | "disabled"> = isMainSite.value
    ? ["enabled", "disabled"]
    : ["inherit", "enabled", "disabled"];
  return base.map((mode) => ({
    mode,
    label: modeLabel(mode),
    description: modeDescription(mode),
  }));
});

function openWizard(objective: AiWizardObjectiveKey = "editorial"): void {
  wizard.open = true;
  wizard.step = "objective";
  wizard.objective_key = objective;
  wizard.completed = false;
  wizard.test_result = null;
  syncWizardDefaults();
}
function closeWizard(): void {
  wizard.open = false;
}
function syncWizardDefaults(): void {
  const provider = wizardProviderOptions.value[0] ?? null;
  if (!provider || !wizardProviderOptions.value.some((item) => item.key === wizard.provider_key)) {
    wizard.provider_key = provider?.key || "";
  }
  const selectedProvider = wizardProvider.value;
  wizard.api_key_ref = String(
    selectedProvider?.api_key_ref ||
      (selectedProvider?.provider_type === "ollama" || selectedProvider?.provider_type === "null"
        ? ""
        : `env:${String(selectedProvider?.key || "AI").toUpperCase()}_API_KEY`),
  );
  const model = wizardModelOptions.value[0] ?? null;
  if (!model || !wizardModelOptions.value.some((item) => item.id === wizard.model_id)) {
    wizard.model_id = Number(model?.id ?? 0);
  }
  if (wizard.step === "objective") testPrompt.value = wizardObjective.value.prompt;
}
function wizardNext(): void {
  const index = wizardStepIndex.value;
  if (index < wizardStepOrder.length - 1) wizard.step = wizardStepOrder[index + 1];
  syncWizardDefaults();
}
function wizardBack(): void {
  const index = wizardStepIndex.value;
  if (index > 0) wizard.step = wizardStepOrder[index - 1];
}
function wizardProviderKindLabel(provider: AiProvider | null): string {
  if (!provider) return "—";
  const labels: Record<string, string> = {
    null: "null provider",
    openai_compatible: provider.key === "mistral" ? "Mistral compatible" : "OpenAI compatible",
    ollama: "Ollama local",
    local: "local / privé",
    custom_http: "autre compatible",
    infomaniak: "autre compatible",
  };
  return labels[provider.provider_type] || provider.provider_type;
}
async function wizardSaveProvider(): Promise<void> {
  const provider = wizardProvider.value;
  if (!provider) return;
  saving.value = true;
  error.value = "";
  success.value = "";
  try {
    await adminApi.post("/ai/providers", {
      provider: {
        key: provider.key,
        name: provider.name,
        type_key: provider.type_key || wizardUsageKey.value,
        provider_type: provider.provider_type,
        base_url: provider.base_url || "",
        api_key_ref: cleanedApiKeyRef(wizard.api_key_ref),
        enabled: true,
        is_default: provider.is_default,
      },
    });
    success.value = "Fournisseur activé sans exposition de secret.";
    await load();
    wizardNext();
  } catch (err) {
    error.value = apiErrorMessage(err, "Activation du fournisseur impossible.");
  } finally {
    saving.value = false;
  }
}
async function wizardSaveModel(): Promise<void> {
  const model = wizardModel.value;
  if (!model) return;
  saving.value = true;
  error.value = "";
  success.value = "";
  try {
    await adminApi.post("/ai/models", {
      model: {
        provider_key: model.provider_key,
        usage_keys: Array.from(new Set([...modelUsageKeys(model), wizardUsageKey.value])),
        key: model.key,
        name: model.name,
        model_type: model.model_type,
        context_window: model.context_window,
        input_price: model.input_price,
        output_price: model.output_price,
        currency: model.currency,
        price_unit: model.price_unit || "1m_tokens",
        api_key_ref: cleanedApiKeyRef(String(model.api_key_ref || "")),
        max_monthly_budget: model.max_monthly_budget || { amount: 0, currency: model.currency || "CHF", mode: "block" },
        enabled: true,
      },
    });
    success.value = "Modèle activé pour l’usage choisi.";
    await load();
    wizardNext();
  } catch (err) {
    error.value = apiErrorMessage(err, "Activation du modèle impossible.");
  } finally {
    saving.value = false;
  }
}
async function wizardRunTest(): Promise<void> {
  const usageKey = wizardUsageKey.value;
  testUsageKey.value = usageKey;
  testPrompt.value = wizardObjective.value.prompt;
  testing.value = true;
  error.value = "";
  success.value = "";
  try {
    const response = await adminApi.post<{ result: AiTestDiagnostic }>(
      "/ai/providers/test",
      {
        test_kind: "usage_configured",
        usage_key: usageKey,
        action_key: wizardObjective.value.action_key,
        prompt: wizardObjective.value.prompt,
        variables: diagnosticVariables(),
      },
    );
    wizard.test_result = response.data.result ?? null;
    applyTestResult(wizard.test_result, "Test de configuration guidée terminé. Vérifiez le résultat ci-dessous avant de continuer.");
    await load();
  } catch (err) {
    error.value = apiErrorMessage(err, "Test de configuration guidée impossible.");
  } finally {
    testing.value = false;
  }
}
async function wizardActivateSite(): Promise<void> {
  const model = wizardModel.value;
  if (!model) return;
  saving.value = true;
  error.value = "";
  success.value = "";
  try {
    await adminApi.post("/ai/site-settings", {
      site_setting: {
        site_id: Number(currentSite.value?.id ?? context.siteId ?? 0),
        usage_key: wizardUsageKey.value,
        mode: "enabled",
        model_id: model.id,
        translation_enabled: wizard.objective_key === "translation",
        seo_enabled: wizard.objective_key === "seo",
        notes: `Configuration guidée : ${wizardObjective.value.label}`,
      },
    });
    success.value = "IA activée pour le site courant.";
    wizard.completed = true;
    await load();
    wizardNext();
  } catch (err) {
    error.value = apiErrorMessage(err, "Activation pour le site impossible.");
  } finally {
    saving.value = false;
  }
}

async function load(): Promise<void> {
  loading.value = true;
  error.value = "";
  try {
    const [statusResponse, settingsResponse] = await Promise.all([
      adminApi.get<{ status: AiStatus; permissions?: Record<string, boolean> }>(
        "/ai/status",
      ),
      adminApi.get<{
        settings: Record<string, AiSetting>;
        blueprints?: AiBlueprint[];
        provider_types: AiProviderType[];
        providers: AiProvider[];
        models: AiModel[];
        site_usage_settings?: AiSiteUsageSetting[];
        site_settings?: AiSiteUsageSetting[];
      }>("/ai/settings"),
    ]);
    status.value = statusResponse.data.status ?? {};
    settings.value = settingsResponse.data.settings ?? {};
    blueprints.value = settingsResponse.data.blueprints ?? [];
    providerTypes.value = settingsResponse.data.provider_types ?? [];
    providers.value = settingsResponse.data.providers ?? [];
    models.value = settingsResponse.data.models ?? [];
    siteUsageSettings.value =
      settingsResponse.data.site_usage_settings ??
      settingsResponse.data.site_settings ??
      [];
    if (
      !enabledUsages.value.find((item) => item.key === selectedUsageKey.value)
    )
      selectedUsageKey.value = enabledUsages.value[0]?.key || "editorial";
    if (!enabledUsages.value.find((item) => item.key === testUsageKey.value))
      testUsageKey.value =
        activeSiteUsages.value[0]?.usage_key ||
        enabledUsages.value[0]?.key ||
        "editorial";
    syncUsageForm(selectedUsageKey.value);
    syncWizardDefaults();
    if (canReadLogs.value) {
      const [usageResponse, budgetResponse] = await Promise.all([
        adminApi.get<{ usage: AiUsage[] }>("/ai/usage", { limit: 25 }),
        adminApi.get<{ budget: AiBudgetSummary }>("/ai/budget"),
      ]);
      usage.value = usageResponse.data.usage ?? [];
      budgetSummary.value = budgetResponse.data.budget ?? null;
      const currentList = usageEventsForActiveTestTab.value;
      if (selectedUsageEventId.value && !currentList.some((event) => event.id === selectedUsageEventId.value)) {
        selectedUsageEventId.value = null;
      }
      if (!selectedUsageEventId.value) {
        selectedUsageEventId.value = currentList[0]?.id ?? null;
      }
    }
    if (canReadSuggestions.value) {
      const suggestionParams: Record<string, string | number> = { limit: 50 };
      if (suggestionStatusFilter.value !== "all")
        suggestionParams.status = suggestionStatusFilter.value;
      const suggestionResponse = await adminApi.get<{
        suggestions: AiSuggestion[];
      }>("/ai/suggestions", suggestionParams);
      suggestions.value = suggestionResponse.data.suggestions ?? [];
      if (!selectedSuggestionId.value && suggestions.value.length)
        selectedSuggestionId.value = suggestions.value[0].id;
    }
  } catch (err) {
    error.value = apiErrorMessage(err, "Configuration IA indisponible.");
  } finally {
    loading.value = false;
  }
}

async function saveSettings(): Promise<void> {
  saving.value = true;
  error.value = "";
  success.value = "";
  try {
    const payload: Record<string, unknown> = {};
    for (const [key, setting] of Object.entries(settings.value))
      payload[key] = setting.value;
    await adminApi.post("/ai/settings", { settings: payload });
    success.value = "Paramètres IA enregistrés.";
    await load();
  } catch (err) {
    error.value = apiErrorMessage(err, "Enregistrement impossible.");
  } finally {
    saving.value = false;
  }
}
async function saveUsage(): Promise<void> {
  saving.value = true;
  error.value = "";
  success.value = "";
  try {
    const payload = {
      ...usageForm,
      site_id: Number(currentSite.value?.id ?? context.siteId ?? 0),
      model_id:
        usageForm.mode === "enabled" ? Number(usageForm.model_id || 0) : 0,
      translation_enabled:
        usageForm.mode === "enabled" && usageForm.usage_key === "editorial"
          ? usageForm.translation_enabled
          : false,
      seo_enabled:
        usageForm.mode === "enabled" && usageForm.usage_key === "editorial"
          ? usageForm.seo_enabled
          : false,
    };
    const response = await adminApi.post<{
      site_usage_settings?: AiSiteUsageSetting[];
      site_settings?: AiSiteUsageSetting[];
    }>("/ai/site-settings", { site_setting: payload });
    siteUsageSettings.value =
      response.data.site_usage_settings ??
      response.data.site_settings ??
      siteUsageSettings.value;
    success.value = "Usage IA enregistré pour le site courant.";
    await load();
    selectedUsageEventId.value = nonStreamingUsageEvents.value[0]?.id ?? selectedUsageEventId.value;
  } catch (err) {
    error.value = apiErrorMessage(
      err,
      "Configuration IA impossible à enregistrer.",
    );
  } finally {
    saving.value = false;
  }
}
async function saveType(): Promise<void> {
  saving.value = true;
  error.value = "";
  success.value = "";
  try {
    const response = await adminApi.post<{ provider_types: AiProviderType[] }>(
      "/ai/provider-types",
      { provider_type: { ...typeForm } },
    );
    providerTypes.value = response.data.provider_types ?? providerTypes.value;
    success.value = "Type d’usage enregistré.";
    closeCatalogModal();
    resetTypeForm();
    await load();
  } catch (err) {
    error.value = apiErrorMessage(err, "Type impossible à enregistrer.");
  } finally {
    saving.value = false;
  }
}
async function deleteType(type: AiProviderType): Promise<void> {
  if (type.is_system || !window.confirm(`Supprimer le type ${type.name} ?`))
    return;
  saving.value = true;
  error.value = "";
  success.value = "";
  try {
    await adminApi.delete(`/ai/provider-types/${encodeURIComponent(type.key)}`);
    success.value = "Type supprimé.";
    await load();
  } catch (err) {
    error.value = apiErrorMessage(err, "Suppression du type impossible.");
  } finally {
    saving.value = false;
  }
}
async function saveProvider(): Promise<void> {
  saving.value = true;
  error.value = "";
  success.value = "";
  try {
    await adminApi.post("/ai/providers", { provider: { ...providerForm } });
    success.value = "Fournisseur enregistré.";
    closeCatalogModal();
    resetProviderForm();
    await load();
  } catch (err) {
    error.value = apiErrorMessage(err, "Fournisseur impossible à enregistrer.");
  } finally {
    saving.value = false;
  }
}
async function deleteProvider(provider: AiProvider): Promise<void> {
  if (
    !canDeleteProvider(provider) ||
    !window.confirm(
      `Supprimer le fournisseur ${provider.name} et ses modèles ?`,
    )
  )
    return;
  saving.value = true;
  error.value = "";
  success.value = "";
  try {
    await adminApi.delete(`/ai/providers/${encodeURIComponent(provider.key)}`);
    success.value = "Fournisseur supprimé.";
    await load();
  } catch (err) {
    error.value = apiErrorMessage(err, "Suppression impossible.");
  } finally {
    saving.value = false;
  }
}
async function saveModel(): Promise<void> {
  saving.value = true;
  error.value = "";
  success.value = "";
  try {
    const inferredModelType = technicalTypeForUsages(modelForm.usage_keys);
    await adminApi.post("/ai/models", {
      model: {
        provider_key: modelForm.provider_key,
        usage_keys: modelForm.usage_keys,
        key: modelForm.key,
        name: modelForm.name,
        model_type: inferredModelType,
        context_window: modelForm.context_window,
        input_price: modelForm.input_price,
        output_price: modelForm.output_price,
        currency: modelForm.currency,
        api_key_ref: cleanedApiKeyRef(modelForm.api_key_ref),
        price_unit: modelForm.price_unit,
        max_monthly_budget: {
          amount: modelForm.budget_amount,
          currency: modelForm.currency,
          mode: modelForm.budget_mode,
          warning_threshold: 0.8,
        },
        enabled: modelForm.enabled,
      },
    });
    success.value = "Modèle enregistré.";
    closeCatalogModal();
    resetModelForm(modelForm.provider_key);
    await load();
  } catch (err) {
    error.value = apiErrorMessage(err, "Modèle impossible à enregistrer.");
  } finally {
    saving.value = false;
  }
}
async function deleteModel(model: AiModel): Promise<void> {
  if (
    !canDeleteModel(model) ||
    !window.confirm(`Supprimer le modèle ${model.name} ?`)
  )
    return;
  saving.value = true;
  error.value = "";
  success.value = "";
  try {
    await adminApi.delete(
      `/ai/models/${encodeURIComponent(model.provider_key)}/${encodeURIComponent(model.key)}`,
    );
    success.value = "Modèle supprimé.";
    await load();
  } catch (err) {
    error.value = apiErrorMessage(err, "Suppression impossible.");
  } finally {
    saving.value = false;
  }
}
async function deleteCurrentType(): Promise<void> {
  const type = providerTypes.value.find((item) => item.key === typeForm.key);
  if (!type) return;
  await deleteType(type);
  closeCatalogModal();
}
async function deleteCurrentProvider(): Promise<void> {
  const provider = currentProviderForDelete.value;
  if (!provider) return;
  await deleteProvider(provider);
  closeCatalogModal();
}
async function deleteCurrentModel(): Promise<void> {
  const model = currentModelForDelete.value;
  if (!model) return;
  await deleteModel(model);
  closeCatalogModal();
}
async function testProvider(kind: AiTestKind = (testKind.value === "streaming" ? "real_prompt" : testKind.value)): Promise<void> {
  testKind.value = kind;
  testing.value = true;
  error.value = "";
  success.value = "";
  try {
    const usageKey =
      selectedTestUsage.value?.key ||
      testUsageKey.value ||
      enabledUsages.value[0]?.key ||
      "editorial";
    const response = await adminApi.post<{ result: AiTestDiagnostic }>(
      "/ai/providers/test",
      {
        test_kind: kind,
        usage_key: usageKey,
        action_key: actionKeyForTest(usageKey),
        prompt: testPrompt.value,
        variables: diagnosticVariables(),
      },
    );
    applyTestResult(
      response.data.result ?? null,
      `${testKindLabel(kind)} terminé pour ${typeName.value(usageKey)}.`,
    );
    await load();
  } catch (err) {
    error.value = apiErrorMessage(
      err,
      "Diagnostic impossible. Vérifiez l’usage sélectionné, son modèle actif et la variable d’environnement de clé API.",
    );
  } finally {
    testing.value = false;
  }
}
function startSelectedAiTest(): void {
  if (testKind.value === "streaming") {
    startStreamingTest();
    return;
  }
  void testProvider(testKind.value as AiTestKind);
}

function startStreamingTest(): void {
  closeStream();
  error.value = "";
  success.value = "";
  streamText.value = "";
  streamError.value = "";
  streamResult.value = null;
  testResult.value = null;
  streaming.value = true;
  streamState.value = "connecting";
  const usageKey =
    selectedTestUsage.value?.key ||
    testUsageKey.value ||
    enabledUsages.value[0]?.key ||
    "editorial";
  const url = adminApi.href("/ai/providers/test/stream", {
    usage_key: usageKey,
    prompt: testPrompt.value,
    contract_version: "admin-api-v1",
  });
  const source = new EventSource(url, { withCredentials: true });
  streamSource.value = source;
  let terminalEventHandled = false;
  const refreshStreamingHistory = async (): Promise<void> => {
    await load();
    selectedUsageEventId.value = streamingUsageEvents.value[0]?.id ?? null;
  };
  source.addEventListener("start", (event) => {
    streamState.value = streamText.value ? "generating" : "connecting";
    try {
      const payload = JSON.parse(
        (event as MessageEvent).data || "{}",
      ) as AiTestDiagnostic;
      streamResult.value = { ...(streamResult.value ?? {}), ...payload };
    } catch {
      /* ignore malformed start event */
    }
  });
  source.addEventListener("token", (event) => {
    streamState.value = "generating";
    try {
      const payload = JSON.parse((event as MessageEvent).data || "{}") as {
        text?: string;
      };
      streamText.value += String(payload.text || "");
    } catch {
      /* ignore malformed token */
    }
  });
  source.addEventListener("usage", (event) => {
    try {
      const payload = JSON.parse(
        (event as MessageEvent).data || "{}",
      ) as AiTestDiagnostic;
      streamResult.value = { ...(streamResult.value ?? {}), ...payload };
    } catch {
      /* ignore malformed usage */
    }
  });
  source.addEventListener("done", async (event) => {
    try {
      const payload = JSON.parse(
        (event as MessageEvent).data || "{}",
      ) as AiTestDiagnostic;
      const result = {
        ...payload,
        content: payload.content || streamText.value,
        response_preview:
          payload.response_preview || streamText.value.slice(0, 240),
        success: true,
        ok: true,
      };
      streamResult.value = result;
      success.value = `Streaming ${typeName.value(usageKey)} terminé.`;
      terminalEventHandled = true;
      await refreshStreamingHistory();
    } catch {
      /* ignore malformed done */
    }
    streamState.value = "done";
    closeStream();
  });
  source.addEventListener("error", async (event) => {
    let message = "Streaming indisponible. Utilisez le test non-streaming.";
    try {
      const payload = JSON.parse(
        (event as MessageEvent).data || "{}",
      ) as AiTestDiagnostic;
      streamResult.value = { ...payload, test_kind: "stream_test", streaming: true } as AiTestDiagnostic;
      message = String(
        payload.human_message || payload.error_message || message,
      );
    } catch {
      /* EventSource emits a native error without JSON when the connection breaks. */
    }
    terminalEventHandled = true;
    streamError.value = message;
    streamState.value = "error";
    closeStream();
    await refreshStreamingHistory();
  });
  source.onerror = async () => {
    if (terminalEventHandled || streamState.value === "done") return;
    terminalEventHandled = true;
    streamError.value =
      "Connexion SSE interrompue. Relancez en mode non-streaming si l’hébergement bloque le streaming.";
    streamState.value = "error";
    closeStream();
    await refreshStreamingHistory();
  };
}
async function testEditorialGeneration(): Promise<void> {
  testing.value = true;
  error.value = "";
  success.value = "";
  try {
    const usageKey =
      selectedTestUsage.value?.key ||
      testUsageKey.value ||
      enabledUsages.value[0]?.key ||
      "editorial";
    const response = await adminApi.post<{ result: AiTestDiagnostic }>(
      "/ai/editorial/test-generation",
      {
        usage_key: usageKey,
        action_key: actionKeyForTest(usageKey),
        prompt: testPrompt.value,
        variables: diagnosticVariables(),
      },
    );
    applyTestResult(
      response.data.result ?? null,
      `Test de génération ${typeName.value(usageKey)} réussi.`,
    );
    await load();
  } catch (err) {
    error.value = apiErrorMessage(
      err,
      "Génération impossible. Vérifiez le modèle de l’usage sélectionné et la configuration du fournisseur.",
    );
  } finally {
    testing.value = false;
  }
}
async function proposeLatestTestAsSuggestion(): Promise<void> {
  if (!testResult.value) return;
  saving.value = true;
  error.value = "";
  success.value = "";
  try {
    const response = await adminApi.post<{ suggestion: AiSuggestion }>(
      "/ai/suggestions/propose",
      {
        target_type: "ai_test",
        target_id: String(
          testResult.value.usage_key || testUsageKey.value || "test",
        ),
        suggestion_type: String(
          testResult.value.action_key || "ai.test_result",
        ),
        source_field: "prompt",
        target_field: "preview",
        preview_text: String(
          testResult.value.response_preview ||
            testResult.value.content ||
            testResult.value.user_message ||
            "",
        ).slice(0, 500),
        reason: "Suggestion créée depuis le dernier test IA.",
        suggestion: { result: testTechnicalDetails(testResult.value) },
      },
    );
    suggestions.value = [
      response.data.suggestion,
      ...suggestions.value.filter(
        (item) => item.id !== response.data.suggestion.id,
      ),
    ];
    selectedSuggestionId.value = response.data.suggestion.id;
    activeOperationsTab.value = "tests";
    success.value = "Suggestion proposée depuis le dernier test IA.";
  } catch (err) {
    error.value = apiErrorMessage(err, "Création de suggestion impossible.");
  } finally {
    saving.value = false;
  }
}
async function updateSuggestionStatus(
  suggestion: AiSuggestion,
  action: "propose" | "accept" | "reject" | "apply" | "expire",
): Promise<void> {
  saving.value = true;
  error.value = "";
  success.value = "";
  try {
    const payload: Record<string, unknown> = {};
    if (action === "apply")
      payload.applied_action_run_id = suggestionApplyRunId.value;
    const response = await adminApi.post<{ suggestion: AiSuggestion }>(
      `/ai/suggestions/${suggestion.id}/${action}`,
      payload,
    );
    const updated = response.data.suggestion;
    suggestions.value = suggestions.value.map((item) =>
      item.id === updated.id ? updated : item,
    );
    selectedSuggestionId.value = updated.id;
    success.value = `Suggestion ${suggestionStatusLabel(String(updated.status))}.`;
  } catch (err) {
    error.value = apiErrorMessage(err, "Transition de suggestion impossible.");
  } finally {
    saving.value = false;
  }
}

async function clearUsageEvents(): Promise<void> {
  const eventsToHide = usageEventsForActiveTestTab.value;
  hideUsageEventsFromDisplay(eventsToHide);
  testResult.value = null;
  streamResult.value = null;
  streamText.value = "";
  streamError.value = "";
  selectedUsageEventId.value = null;
  success.value = "Tests retirés de l’affichage. L’historique des dépenses et les tokens restent conservés.";
}

watch(
  () => wizard.objective_key,
  () => {
    wizard.test_result = null;
    syncWizardDefaults();
  },
);
watch(
  () => wizard.provider_key,
  () => syncWizardDefaults(),
);

watch(
  () => context.siteId,
  () => {
    // Le site sélectionné dans le header est la source de vérité.
    // On reste dans le module courant et on recharge uniquement la configuration du site.
    void load();
  },
);
onMounted(() => {
  loadHiddenUsageEventIds();
  void load();
});
onBeforeUnmount(closeStream);
</script>

<template>
  <section class="ai-console ai-console--wide" aria-label="Configuration IA">
    <ApiFeedback :error="error" :success="success" />

    <div class="ai-status-grid ai-status-grid--compact" aria-label="État IA">
      <article
        v-for="card in statusCards"
        :key="card.label"
        class="card ai-state-card"
        :class="`ai-state-card--${card.status}`"
      >
        <span class="eyebrow">{{ card.label }}</span
        ><strong>{{ card.value }}</strong>
      </article>
    </div>

    <div class="ai-console-layout ai-console-layout--full">
      <aside class="card ai-side-nav" aria-label="Navigation IA">
        <button
          v-for="section in sections"
          :key="section.key"
          type="button"
          class="ai-side-link"
          :class="{ active: activeSection === section.key }"
          @click="activeSection = section.key"
        >
          <span>{{ section.label }}</span>
        </button>
      </aside>

      <main class="ai-config-main">
        <article
          v-if="activeSection === 'site'"
          class="card ai-panel-card ai-panel-card--compact"
        >
          <header class="ai-panel-title"><h2>Configuration</h2></header>
          <section class="ai-wizard-card" aria-label="Assistant de configuration guidée">
            <div class="ai-wizard-intro">
              <div>
                <span class="eyebrow">Configuration guidée</span>
                <strong>Activer l’IA sans manipuler toute la structure technique</strong>
              </div>
              <button class="btn primary" type="button" :disabled="!canManage" @click="openWizard()">
                Lancer l’assistant
              </button>
            </div>
            <div class="ai-wizard-summary-grid">
              <span><strong>{{ wizardActiveUsages.length }}</strong> usage(s) activé(s)</span>
              <span><strong>{{ wizardMissingUsages.length }}</strong> usage(s) non configuré(s)</span>
              <span><strong>{{ wizardProviderOptions.length }}</strong> fournisseur(s) compatible(s)</span>
            </div>
          </section>

          <div v-if="wizard.open" class="ai-wizard-overlay" role="dialog" aria-modal="true" aria-label="Assistant IA guidé">
            <div class="ai-wizard-modal">
              <header class="ai-wizard-header">
                <div>
                  <span class="eyebrow">Assistant IA guidé</span>
                  <h3>{{ wizardObjective.label }}</h3>
                </div>
                <button class="btn ghost" type="button" @click="closeWizard">Fermer</button>
              </header>
              <ol class="ai-wizard-steps">
                <li v-for="(step, index) in wizardStepOrder" :key="step" :class="{ active: wizard.step === step, done: index < wizardStepIndex }">
                  {{ index + 1 }}
                </li>
              </ol>

              <section v-if="wizard.step === 'objective'" class="ai-wizard-step-panel">
                <h4>1. Choisir l’objectif</h4>
                <div class="ai-wizard-option-grid">
                  <button v-for="objective in wizardObjectives" :key="objective.key" type="button" class="ai-wizard-option" :class="{ active: wizard.objective_key === objective.key }" @click="wizard.objective_key = objective.key">
                    <strong>{{ objective.label }}</strong>
                    <small>{{ objective.description }}</small>
                  </button>
                </div>
                <p class="empty-state mt-3"><strong>Configuration minimale recommandée</strong><span>{{ wizardObjective.label }} · fournisseur {{ wizardObjective.recommended_provider_type }} · modèle compatible avec l’usage {{ typeName(wizardUsageKey) }}.</span></p>
              </section>

              <section v-else-if="wizard.step === 'provider'" class="ai-wizard-step-panel">
                <h4>2. Choisir le fournisseur compatible</h4>
                <div class="ai-wizard-option-grid">
                  <button v-for="provider in wizardProviderOptions" :key="provider.key" type="button" class="ai-wizard-option" :class="{ active: wizard.provider_key === provider.key }" @click="wizard.provider_key = provider.key">
                    <strong>{{ provider.name }}</strong>
                    <small>{{ wizardProviderKindLabel(provider) }} · {{ provider.enabled ? 'actif' : 'désactivé' }}</small>
                  </button>
                </div>
                <p v-if="!wizardProviderOptions.length" class="empty-state"><strong>Aucun fournisseur compatible</strong><span>Ajoutez un fournisseur dans le catalogue avancé.</span></p>
              </section>

              <section v-else-if="wizard.step === 'secret'" class="ai-wizard-step-panel">
                <h4>3. Configurer la clé ou la variable d’environnement</h4>
                <label class="ai-field-with-hint"><span class="schema-label-with-hint"><span>Référence de clé</span></span><input v-model="wizard.api_key_ref" placeholder="env:OPENAI_API_KEY" :disabled="!canManage" /></label>
                <p class="muted small">Aucun secret n’est affiché ni demandé en clair. Indiquez de préférence une référence de variable d’environnement.</p>
                <button class="btn primary" type="button" :disabled="saving || !canManage || !wizardProvider" @click="wizardSaveProvider">Enregistrer le fournisseur</button>
              </section>

              <section v-else-if="wizard.step === 'model'" class="ai-wizard-step-panel">
                <h4>4. Sélectionner le modèle</h4>
                <div class="ai-wizard-option-grid">
                  <button v-for="model in wizardModelOptions" :key="model.id" type="button" class="ai-wizard-option" :class="{ active: wizard.model_id === model.id }" @click="wizard.model_id = model.id">
                    <strong>{{ model.name }}</strong>
                    <small>{{ model.key }} · {{ modelUsageLabel(model) }} · {{ model.enabled ? 'actif' : 'à activer' }}</small>
                  </button>
                </div>
                <p v-if="!wizardModelOptions.length" class="empty-state"><strong>Aucun modèle compatible</strong><span>Créez ou rattachez un modèle à cet usage dans le catalogue avancé.</span></p>
                <button class="btn primary" type="button" :disabled="saving || !canManage || !wizardModel" @click="wizardSaveModel">Activer le modèle sélectionné</button>
              </section>

              <section v-else-if="wizard.step === 'test'" class="ai-wizard-step-panel">
                <h4>5. Tester la configuration</h4>
                <p class="muted">Usage testé : <strong>{{ typeName(wizardUsageKey) }}</strong> · modèle : <strong>{{ wizardModel?.name || '—' }}</strong></p>
                <label class="ai-field-with-hint"><span class="schema-label-with-hint"><span>Prompt envoyé</span></span><textarea rows="3" v-model="testPrompt" :disabled="testing || !canManage"></textarea></label>
                <button class="btn primary" type="button" :disabled="testing || !canManage || !wizardModel" @click="wizardRunTest">Tester la configuration</button>
                <p v-if="wizard.test_result" class="muted small mt-2">Le résultat reste affiché ici. Continuez uniquement après avoir vérifié le statut, le modèle et la réponse.</p>
                <AiDiagnosticResultCard v-if="wizard.test_result" :result="wizard.test_result" :site-name="currentSite?.name || ''" />
              </section>

              <section v-else-if="wizard.step === 'activate'" class="ai-wizard-step-panel">
                <h4>6. Activer pour le site courant</h4>
                <p class="empty-state"><strong>{{ currentSite?.name || 'Site sélectionné' }}</strong><span>{{ wizardObjective.label }} utilisera {{ wizardModel?.name || 'le modèle sélectionné' }}. Les options SEO/traduction sont activées uniquement pour l’objectif correspondant.</span></p>
                <button class="btn primary" type="button" :disabled="saving || !canManage || !wizardModel" @click="wizardActivateSite">Activer pour ce site</button>
              </section>

              <section v-else class="ai-wizard-step-panel">
                <h4>7. Résumé final</h4>
                <div class="ai-test-meta">
                  <span><strong>Objectif</strong>{{ wizardObjective.label }}</span>
                  <span><strong>Fournisseur</strong>{{ wizardProvider?.name || '—' }}</span>
                  <span><strong>Modèle</strong>{{ wizardModel?.name || '—' }}</span>
                  <span><strong>Usage activé</strong>{{ typeName(wizardUsageKey) }}</span>
                </div>
                <div class="ai-wizard-summary-grid mt-3">
                  <span><strong>{{ wizardActiveUsages.length }}</strong> usage(s) activé(s)</span>
                  <span><strong>{{ wizardMissingUsages.length }}</strong> usage(s) non configuré(s)</span>
                </div>
              </section>

              <footer class="ai-wizard-footer">
                <button class="btn" type="button" :disabled="wizardStepIndex === 0" @click="wizardBack">Retour</button>
                <button v-if="!['secret','model','test','activate','summary'].includes(wizard.step)" class="btn primary" type="button" @click="wizardNext">Continuer</button>
                <button v-if="wizard.step === 'test'" class="btn primary" type="button" :disabled="!wizard.test_result || !testSucceeded(wizard.test_result)" @click="wizardNext">Continuer après vérification</button>
                <button v-if="wizard.step === 'summary'" class="btn primary" type="button" @click="closeWizard">Terminer</button>
              </footer>
            </div>
          </div>

          <div class="ai-site-choice-layout">
            <div
              class="ai-provider-list ai-provider-list--flush ai-provider-list--sites"
              aria-label="Types d’usage IA"
            >
              <button
                v-for="setting in effectiveUsageSettings"
                :key="setting.usage_key"
                class="ai-provider-row"
                type="button"
                :class="{ active: selectedUsageKey === setting.usage_key }"
                @click="syncUsageForm(setting.usage_key)"
              >
                <span
                  ><strong>{{ typeName(setting.usage_key) }}</strong
                  ><small>{{
                    usageModel(setting)?.name ||
                    (setting.mode === "inherit"
                      ? "Hérite du site principal"
                      : "Aucun modèle")
                  }}</small></span
                >
                <span class="ai-row-statuses">
                  <StatusBadge :status="usageStatus(setting)" />
                  <StatusBadge
                    v-if="usageAvailabilityStatus(setting)"
                    :status="usageAvailabilityStatus(setting)"
                  />
                </span>
              </button>
            </div>

            <form class="ai-scope-form" @submit.prevent="saveUsage">
              <div
                class="ai-choice-list"
                role="radiogroup"
                aria-label="Règle d'application"
              >
                <button
                  v-for="option in ruleOptions"
                  :key="option.mode"
                  class="ai-choice-card"
                  type="button"
                  :class="{ active: usageForm.mode === option.mode }"
                  :disabled="!canManage"
                  @click="usageForm.mode = option.mode"
                >
                  <span class="ai-choice-dot"></span>
                  <span
                    ><strong>{{ option.label }}</strong
                    ><small>{{ option.description }}</small></span
                  >
                </button>
              </div>

              <div
                v-if="usageForm.usage_key === 'editorial'"
                class="ai-form-grid ai-form-grid--flush mt-3"
              >
                <label class="ai-checkbox-card"
                  ><input
                    type="checkbox"
                    v-model="usageForm.translation_enabled"
                    :disabled="!canManage || usageForm.mode === 'disabled'"
                  />
                  Traductions IA</label
                >
                <label class="ai-checkbox-card"
                  ><input
                    type="checkbox"
                    v-model="usageForm.seo_enabled"
                    :disabled="!canManage || usageForm.mode === 'disabled'"
                  />
                  SEO IA</label
                >
              </div>

              <div v-if="usageForm.mode === 'inherit'" class="empty-state mt-3">
                <strong>Héritage du site principal</strong>
                <span
                  >Le site courant utilisera le choix défini pour ce même usage
                  sur le site principal.</span
                >
              </div>

              <p
                v-else-if="modelsForUsage(usageForm.usage_key).length === 0"
                class="empty-state mt-3"
              >
                <strong>Aucun modèle pour cet usage</strong
                ><span
                  >Ajoutez ou activez un modèle dans le catalogue des
                  modèles.</span
                >
              </p>

              <div v-else class="ai-model-grid ai-model-grid--flush mt-3">
                <button
                  v-for="model in modelsForUsage(usageForm.usage_key)"
                  :key="model.id"
                  class="ai-model-card ai-model-card--button ai-model-card--with-status"
                  type="button"
                  :class="{
                    active: usageForm.model_id === model.id,
                    'is-unavailable': !modelIsSelectable(model),
                  }"
                  :disabled="
                    !canManage ||
                    usageForm.mode !== 'enabled' ||
                    !modelIsSelectable(model)
                  "
                  @click="usageForm.model_id = model.id"
                >
                  <StatusBadge
                    class="ai-card-status"
                    :status="modelStatus(model)"
                  />
                  <div>
                    <strong>{{ model.name }}</strong
                    ><small
                      >{{ providerName(model.provider_key) }} ·
                      {{ modelUsageLabel(model) }}</small
                    >
                  </div>
                  <p class="muted small">
                    {{ model.key }} · budget
                    {{
                      money(
                        Number(model.max_monthly_budget?.amount ?? 0),
                        modelBudgetCurrency(model),
                      )
                    }}
                  </p>
                </button>
              </div>

              <button
                class="btn primary mt-3"
                type="submit"
                :disabled="saving || !canManage"
              >
                Enregistrer
              </button>
            </form>
          </div>
        </article>

        <div v-else-if="activeSection === 'catalog'" class="ai-panel-shell">
          <div class="ai-tabs-with-action">
            <nav
              class="editor-tabs ai-section-tabs"
              role="tablist"
              aria-label="Catalogue IA"
            >
              <button
                v-for="tab in catalogTabs"
                :key="tab.key"
                type="button"
                :class="[
                  'editor-tab',
                  { active: activeCatalogTab === tab.key },
                ]"
                @click="activeCatalogTab = tab.key"
              >
                {{ tab.label }}
              </button>
            </nav>
            <button
              class="btn primary ai-tabs-action"
              type="button"
              :disabled="!canManage"
              @click="openNewCatalogItem"
            >
              {{ catalogNewButtonLabel }}
            </button>
          </div>

          <article class="card ai-panel-card ai-panel-card--compact">
            <section v-if="activeCatalogTab === 'types'" class="ai-tab-panel">
              <div class="ai-provider-list ai-provider-list--flush">
                <article
                  v-for="type in sortedProviderTypes"
                  :key="type.key"
                  class="ai-provider-row ai-provider-row--actions"
                >
                  <button type="button" @click="openEditType(type)">
                    <span
                      ><strong>{{ type.name }}</strong
                      ><small
                        >{{ type.key }} · {{ type.description }}</small
                      ></span
                    ><StatusBadge :status="type.enabled ? 'ok' : 'désactivé'" />
                  </button>
                </article>
              </div>
            </section>

            <section
              v-else-if="activeCatalogTab === 'providers'"
              class="ai-tab-panel"
            >
              <div class="ai-panel-tools ai-panel-tools--between">
                <div
                  class="ai-choice-list ai-choice-list--inline"
                  role="tablist"
                  aria-label="Filtrer par usage"
                >
                  <button
                    class="ai-choice-tab"
                    type="button"
                    :class="{ active: selectedProviderUsageKey === '' }"
                    @click="selectedProviderUsageKey = ''"
                  >
                    <span class="ai-choice-dot"></span>Tous
                  </button>
                  <button
                    v-for="type in enabledUsages"
                    :key="type.key"
                    class="ai-choice-tab"
                    type="button"
                    :class="{ active: selectedProviderUsageKey === type.key }"
                    @click="selectedProviderUsageKey = type.key"
                  >
                    <span class="ai-choice-dot"></span>{{ type.name }}
                  </button>
                </div>
              </div>
              <p class="muted small mb-0">
                {{ frCount(filteredProviders.length, "fournisseur") }} affichés,
                dont {{ frCount(filteredActiveProviders.length, "actif") }}.
              </p>
              <div class="ai-provider-list ai-provider-list--flush mt-3">
                <article
                  v-for="provider in filteredProviders"
                  :key="provider.key"
                  class="ai-provider-row ai-provider-row--actions"
                >
                  <button type="button" @click="openEditProvider(provider)">
                    <span
                      ><strong>{{ provider.name }}</strong
                      ><small
                        >{{ typeName(provider.type_key) }} ·
                        {{ provider.provider_type }} ·
                        {{ provider.base_url || "URL non définie" }} ·
                        {{ provider.api_key_ref || "sans clé" }} ·
                        {{
                          frCount(providerModels(provider.key).length, "modèle")
                        }}</small
                      ></span
                    ><StatusBadge
                      :status="
                        provider.is_default
                          ? 'actif'
                          : provider.enabled
                            ? 'ok'
                            : 'désactivé'
                      "
                    />
                  </button>
                </article>
              </div>
            </section>

            <section v-else class="ai-tab-panel">
              <div class="ai-panel-tools ai-panel-tools--between">
                <div
                  class="ai-choice-list ai-choice-list--inline"
                  role="tablist"
                  aria-label="Filtrer par fournisseur"
                >
                  <button
                    class="ai-choice-tab"
                    type="button"
                    :class="{ active: selectedProviderKey === '' }"
                    @click="selectedProviderKey = ''"
                  >
                    <span class="ai-choice-dot"></span>Tous
                  </button>
                  <button
                    v-for="provider in sortedProviders"
                    :key="provider.key"
                    class="ai-choice-tab"
                    type="button"
                    :class="{ active: selectedProviderKey === provider.key }"
                    @click="selectedProviderKey = provider.key"
                  >
                    <span class="ai-choice-dot"></span>{{ provider.name }}
                  </button>
                </div>
              </div>
              <p class="muted small mb-0">
                {{ frCount(filteredModels.length, "modèle") }} affichés, dont
                {{ frCount(filteredActiveModels.length, "actif") }}.
              </p>
              <div class="ai-model-grid ai-model-grid--flush mt-3">
                <article
                  v-for="model in filteredModels"
                  :key="model.id"
                  class="ai-model-card ai-model-card--clickable"
                  :class="{
                    active: modelIsSelectable(model),
                    'is-unavailable': !modelIsSelectable(model),
                  }"
                  role="button"
                  tabindex="0"
                  @click="openEditModel(model)"
                  @keydown.enter.prevent="openEditModel(model)"
                  @keydown.space.prevent="openEditModel(model)"
                >
                  <div>
                    <strong>{{ model.name }}</strong
                    ><small
                      >{{ model.key }} ·
                      {{ providerName(model.provider_key) }} ·
                      {{ modelUsageLabel(model) }}</small
                    >
                  </div>
                  <p class="muted small">
                    {{
                      modelIsSelectable(model)
                        ? "Disponible pour les sites"
                        : modelStatus(model)
                    }}
                    · clé {{ model.effective_api_key_ref || "—" }} · budget
                    {{
                      money(
                        Number(model.max_monthly_budget?.amount ?? 0),
                        modelBudgetCurrency(model),
                      )
                    }}
                  </p>
                </article>
              </div>
            </section>
          </article>
        </div>

        <div v-else-if="activeSection === 'operations'" class="ai-panel-shell">
          <nav
            class="editor-tabs ai-section-tabs"
            role="tablist"
            aria-label="Tests IA"
          >
            <button
              v-for="tab in operationsTabs"
              :key="tab.key"
              type="button"
              :class="[
                'editor-tab',
                { active: activeOperationsTab === tab.key },
              ]"
              @click="activeOperationsTab = tab.key"
            >
              {{ tab.label }}
            </button>
          </nav>

          <article class="card ai-panel-card ai-panel-card--compact">
            <section
              v-if="activeOperationsTab === 'expenses'"
              class="ai-tab-panel"
            >
              <div class="ai-spend-dashboard">
                <div class="ai-spend-hero">
                  <div>
                    <span class="eyebrow">Consommation</span>
                    <strong>{{ monthlyBudgetLabel() }}</strong>
                    <small>Coûts estimés et appels IA du mois en cours.</small>
                  </div>
                  <div class="ai-spend-hero__amounts">
                    <span v-for="row in totalCostByCurrency()" :key="row.currency">
                      <strong>{{ money(row.cost, row.currency) }}</strong>
                      <small>{{ row.calls }} appel(s)</small>
                    </span>
                  </div>
                </div>

                <div class="ai-spend-kpi-grid">
                  <article class="ai-spend-kpi">
                    <span>Requêtes</span>
                    <strong>{{ budgetSummary?.calls ?? 0 }}</strong>
                    <small>{{ budgetSummary?.successful_calls ?? 0 }} succès · {{ budgetSummary?.failed_calls ?? 0 }} échec(s)</small>
                  </article>
                  <article class="ai-spend-kpi">
                    <span>Tokens entrants</span>
                    <strong>{{ integer(budgetSummary?.input_tokens) }}</strong>
                    <small>Tous modèles confondus</small>
                  </article>
                  <article class="ai-spend-kpi">
                    <span>Tokens sortants</span>
                    <strong>{{ integer(budgetSummary?.output_tokens) }}</strong>
                    <small>Tous modèles confondus</small>
                  </article>
                  <article class="ai-spend-kpi">
                    <span>Coût estimé</span>
                    <strong>{{ money(Number(budgetSummary?.total_cost || 0), budgetSummary?.currency || 'CHF') }}</strong>
                    <small>Suivi interne, aucune facture fournisseur</small>
                  </article>
                </div>

                <p v-if="!canReadLogs" class="empty-state"><strong>Dépenses non accessibles</strong>Permission <code>ai.logs.read</code> requise.</p>
                <p v-else-if="!budgetSummary?.spend_by_model?.length" class="empty-state"><strong>Aucune dépense IA</strong>Les coûts apparaîtront ici dès qu’un appel IA sera enregistré.</p>
                <div v-else class="ai-spend-model-grid">
                  <article v-for="model in budgetSummary.spend_by_model" :key="`${model.provider_key}:${model.model_key}`" class="ai-spend-model-card">
                    <header>
                      <div>
                        <span class="eyebrow">{{ modelProviderDisplayName(model.provider_key) }}</span>
                        <strong>{{ modelDisplayName(model.provider_key, model.model_key) }}</strong>
                        <small>{{ model.model_key }}</small>
                      </div>
                      <StatusBadge :status="model.failed_calls > 0 ? 'alerte' : 'ok'" />
                    </header>
                    <div class="ai-mini-chart" aria-hidden="true">
                      <span :style="{ width: `${Math.min(100, Math.max(6, model.input_tokens / Math.max(1, model.total_tokens) * 100))}%` }"></span>
                      <span :style="{ width: `${Math.min(100, Math.max(6, model.output_tokens / Math.max(1, model.total_tokens) * 100))}%` }"></span>
                    </div>
                    <div class="ai-test-meta">
                      <span><strong>Appels</strong>{{ model.calls }}</span>
                      <span><strong>Échecs</strong>{{ model.failed_calls }}</span>
                    </div>
                    <div class="ai-currency-list">
                      <span v-for="currency in model.currencies" :key="currency.currency">
                        <strong>{{ money(currency.cost, currency.currency) }}</strong>
                        <small>{{ currency.calls }} appel(s) · {{ currency.currency }}</small>
                      </span>
                    </div>
                  </article>
                </div>
              </div>
            </section>

            <section
              v-else-if="activeOperationsTab === 'tests'"
              class="ai-tab-panel"
            >
              <section class="ai-diagnostic-zone">
                <header><span class="eyebrow">Tests</span></header>
                <div class="ai-test-config">
                  <div class="block-stack">
                    <label class="schema-label-with-hint"><span>Nature du test</span></label>
                    <div class="ai-choice-list ai-choice-list--inline" role="radiogroup" aria-label="Nature du test IA">
                      <button v-for="item in aiTestKindOptions" :key="item.key" class="ai-choice-tab" type="button" :class="{ active: testKind === item.key }" :disabled="testing || streaming || !canManage" @click="testKind = item.key"><span class="ai-choice-dot"></span>{{ item.label }}</button>
                    </div>
                    <small class="muted">{{ currentTestKindHelp() }}</small>
                  </div>
                  <div class="block-stack">
                    <label class="schema-label-with-hint"><span>Usage à tester</span></label>
                    <div class="ai-choice-list ai-choice-list--inline" role="radiogroup" aria-label="Usage IA à tester">
                      <button v-for="type in enabledUsages" :key="type.key" class="ai-choice-tab" type="button" :class="{ active: testUsageKey === type.key }" :disabled="testing || streaming || !canManage" @click="testUsageKey = type.key"><span class="ai-choice-dot"></span>{{ type.name }}</button>
                    </div>
                  </div>
                  <div class="ai-test-config__model ai-test-config__model--status">
                    <span>Configuration testée</span>
                    <strong>{{ selectedTestModelLabel() }}</strong>
                    <small>{{ selectedTestModel?.provider_name || selectedTestUsageSetting?.resolved_provider_name || '—' }} · {{ selectedTestModel?.key || selectedTestUsageSetting?.model_key || '—' }} · Dernier test : {{ usageEventsForActiveTestTab[0] ? usageStatusLabel(usageEventsForActiveTestTab[0].status) + ' (' + formatDate(usageEventsForActiveTestTab[0].created_at) + ')' : 'aucun' }}</small>
                  </div>
                  <label v-if="testKind === 'real_prompt' || testKind === 'streaming'" class="ai-field-with-hint ai-test-prompt"><span class="schema-label-with-hint"><span>Prompt de test</span></span><textarea rows="3" v-model="testPrompt" :disabled="testing || streaming || !canManage" placeholder="Réécris ce court texte de manière claire : Bonjour, voici un test CMS."></textarea></label>
                  <div v-if="testKind === 'streaming'" class="ai-stream-panel" :class="streamStatusClass()">
                    <div class="ai-stream-output-block">
                      <span class="eyebrow">Réponse progressive</span>
                      <pre class="ai-stream-output">{{ streamText || 'La réponse progressive apparaîtra ici.' }}</pre>
                    </div>
                  </div>
                </div>
                <div class="ai-panel-tools ai-panel-tools--right">
                  <button class="btn primary" type="button" @click="startSelectedAiTest" :disabled="testing || streaming || !canManage || !enabledUsages.length">Démarrer le test</button>
                  <button v-if="streaming" class="btn ghost" type="button" @click="closeStream">Arrêter</button>
                </div>
              </section>

              <section class="ai-diagnostic-zone">
                <header><span class="eyebrow">Historique</span><strong>Tests réalisés</strong></header>
                <div class="ai-choice-list ai-choice-list--inline" role="tablist" aria-label="Filtres de l’historique IA">
                  <button class="ai-choice-tab" type="button" :class="{ active: activeHistoryFilter === 'all' }" @click="setHistoryFilter('all')"><span class="ai-choice-dot"></span>Tous</button>
                  <button class="ai-choice-tab" type="button" :class="{ active: activeHistoryFilter === 'success' }" @click="setHistoryFilter('success')"><span class="ai-choice-dot"></span>Succès</button>
                  <button class="ai-choice-tab" type="button" :class="{ active: activeHistoryFilter === 'failed' }" @click="setHistoryFilter('failed')"><span class="ai-choice-dot"></span>Échecs</button>
                  <button class="ai-choice-tab" type="button" :class="{ active: activeHistoryFilter === 'streaming' }" @click="setHistoryFilter('streaming')"><span class="ai-choice-dot"></span>Streaming</button>
                  <button class="ai-choice-tab" type="button" :class="{ active: activeHistoryFilter === 'content' }" @click="setHistoryFilter('content')"><span class="ai-choice-dot"></span>Avec contenu</button>
                  <button class="ai-choice-tab" type="button" :class="{ active: activeHistoryFilter === 'hidden' }" @click="setHistoryFilter('hidden')"><span class="ai-choice-dot"></span>Masqués</button>
                </div>
                <div class="ai-activity-layout ai-activity-layout--tests">
                  <div>
                    <p v-if="!canReadLogs" class="empty-state"><strong>Logs non accessibles</strong>Permission <code>ai.logs.read</code> requise.</p>
                    <p v-else-if="activeHistoryFilter === 'hidden'" class="empty-state"><strong>Historique masqué</strong></p>
                    <p v-else-if="!usage.length" class="empty-state"><strong>Aucun test</strong>Lancez un test pour créer un premier événement.</p>
                    <p v-else-if="!usageEventsForActiveTestTab.length" class="empty-state"><strong>Aucun résultat</strong>Aucun test ne correspond à ce filtre.</p>
                    <div v-else class="ai-usage-list">
                      <article v-for="event in usageEventsForActiveTestTab" :key="event.id" class="ai-usage-row ai-test-history-card" :class="[usageEventCardClass(event), { active: selectedUsageEventId === event.id }]" role="button" tabindex="0" @click="selectUsageEvent(event)" @keyup.enter="selectUsageEvent(event)">
                        <span>
                          <strong>{{ usageEventTitle(event) }} / {{ usageEventUsageLabel(event) }}</strong>
                          <small>{{ usageEventConfigurationLabel(event) }} · {{ formatDate(event.created_at) }}</small>
                        </span>
                      </article>
                    </div>
                  </div>
                  <div class="ai-result-stack">
                    <AiDiagnosticResultCard :result="displayedTestResult" :site-name="currentSite?.name || ''" />
                  </div>
                </div>
              </section>
            </section>

            <section
              v-else-if="activeOperationsTab === 'suggestions'"
              class="ai-tab-panel"
            >
              <div class="ai-panel-tools ai-panel-tools--between">
                <div
                  class="ai-choice-list ai-choice-list--inline"
                  role="tablist"
                  aria-label="Filtrer les suggestions IA"
                >
                  <button
                    v-for="status in suggestionFilterOptions"
                    :key="status"
                    class="ai-choice-tab"
                    type="button"
                    :class="{ active: suggestionStatusFilter === status }"
                    @click="setSuggestionFilter(status)"
                  >
                    <span class="ai-choice-dot"></span
                    >{{
                      status === "all"
                        ? "Toutes"
                        : suggestionStatusLabel(status)
                    }}
                  </button>
                </div>
                <button
                  class="btn"
                  type="button"
                  @click="load"
                  :disabled="loading"
                >
                  Mettre à jour
                </button>
              </div>
              <p class="muted small">
                Cycle autorisé : brouillon → proposée → acceptée/rejetée, puis
                acceptée → appliquée. Les suggestions proposées ou acceptées
                peuvent expirer. L’application directe en production reste
                désactivée.
              </p>
              <p v-if="!canReadSuggestions" class="empty-state">
                <strong>Suggestions non accessibles</strong>Permission
                <code>ai.suggestions.read</code> requise.
              </p>
              <p v-else-if="!filteredSuggestions.length" class="empty-state">
                <strong>Aucune suggestion</strong>Les suggestions IA proposées
                apparaîtront ici.
              </p>
              <div v-else class="ai-activity-layout">
                <div class="ai-usage-list">
                  <article
                    v-for="suggestion in filteredSuggestions"
                    :key="suggestion.id"
                    class="ai-usage-row"
                    :class="{
                      active: selectedSuggestion?.id === suggestion.id,
                    }"
                    role="button"
                    tabindex="0"
                    @click="selectSuggestion(suggestion)"
                    @keyup.enter="selectSuggestion(suggestion)"
                  >
                    <span
                      ><strong
                        >{{
                          suggestionStatusLabel(String(suggestion.status))
                        }}
                        · {{ suggestion.suggestion_type }}</strong
                      ><small
                        >{{
                          suggestion.target_label ||
                          `${suggestion.target_type} #${suggestion.target_id}`
                        }}
                        · {{ formatDate(suggestion.created_at) }}</small
                      ></span
                    >
                    <span>{{
                      suggestion.target_field || "champ non défini"
                    }}</span>
                  </article>
                </div>
                <div
                  class="ai-test-result"
                  :class="
                    selectedSuggestion?.status === 'rejected' ||
                    selectedSuggestion?.status === 'expired'
                      ? 'is-error'
                      : selectedSuggestion?.status === 'applied'
                        ? 'is-success'
                        : 'is-idle'
                  "
                >
                  <div class="ai-test-summary">
                    <span class="ai-test-status">{{
                      selectedSuggestion
                        ? suggestionStatusLabel(
                            String(selectedSuggestion.status),
                          )
                        : "Aucune suggestion"
                    }}</span
                    ><small v-if="selectedSuggestion"
                      >{{ selectedSuggestion.target_label }} ·
                      {{ suggestionActionRunLabel(selectedSuggestion) }}</small
                    >
                  </div>
                  <template v-if="selectedSuggestion">
                    <div class="ai-test-meta">
                      <span
                        ><strong>Cible</strong
                        >{{ selectedSuggestion.target_type }} #{{
                          selectedSuggestion.target_id
                        }}</span
                      ><span
                        ><strong>Champ</strong
                        >{{ selectedSuggestion.source_field || "—" }} →
                        {{ selectedSuggestion.target_field || "—" }}</span
                      ><span
                        ><strong>Type</strong
                        >{{ selectedSuggestion.suggestion_type }}</span
                      ><span
                        ><strong>Créée</strong
                        >{{ formatDate(selectedSuggestion.created_at) }}</span
                      >
                    </div>
                    <p>
                      <strong>Aperçu</strong><br />{{
                        selectedSuggestion.preview_text || "—"
                      }}
                    </p>
                    <p v-if="selectedSuggestion.reason">
                      <strong>Raison</strong><br />{{
                        selectedSuggestion.reason
                      }}
                    </p>
                    <div class="ai-panel-tools ai-panel-tools--right">
                      <button
                        class="btn"
                        type="button"
                        :disabled="
                          saving ||
                          !canManageSuggestions ||
                          !suggestionCan(selectedSuggestion, 'proposed')
                        "
                        @click="
                          updateSuggestionStatus(selectedSuggestion, 'propose')
                        "
                      >
                        Proposer
                      </button>
                      <button
                        class="btn"
                        type="button"
                        :disabled="
                          saving ||
                          !canManageSuggestions ||
                          !suggestionCan(selectedSuggestion, 'accepted')
                        "
                        @click="
                          updateSuggestionStatus(selectedSuggestion, 'accept')
                        "
                      >
                        Accepter
                      </button>
                      <button
                        class="btn"
                        type="button"
                        :disabled="
                          saving ||
                          !canManageSuggestions ||
                          !suggestionCan(selectedSuggestion, 'rejected')
                        "
                        @click="
                          updateSuggestionStatus(selectedSuggestion, 'reject')
                        "
                      >
                        Rejeter
                      </button>
                      <button
                        class="btn"
                        type="button"
                        :disabled="
                          saving ||
                          !canManageSuggestions ||
                          !suggestionCan(selectedSuggestion, 'expired')
                        "
                        @click="
                          updateSuggestionStatus(selectedSuggestion, 'expire')
                        "
                      >
                        Expirer
                      </button>
                    </div>
                    <label class="ai-field-with-hint mt-3"
                      ><span class="schema-label-with-hint"
                        ><span>applied_action_run_id</span
                        ><InfoHint
                          text="Requis pour marquer comme appliquée. Il doit pointer vers une action qui a créé une révision ou un brouillon. Aucune modification directe de production n’est réalisée ici."
                          placement="end" /></span
                      ><input
                        type="number"
                        min="0"
                        v-model.number="suggestionApplyRunId"
                        :disabled="
                          saving ||
                          !canApplySuggestions ||
                          !suggestionCan(selectedSuggestion, 'applied')
                        "
                    /></label>
                    <button
                      class="btn primary mt-3"
                      type="button"
                      :disabled="
                        saving ||
                        !canApplySuggestions ||
                        !suggestionCan(selectedSuggestion, 'applied') ||
                        suggestionApplyRunId <= 0
                      "
                      @click="
                        updateSuggestionStatus(selectedSuggestion, 'apply')
                      "
                    >
                      Appliquer via révision/brouillon
                    </button>
                    <details class="ai-test-details mt-3">
                      <summary>Historique minimal</summary>
                      <pre>{{
                        JSON.stringify(
                          selectedSuggestion.history || [],
                          null,
                          2,
                        )
                      }}</pre>
                    </details>
                    <details class="ai-test-details">
                      <summary>Détails suggestion</summary>
                      <pre>{{
                        JSON.stringify(selectedSuggestion, null, 2)
                      }}</pre>
                    </details>
                  </template>
                </div>
              </div>
            </section>

            <section v-else class="ai-tab-panel">
              <form
                class="ai-settings-list ai-settings-list--flush"
                @submit.prevent="saveSettings"
              >
                <label class="ai-toggle-row"
                  ><span
                    ><strong>Activer l’IA globale</strong></span
                  ><input
                    type="checkbox"
                    v-model="aiEnabled"
                    :disabled="!canManage"
                /></label>
                <label class="ai-toggle-row"
                  ><span
                    ><strong>Journaliser les prompts complets</strong></span
                  ><input
                    type="checkbox"
                    v-model="logPrompts"
                    :disabled="!canManage"
                /></label>
                <label class="ai-toggle-row"
                  ><span
                    ><strong>Journaliser les réponses complètes</strong></span
                  ><input
                    type="checkbox"
                    v-model="logResponses"
                    :disabled="!canManage"
                /></label>
                <button
                  class="btn primary"
                  type="submit"
                  :disabled="saving || !canManage"
                >
                  Enregistrer les paramètres
                </button>
              </form>
            </section>
          </article>
        </div>
      </main>
    </div>

    <div
      v-if="catalogModal"
      class="modal-backdrop-editor"
      @click.self="closeCatalogModal"
    >
      <form
        v-if="catalogModal === 'types'"
        class="block-modal ai-modal"
        @submit.prevent="saveType"
      >
        <header class="block-modal__header">
          <div>
            <h2>{{ catalogModalTitle }}</h2>
          </div>
          <button
            class="block-modal__close"
            type="button"
            @click="closeCatalogModal"
          >
            ×
          </button>
        </header>
        <div class="block-modal__body">
          <div class="block-grid">
            <label class="ai-field-with-hint"
              ><span class="schema-label-with-hint"
                ><span>{{ blueprintFieldLabel("provider_type", "key") }}</span
                ><InfoHint
                  v-if="blueprintHasHelp('provider_type', 'key')"
                  :text="blueprintFieldHelp('provider_type', 'key')"
                  placement="end" /></span
              ><input
                v-model="typeForm.key"
                placeholder="editorial"
                :disabled="!canManage || typeForm.is_system"
            /></label>
            <label class="ai-field-with-hint"
              ><span class="schema-label-with-hint"
                ><span>{{ blueprintFieldLabel("provider_type", "name") }}</span
                ><InfoHint
                  v-if="blueprintHasHelp('provider_type', 'name')"
                  :text="blueprintFieldHelp('provider_type', 'name')"
                  placement="end" /></span
              ><input
                v-model="typeForm.name"
                placeholder="Éditorial"
                :disabled="!canManage"
            /></label>
            <label class="ai-field-with-hint"
              ><span class="schema-label-with-hint"
                ><span>{{
                  blueprintFieldLabel("provider_type", "sort_order")
                }}</span
                ><InfoHint
                  v-if="blueprintHasHelp('provider_type', 'sort_order')"
                  :text="blueprintFieldHelp('provider_type', 'sort_order')"
                  placement="end" /></span
              ><input
                type="number"
                min="0"
                v-model.number="typeForm.sort_order"
                :disabled="!canManage"
            /></label>
            <label class="checkline--modal"
              ><input
                type="checkbox"
                v-model="typeForm.enabled"
                :disabled="!canManage" />
              <span class="schema-label-with-hint"
                ><span>{{
                  blueprintFieldLabel("provider_type", "enabled")
                }}</span
                ><InfoHint
                  v-if="blueprintHasHelp('provider_type', 'enabled')"
                  :text="blueprintFieldHelp('provider_type', 'enabled')"
                  placement="end" /></span
            ></label>
          </div>
          <label class="ai-field-with-hint"
            ><span class="schema-label-with-hint"
              ><span>{{
                blueprintFieldLabel("provider_type", "description")
              }}</span
              ><InfoHint
                v-if="blueprintHasHelp('provider_type', 'description')"
                :text="blueprintFieldHelp('provider_type', 'description')"
                placement="end" /></span
            ><textarea
              rows="3"
              v-model="typeForm.description"
              :disabled="!canManage"
            ></textarea>
          </label>
        </div>
        <footer class="block-modal__footer block-modal__footer--split">
          <button
            class="btn danger"
            type="button"
            :disabled="
              saving ||
              !canManage ||
              catalogModalMode !== 'edit' ||
              typeForm.is_system
            "
            @click="deleteCurrentType"
          >
            Supprimer
          </button>
          <button
            class="btn primary"
            type="submit"
            :disabled="saving || !canManage"
          >
            Enregistrer
          </button>
        </footer>
      </form>

      <form
        v-else-if="catalogModal === 'providers'"
        class="block-modal ai-modal"
        @submit.prevent="saveProvider"
      >
        <header class="block-modal__header">
          <div>
            <h2>{{ catalogModalTitle }}</h2>
          </div>
          <button
            class="block-modal__close"
            type="button"
            @click="closeCatalogModal"
          >
            ×
          </button>
        </header>
        <div class="block-modal__body">
          <div class="block-stack">
            <label class="schema-label-with-hint"
              ><span>{{ blueprintFieldLabel("provider", "type_key") }}</span
              ><InfoHint
                v-if="blueprintHasHelp('provider', 'type_key')"
                :text="blueprintFieldHelp('provider', 'type_key')"
                placement="end"
            /></label>
            <div
              class="ai-choice-list ai-choice-list--inline"
              role="radiogroup"
              aria-label="Usage principal du fournisseur"
            >
              <button
                v-for="type in enabledUsages"
                :key="type.key"
                class="ai-choice-tab"
                type="button"
                :class="{ active: providerForm.type_key === type.key }"
                :disabled="!canManage"
                @click="providerForm.type_key = type.key"
              >
                <span class="ai-choice-dot"></span>{{ type.name }}
              </button>
            </div>
          </div>
          <div class="block-grid">
            <label class="ai-field-with-hint"
              ><span class="schema-label-with-hint"
                ><span>{{ blueprintFieldLabel("provider", "key") }}</span
                ><InfoHint
                  v-if="blueprintHasHelp('provider', 'key')"
                  :text="blueprintFieldHelp('provider', 'key')"
                  placement="end" /></span
              ><input
                v-model="providerForm.key"
                placeholder="openai"
                :disabled="!canManage || providerForm.key === 'null_provider'"
            /></label>
            <label class="ai-field-with-hint"
              ><span class="schema-label-with-hint"
                ><span>{{ blueprintFieldLabel("provider", "name") }}</span
                ><InfoHint
                  v-if="blueprintHasHelp('provider', 'name')"
                  :text="blueprintFieldHelp('provider', 'name')"
                  placement="end" /></span
              ><input
                v-model="providerForm.name"
                placeholder="OpenAI"
                :disabled="!canManage"
            /></label>
          </div>
          <div class="block-stack">
            <label class="schema-label-with-hint"
              ><span>{{
                blueprintFieldLabel("provider", "provider_type")
              }}</span
              ><InfoHint
                v-if="blueprintHasHelp('provider', 'provider_type')"
                :text="blueprintFieldHelp('provider', 'provider_type')"
                placement="end"
            /></label>
            <div
              class="ai-choice-list ai-choice-list--inline"
              role="radiogroup"
              aria-label="Adaptateur technique"
            >
              <button
                v-for="adapter in [
                  'openai_compatible',
                  'custom_http',
                  'infomaniak',
                  'ollama',
                  'local',
                  'null',
                ]"
                :key="adapter"
                class="ai-choice-tab"
                type="button"
                :class="{ active: providerForm.provider_type === adapter }"
                :disabled="!canManage || providerForm.key === 'null_provider'"
                @click="providerForm.provider_type = adapter"
              >
                <span class="ai-choice-dot"></span>{{ adapter }}
              </button>
            </div>
          </div>
          <div class="block-grid">
            <label class="ai-field-with-hint"
              ><span class="schema-label-with-hint"
                ><span>{{ blueprintFieldLabel("provider", "base_url") }}</span
                ><InfoHint
                  v-if="blueprintHasHelp('provider', 'base_url')"
                  :text="blueprintFieldHelp('provider', 'base_url')"
                  placement="end" /></span
              ><input
                v-model="providerForm.base_url"
                placeholder="https://api.openai.com/v1"
                :disabled="!canManage"
            /></label>
            <label class="ai-field-with-hint"
              ><span class="schema-label-with-hint"
                ><span>{{
                  blueprintFieldLabel("provider", "api_key_ref")
                }}</span
                ><InfoHint
                  v-if="blueprintFieldHelp('provider', 'api_key_ref')"
                  :text="blueprintFieldHelp('provider', 'api_key_ref')"
                  placement="end" /></span
              ><input
                v-model="providerForm.api_key_ref"
                placeholder="env:OPENAI_API_KEY"
                :disabled="!canManage"
            /></label>
            <label class="checkline--modal"
              ><input
                type="checkbox"
                v-model="providerForm.enabled"
                :disabled="!canManage" />
              <span class="schema-label-with-hint"
                ><span>{{ blueprintFieldLabel("provider", "enabled") }}</span
                ><InfoHint
                  v-if="blueprintHasHelp('provider', 'enabled')"
                  :text="blueprintFieldHelp('provider', 'enabled')"
                  placement="end" /></span
            ></label>
            <label class="checkline--modal"
              ><input
                type="checkbox"
                v-model="providerForm.is_default"
                :disabled="!canManage" />
              <span class="schema-label-with-hint"
                ><span>{{ blueprintFieldLabel("provider", "is_default") }}</span
                ><InfoHint
                  v-if="blueprintHasHelp('provider', 'is_default')"
                  :text="blueprintFieldHelp('provider', 'is_default')"
                  placement="end" /></span
            ></label>
          </div>
        </div>
        <footer class="block-modal__footer block-modal__footer--split">
          <button
            class="btn danger"
            type="button"
            :disabled="
              saving ||
              !canManage ||
              catalogModalMode !== 'edit' ||
              !canDeleteCurrentProvider
            "
            @click="deleteCurrentProvider"
          >
            Supprimer
          </button>
          <button
            class="btn primary"
            type="submit"
            :disabled="saving || !canManage"
          >
            Enregistrer
          </button>
        </footer>
      </form>

      <form v-else class="block-modal ai-modal" @submit.prevent="saveModel">
        <header class="block-modal__header">
          <div>
            <h2>{{ catalogModalTitle }}</h2>
          </div>
          <button
            class="block-modal__close"
            type="button"
            @click="closeCatalogModal"
          >
            ×
          </button>
        </header>
        <div class="block-modal__body">
          <div class="block-stack">
            <label class="schema-label-with-hint"
              ><span>{{ blueprintFieldLabel("model", "provider_id") }}</span
              ><InfoHint
                v-if="blueprintHasHelp('model', 'provider_id')"
                :text="blueprintFieldHelp('model', 'provider_id')"
                placement="end"
            /></label>
            <div
              class="ai-choice-list ai-choice-list--inline"
              role="radiogroup"
              aria-label="Fournisseur du modèle"
            >
              <button
                v-for="provider in sortedProviders"
                :key="provider.key"
                class="ai-choice-tab"
                type="button"
                :class="{ active: modelForm.provider_key === provider.key }"
                :disabled="!canManage"
                @click="modelForm.provider_key = provider.key"
              >
                <span class="ai-choice-dot"></span>{{ provider.name }}
              </button>
            </div>
          </div>
          <div class="block-stack">
            <label class="schema-label-with-hint"
              ><span>{{ blueprintFieldLabel("model", "usage_keys") }}</span
              ><InfoHint
                v-if="blueprintHasHelp('model', 'usage_keys')"
                :text="blueprintFieldHelp('model', 'usage_keys')"
                placement="end"
            /></label>
            <div
              class="ai-choice-list ai-choice-list--inline"
              role="radiogroup"
              aria-label="Usage du modèle"
            >
              <button
                v-for="type in enabledUsages"
                :key="type.key"
                class="ai-choice-tab"
                type="button"
                :class="{ active: modelForm.usage_keys.includes(type.key) }"
                :disabled="!canManage"
                @click="toggleModelUsage(type.key)"
              >
                <span class="ai-choice-dot"></span>{{ type.name }}
              </button>
            </div>
          </div>
          <div class="block-grid">
            <label class="ai-field-with-hint"
              ><span class="schema-label-with-hint"
                ><span>{{ blueprintFieldLabel("model", "key") }}</span
                ><InfoHint
                  v-if="blueprintHasHelp('model', 'key')"
                  :text="blueprintFieldHelp('model', 'key')"
                  placement="end" /></span
              ><input
                v-model="modelForm.key"
                placeholder="gpt-4.1-mini"
                :disabled="!canManage"
            /></label>
            <label class="ai-field-with-hint"
              ><span class="schema-label-with-hint"
                ><span>{{ blueprintFieldLabel("model", "name") }}</span
                ><InfoHint
                  v-if="blueprintHasHelp('model', 'name')"
                  :text="blueprintFieldHelp('model', 'name')"
                  placement="end" /></span
              ><input
                v-model="modelForm.name"
                placeholder="GPT 4.1 mini"
                :disabled="!canManage"
            /></label>
            <label class="ai-field-with-hint"
              ><span class="schema-label-with-hint"
                ><span>{{ blueprintFieldLabel("model", "api_key_ref") }}</span
                ><InfoHint
                  v-if="blueprintHasHelp('model', 'api_key_ref')"
                  :text="blueprintFieldHelp('model', 'api_key_ref')"
                  placement="end" /></span
              ><input
                v-model="modelForm.api_key_ref"
                placeholder="vide = hérite du fournisseur"
                :disabled="!canManage"
            /></label>
            <label class="ai-field-with-hint"
              ><span class="schema-label-with-hint"
                ><span>{{ blueprintFieldLabel("model", "currency") }}</span
                ><InfoHint
                  v-if="blueprintHasHelp('model', 'currency')"
                  :text="blueprintFieldHelp('model', 'currency')"
                  placement="end" /></span
              ><input
                v-model="modelForm.currency"
                placeholder="CHF"
                :disabled="!canManage"
            /></label>
            <label class="ai-field-with-hint"
              ><span class="schema-label-with-hint"
                ><span>{{ blueprintFieldLabel("model", "input_price") }}</span
                ><InfoHint
                  v-if="blueprintHasHelp('model', 'input_price')"
                  :text="blueprintFieldHelp('model', 'input_price')"
                  placement="end" /></span
              ><input
                type="number"
                min="0"
                step="0.000001"
                v-model.number="modelForm.input_price"
                :disabled="!canManage"
            /></label>
            <label class="ai-field-with-hint"
              ><span class="schema-label-with-hint"
                ><span>{{ blueprintFieldLabel("model", "output_price") }}</span
                ><InfoHint
                  v-if="blueprintHasHelp('model', 'output_price')"
                  :text="blueprintFieldHelp('model', 'output_price')"
                  placement="end" /></span
              ><input
                type="number"
                min="0"
                step="0.000001"
                v-model.number="modelForm.output_price"
                :disabled="!canManage"
            /></label>
            <label class="ai-field-with-hint"
              ><span class="schema-label-with-hint"
                ><span>Unité tarifaire</span
                ><InfoHint
                  text="Unité utilisée pour calculer le coût à partir des tokens. Par défaut : prix par million de tokens."
                  placement="end" /></span
              ><select v-model="modelForm.price_unit" :disabled="!canManage">
                <option value="1m_tokens">Prix par 1M tokens</option>
                <option value="1k_tokens">Prix par 1k tokens</option>
                <option value="token">Prix par token</option>
              </select></label
            >
            <label class="ai-field-with-hint"
              ><span class="schema-label-with-hint"
                ><span>{{
                  blueprintFieldLabel("model", "max_monthly_budget_json")
                }}</span
                ><InfoHint
                  v-if="blueprintHasHelp('model', 'max_monthly_budget_json')"
                  :text="blueprintFieldHelp('model', 'max_monthly_budget_json')"
                  placement="end" /></span
              ><input
                type="number"
                min="0"
                step="1"
                v-model.number="modelForm.budget_amount"
                :disabled="!canManage"
            /></label>
            <label class="ai-field-with-hint"
              ><span class="schema-label-with-hint"
                ><span>Comportement budget</span
                ><InfoHint
                  text="En mode blocage, un appel externe est refusé si l’estimation dépasse le budget mensuel. En mode avertissement, l’appel reste autorisé."
                  placement="end" /></span
              ><select v-model="modelForm.budget_mode" :disabled="!canManage">
                <option value="block">Bloquer au dépassement</option>
                <option value="warn">Avertir seulement</option>
              </select></label
            >
            <label class="checkline--modal"
              ><input
                type="checkbox"
                v-model="modelForm.enabled"
                :disabled="!canManage" />
              <span class="schema-label-with-hint"
                ><span>{{ blueprintFieldLabel("model", "enabled") }}</span
                ><InfoHint
                  v-if="blueprintHasHelp('model', 'enabled')"
                  :text="blueprintFieldHelp('model', 'enabled')"
                  placement="end" /></span
            ></label>
          </div>
        </div>
        <footer class="block-modal__footer block-modal__footer--split">
          <button
            class="btn danger"
            type="button"
            :disabled="
              saving ||
              !canManage ||
              catalogModalMode !== 'edit' ||
              !canDeleteCurrentModel
            "
            @click="deleteCurrentModel"
          >
            Supprimer
          </button>
          <button
            class="btn primary"
            type="submit"
            :disabled="
              saving ||
              !canManage ||
              !modelForm.provider_key ||
              modelForm.usage_keys.length === 0
            "
          >
            Enregistrer
          </button>
        </footer>
      </form>
    </div>
  </section>
</template>
