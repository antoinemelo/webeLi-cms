<script setup lang="ts">
import StatusBadge from '@/components/ui/StatusBadge.vue';
import RelationActions from './RelationActions.vue';

defineProps<{
  name: string;
  typeLabel: string;
  status?: string;
  contextLine?: string;
  email?: string;
  phone?: string;
  memoCount?: number;
  sharedMemoCount?: number;
  canMessage?: boolean;
  canMemo?: boolean;
  canEdit?: boolean;
}>();

defineEmits<{
  message: [];
  memo: [];
  share: [];
  edit: [];
  openMemos: [];
}>();
</script>

<template>
  <header class="relation-header">
    <div class="relation-header-main">
      <div>
        <h2>{{ name || 'Nouvelle relation' }}</h2>
        <p class="relation-header-meta">
          <span>{{ typeLabel }}</span>
          <span v-if="status">· <StatusBadge :status="status" /></span>
          <span v-if="contextLine">· {{ contextLine }}</span>
          <span v-if="email">· {{ email }}</span>
          <span v-if="phone">· {{ phone }}</span>
        </p>
      </div>
      <div v-if="Number(memoCount || 0) > 0 || Number(sharedMemoCount || 0) > 0" class="relation-badges">
        <button v-if="Number(memoCount || 0) > 0" class="relation-badge" type="button" @click="$emit('openMemos')">
          Mémos {{ memoCount }}
        </button>
        <button v-if="Number(sharedMemoCount || 0) > 0" class="relation-badge" type="button" @click="$emit('openMemos')">
          Partagés {{ sharedMemoCount }}
        </button>
      </div>
    </div>
    <RelationActions
      :can-message="canMessage"
      :can-memo="canMemo"
      :can-edit="canEdit"
      @message="$emit('message')"
      @memo="$emit('memo')"
      @share="$emit('share')"
      @edit="$emit('edit')"
    />
  </header>
</template>

<style scoped>
.relation-header {
  display: grid;
  gap: .7rem;
  border: 1px solid #e4e7ec;
  border-radius: 8px;
  padding: .85rem;
  background: #f8fafc;
}

.relation-header-main {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: flex-start;
}

.relation-header h2 {
  margin: 0;
  font-size: 1.2rem;
}

.relation-header-meta {
  display: flex;
  gap: .4rem;
  align-items: center;
  flex-wrap: wrap;
  margin: .2rem 0 0;
  color: #667085;
  font-size: .92rem;
  line-height: 1.45;
}

.relation-badges {
  display: flex;
  gap: .35rem;
  flex-wrap: wrap;
}

.relation-badge {
  border: 1px solid #cfd8dc;
  border-radius: 999px;
  background: #fff;
  padding: .25rem .55rem;
  cursor: pointer;
}
</style>
