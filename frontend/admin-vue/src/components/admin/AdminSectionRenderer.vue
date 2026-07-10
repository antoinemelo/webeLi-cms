<script setup lang="ts">
import type { AdminSection } from '@/admin/adminSections';
import InfoHint from '@/components/ui/InfoHint.vue';
import { canShow } from '@/router/navigation';
import { useAdminContextStore } from '@/stores/adminContext';

const props = defineProps<{ section: AdminSection }>();
const context = useAdminContextStore();

function blockEyebrow(type: AdminSection['blocks'][number]['type']): string {
  return type === 'quick_actions' ? 'TABLEAU DE BORD' : 'STUDIO';
}
</script>

<template>
  <section class="admin-section">
    <div v-for="block in props.section.blocks" :key="block.key" class="section-block card">
      <div class="section-block-head">
        <div>
          <p class="eyebrow">{{ blockEyebrow(block.type) }}</p>
          <h2 class="section-block-title">
            <span>{{ block.title }}</span>
            <InfoHint v-if="block.description" :text="block.description" placement="end" />
          </h2>
        </div>
      </div>

      <div class="section-card-grid">
        <RouterLink
          v-for="item in block.items.filter((entry) => canShow(entry.permission, context.can, entry.anyPermission))"
          :key="`${item.route}-${item.label}`"
          class="section-card"
          :class="{ disabled: item.disabled }"
          :to="item.disabled ? '#' : item.route"
          :aria-disabled="item.disabled ? 'true' : 'false'"
        >
          <span class="section-card-icon">{{ item.label.slice(0, 1).toUpperCase() }}</span>
          <span>
            <strong>{{ item.label }}</strong>
            <small>{{ item.hint || 'Ouvrir la section' }}</small>
          </span>
          <em v-if="item.disabled">bientôt</em>
        </RouterLink>
      </div>
    </div>
  </section>
</template>
