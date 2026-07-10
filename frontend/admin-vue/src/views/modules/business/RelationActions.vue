<script setup lang="ts">
withDefaults(defineProps<{
  canMessage?: boolean;
  canMemo?: boolean;
  canShare?: boolean;
  canEdit?: boolean;
}>(), {
  canMessage: true,
  canMemo: true,
  canShare: true,
  canEdit: true,
});

defineEmits<{
  message: [];
  memo: [];
  share: [];
  edit: [];
}>();
</script>

<template>
  <div class="relation-actions">
    <button v-if="canMessage" class="btn small" type="button" @click="$emit('message')">Envoyer un message</button>
    <button v-if="canMemo" class="btn small" type="button" @click="$emit('memo')">Ajouter mémo</button>
    <button v-if="canShare" class="btn ghost small" type="button" @click="$emit('share')">Partager</button>
    <button v-if="canEdit" class="btn ghost small" type="button" @click="$emit('edit')">Modifier</button>
  </div>
</template>

<style scoped>
.relation-actions {
  display: flex;
  gap: .45rem;
  align-items: center;
  flex-wrap: wrap;
}
</style>
