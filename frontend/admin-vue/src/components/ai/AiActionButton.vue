<script setup lang="ts">
type AiAction = {
  action_key: string;
  label: string;
  description?: string;
  available?: boolean;
  availability?: string[];
  supports_streaming?: boolean;
  supports_async?: boolean;
  model_label?: string | null;
  provider_label?: string | null;
};

const props = defineProps<{
  action: AiAction;
  running?: boolean;
}>();

const emit = defineEmits<{
  run: [action: AiAction];
}>();

function reasonLabel(reason: string): string {
  const labels: Record<string, string> = {
    permission_missing: "permission manquante",
    usage_not_configured: "usage non configuré",
    provider_disabled: "fournisseur désactivé",
    model_disabled: "modèle désactivé",
  };
  return labels[reason] || reason;
}
</script>

<template>
  <button
    class="ai-action-button"
    type="button"
    :disabled="running || !props.action.available"
    @click="emit('run', props.action)"
  >
    <span>
      <strong>{{ props.action.label }}</strong>
      <small>{{ props.action.description }}</small>
      <small v-if="props.action.model_label">
        {{ props.action.provider_label || 'Provider IA' }} · {{ props.action.model_label }}
      </small>
      <small v-else-if="props.action.availability?.length">
        {{ props.action.availability.map(reasonLabel).join(' · ') }}
      </small>
    </span>
    <span class="ai-action-button__badges">
      <em v-if="props.action.supports_streaming">stream</em>
      <em v-if="props.action.supports_async">async</em>
      <em>{{ props.action.available ? 'prêt' : 'à configurer' }}</em>
    </span>
  </button>
</template>
