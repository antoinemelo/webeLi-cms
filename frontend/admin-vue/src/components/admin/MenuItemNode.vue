<script setup lang="ts">
import { computed } from 'vue';

export type AdminLanguageLite = { code: string; name?: string; native_name?: string; is_default?: boolean };
export type MenuTargetType = 'url'|'external'|'anchor'|'term'|'resource'|'separator';
export type MenuItemDraft = {
  uid: string;
  label: string;
  title_attr: string;
  target_type: MenuTargetType;
  url: string;
  term_id: string;
  resource_type: string;
  resource_id: string;
  css_class: string;
  open_in_new_tab: boolean;
  is_active: boolean;
  target_label?: string;
  target_status?: string;
  target_missing?: boolean;
  localizations: Record<string, { label: string; title_attr: string }>;
  children: MenuItemDraft[];
};

const props = defineProps<{
  item: MenuItemDraft;
  list: MenuItemDraft[];
  index: number;
  activeLanguage: string;
  selectedUid: string;
  depth?: number;
  maxDepth?: number;
}>();
const emit = defineEmits<{
  select: [item: MenuItemDraft];
  addChild: [item: MenuItemDraft];
  remove: [list: MenuItemDraft[], index: number];
  move: [list: MenuItemDraft[], index: number, direction: -1|1];
  indent: [list: MenuItemDraft[], index: number];
  outdent: [list: MenuItemDraft[], index: number];
}>();

const depth = computed(() => props.depth ?? 0);
const maxDepth = computed(() => props.maxDepth ?? 3);
const currentLabel = computed(() => props.item.localizations[props.activeLanguage]?.label || props.item.label || 'Item sans libellé');
const canAddChild= computed(() => depth.value < maxDepth.value - 1);
</script>

<template>
  <div class="menu-builder-node" :class="[`menu-builder-node--depth-${depth}`, { 'is-selected': item.uid === selectedUid, 'is-muted': !item.is_active }]">
    <div class="menu-builder-row" role="button" tabindex="0" @click="emit('select', item)" @keydown.enter="emit('select', item)">
      <div class="menu-builder-row__main">
        <strong class="menu-builder-row__label">{{ currentLabel }}</strong>
        <span v-if="item.target_status && item.target_status !== 'published'" class="badge text-bg-warning">{{ item.target_status }}</span>
        <span v-if="item.target_missing" class="badge text-bg-danger">cible introuvable</span>
      </div>
      <div class="menu-builder-row__tools btn-group btn-group-sm" role="group" aria-label="Actions sur cet item de menu" @click.stop>
        <button class="btn btn-sm btn-outline-secondary" :disabled="index === 0" title="Monter" @click="emit('move', list, index, -1)">↑</button>
        <button class="btn btn-sm btn-outline-secondary" :disabled="index === list.length - 1" title="Descendre" @click="emit('move', list, index, 1)">↓</button>
        <button class="btn btn-sm btn-outline-secondary" :disabled="index === 0 || depth >= maxDepth - 1" title="Mettre en sous-menu" @click="emit('indent', list, index)">→</button>
        <button class="btn btn-sm btn-outline-secondary" :disabled="depth === 0" title="Remonter d’un niveau" @click="emit('outdent', list, index)">←</button>
        <button class="btn btn-sm btn-outline-primary" :disabled="!canAddChild" title="Ajouter un sous-item" @click="emit('addChild', item)">+ Sous-item</button>
        <button class="btn btn-sm btn-outline-danger" @click="emit('remove', list, index)">×</button>
      </div>
    </div>

    <div v-if="item.children.length" class="menu-builder-children">
      <MenuItemNode
        v-for="(child, childIndex) in item.children"
        :key="child.uid"
        :item="child"
        :list="item.children"
        :index="childIndex"
        :active-language="activeLanguage"
        :selected-uid="selectedUid"
        :depth="depth + 1"
        :max-depth="maxDepth"
        @select="emit('select', $event)"
        @add-child="emit('addChild', $event)"
        @remove="(childList, i) => emit('remove', childList, i)"
        @move="(childList, i, direction) => emit('move', childList, i, direction)"
        @indent="(childList, i) => emit('indent', childList, i)"
        @outdent="(childList, i) => emit('outdent', childList, i)"
      />
    </div>
  </div>
</template>

<style scoped>
.menu-builder-node { margin-bottom: .5rem; }
.menu-builder-children { margin-left: 1.25rem; padding-left: .75rem; border-left: 1px dashed var(--bs-border-color); }
.menu-builder-row { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .65rem .75rem; border: 1px solid var(--bs-border-color); border-radius: .75rem; background: var(--bs-body-bg); cursor: pointer; }
.menu-builder-row:hover, .menu-builder-node.is-selected > .menu-builder-row { border-color: var(--bs-primary); box-shadow: 0 0 0 .15rem rgba(var(--bs-primary-rgb), .12); }
.menu-builder-node.is-muted > .menu-builder-row { opacity: .62; }
.menu-builder-row__main { min-width: 0; display: inline-flex; align-items: center; gap: .5rem; flex: 1 1 auto; }
.menu-builder-row__label { overflow-wrap: anywhere; }
.menu-builder-row__tools { flex: 0 0 auto; }
@media (max-width: 575.98px) {
  .menu-builder-row { gap: .5rem; }
  .menu-builder-row__tools { flex-wrap: wrap; justify-content: flex-start; }
  .menu-builder-children { margin-left: .5rem; }
}
</style>
