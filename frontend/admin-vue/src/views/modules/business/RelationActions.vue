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
    <button v-if="canMessage" class="btn primary small" type="button" @click="$emit('message')">Envoyer un message</button>
    <button v-else-if="canMemo" class="btn primary small" type="button" @click="$emit('memo')">Ajouter mémo</button>
    <details v-if="canMemo || canShare || canEdit" class="relation-actions-menu">
      <summary class="btn ghost small">Actions</summary>
      <div>
        <button v-if="canMessage && canMemo" type="button" @click="$emit('memo')">Ajouter mémo</button>
        <button v-if="canShare" type="button" @click="$emit('share')">Partager</button>
        <button v-if="canEdit" type="button" @click="$emit('edit')">Modifier</button>
      </div>
    </details>
  </div>
</template>

<style scoped>
.relation-actions {
  display: flex;
  gap: .45rem;
  align-items: center;
  flex-wrap: wrap;
}
.relation-actions-menu{position:relative}.relation-actions-menu summary{list-style:none;cursor:pointer}.relation-actions-menu summary::-webkit-details-marker{display:none}.relation-actions-menu>div{position:absolute;right:0;top:calc(100% + .25rem);z-index:5;min-width:10rem;display:grid;background:#fff;border:1px solid #d0d5dd;border-radius:6px;padding:.3rem;box-shadow:0 8px 20px #10182822}.relation-actions-menu button{border:0;background:transparent;text-align:left;padding:.5rem;border-radius:4px}.relation-actions-menu button:hover{background:#f2f4f7}
</style>
