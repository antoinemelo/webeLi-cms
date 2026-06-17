<script setup lang="ts">
type AiSuggestion = {
  id?: number;
  title?: string;
  suggestion_type?: string;
  status?: string;
  target_type?: string;
  target_id?: string;
  target_label?: string;
  preview_text?: string;
  reason?: string;
  created_at?: string;
};

const props = defineProps<{
  suggestion: AiSuggestion;
  selected?: boolean;
}>();

const emit = defineEmits<{
  select: [suggestion: AiSuggestion];
  accept: [suggestion: AiSuggestion];
  reject: [suggestion: AiSuggestion];
}>();
</script>

<template>
  <article class="ai-suggestion-card" :class="{ active: props.selected }">
    <header>
      <div>
        <strong>{{ props.suggestion.title || props.suggestion.suggestion_type || 'Suggestion IA' }}</strong>
        <small>
          {{ props.suggestion.target_label || props.suggestion.target_type || 'contenu' }}
          <template v-if="props.suggestion.target_id">#{{ props.suggestion.target_id }}</template>
        </small>
      </div>
      <span class="ai-suggestion-card__status">{{ props.suggestion.status || 'draft' }}</span>
    </header>
    <p>{{ props.suggestion.preview_text || 'Aucun aperçu disponible.' }}</p>
    <small v-if="props.suggestion.reason">{{ props.suggestion.reason }}</small>
    <footer>
      <button class="btn" type="button" @click="emit('select', props.suggestion)">Voir</button>
      <button class="btn" type="button" @click="emit('reject', props.suggestion)">Rejeter</button>
      <button class="btn primary" type="button" @click="emit('accept', props.suggestion)">Accepter</button>
    </footer>
  </article>
</template>
