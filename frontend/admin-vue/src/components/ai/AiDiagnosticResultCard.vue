<script setup lang="ts">
type DiagnosticBadge = { key?: string; label: string; tone?: string };
type DiagnosticResult = {
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
  provider_key?: string;
  provider_label?: string;
  model_key?: string;
  model_label?: string;
  usage_key?: string;
  usage_label?: string;
  endpoint?: string;
  action_key?: string | null;
  prompt_key?: string | null;
  prompt_name?: string | null;
  prompt_source?: string | null;
  system_message?: string | null;
  user_message?: string | null;
  prompt?: string;
  content?: string;
  response_preview?: string;
  json?: unknown;
  body_preview?: string | null;
  usage?: Record<string, unknown>;
  input_tokens?: number;
  output_tokens?: number;
  total_tokens?: number;
  test_kind?: string;
  estimated_cost?: number;
  currency?: string;
  provider_response_id?: string | null;
  diagnostic_badges?: DiagnosticBadge[];
};

const props = defineProps<{
  result: DiagnosticResult | null;
  siteName?: string;
}>();

function ok(): boolean {
  return Boolean(props.result?.success ?? props.result?.ok ?? false);
}
function statusClass(): string {
  if (!props.result) return "is-idle";
  return ok() ? "is-success" : "is-error";
}
function money(value: unknown, currency: unknown): string {
  return `${String(currency || "CHF").toUpperCase()} ${Number(value || 0).toFixed(5)}`;
}
function integer(value: unknown): string {
  return Number(value || 0).toLocaleString("fr-CH");
}
function usageValue(key: string): number {
  const usage = props.result?.usage || {};
  const aliases: Record<string, string[]> = {
    input_tokens: ["input_tokens", "prompt_tokens"],
    output_tokens: ["output_tokens", "completion_tokens"],
    total_tokens: ["total_tokens"],
  };
  for (const candidate of aliases[key] || [key]) {
    const fromUsage = Number(usage[candidate]);
    if (Number.isFinite(fromUsage) && fromUsage > 0) return fromUsage;
    const fromResult = Number((props.result || {})[candidate as keyof DiagnosticResult]);
    if (Number.isFinite(fromResult) && fromResult > 0) return fromResult;
  }
  if (key === "total_tokens") return usageValue("input_tokens") + usageValue("output_tokens");
  return 0;
}
function responseText(): string {
  return String(
    props.result?.response_preview ||
      props.result?.content ||
      props.result?.body_preview ||
      "Aucune réponse lisible.",
  );
}
function humanMessage(): string {
  if (!props.result) return "";
  return String(
    props.result.human_message ||
      props.result.error_message ||
      (ok()
        ? "Le provider IA a répondu correctement."
        : "Le test IA a échoué."),
  );
}
function suggestedFix(): string {
  if (!props.result) return "";
  return String(
    props.result.suggested_fix ||
      (ok()
        ? "Aucune correction nécessaire."
        : "Vérifiez le fournisseur, le modèle configuré, la clé API et le budget."),
  );
}
function promptText(): string {
  const system = String(props.result?.system_message || "").trim();
  const user = String(props.result?.user_message || props.result?.prompt || "").trim();
  return [
    system ? `Système:\n${system}` : "",
    user ? `Utilisateur:\n${user}` : "",
  ]
    .filter(Boolean)
    .join("\n\n") || "Aucun prompt envoyé pour ce test.";
}
function rawJson(): string {
  return JSON.stringify(props.result ?? {}, null, 2);
}
</script>

<template>
  <article class="ai-test-result" :class="statusClass()">
    <p v-if="!result" class="muted small">Aucun test sélectionné.</p>

    <template v-else>
      <div class="ai-test-meta ai-test-meta--compact">
        <span><strong>Temps</strong>{{ Number(result.duration_ms || 0) }} ms</span>
        <span><strong>Tokens</strong>{{ integer(usageValue('input_tokens')) }} in · {{ integer(usageValue('output_tokens')) }} out</span>
        <span><strong>Coût</strong>{{ money(result.estimated_cost, result.currency) }}</span>
      </div>

      <p class="ai-readable-response"><strong>Réponse</strong> {{ responseText() }}</p>
      <p class="ai-readable-note">{{ humanMessage() }} {{ suggestedFix() }}</p>

      <details class="ai-test-details">
        <summary>Détails</summary>
        <div class="ai-test-details__content">
          <strong>Prompt envoyé</strong>
          <pre>{{ promptText() }}</pre>
          <strong>JSON</strong>
          <pre>{{ rawJson() }}</pre>
        </div>
      </details>
    </template>
  </article>
</template>
