<script setup lang="ts">
type AiActionResult = {
  action_key?: string;
  success?: boolean;
  suggestion?: Record<string, unknown> | null;
  preview_text?: string;
  human_message?: string;
  suggested_fix?: string;
  raw?: unknown;
};

const props = defineProps<{
  result: AiActionResult | null;
}>();
</script>

<template>
  <aside class="ai-action-result-panel">
    <p v-if="!props.result" class="empty-state">
      <strong>Aucune action IA exécutée</strong>
      <span>Les résultats d’actions éditoriales apparaîtront ici comme suggestions, jamais comme modification directe du contenu publié.</span>
    </p>
    <template v-else>
      <header>
        <strong>{{ props.result.success ? 'Suggestion préparée' : 'Action IA à vérifier' }}</strong>
        <small>{{ props.result.action_key }}</small>
      </header>
      <p>{{ props.result.preview_text || props.result.human_message || 'Résultat sans aperçu.' }}</p>
      <p v-if="props.result.suggested_fix" class="muted small">{{ props.result.suggested_fix }}</p>
      <details>
        <summary>Détails techniques</summary>
        <pre>{{ JSON.stringify(props.result.raw || props.result, null, 2) }}</pre>
      </details>
    </template>
  </aside>
</template>
