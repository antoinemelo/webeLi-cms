<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';

export type ModuleNavigationItem = {
  key: string;
  label: string;
  route: string;
  visible?: boolean;
};

const props = defineProps<{
  label: string;
  items: ModuleNavigationItem[];
  variant?: 'primary' | 'compact';
}>();

const route = useRoute();
const router = useRouter();
const visibleItems = computed(() => props.items.filter((item) => item.visible !== false));
const currentRoute = computed(() => {
  const exact = visibleItems.value.find((item) => item.route === route.path);
  if (exact) return exact.route;
  return [...visibleItems.value]
    .filter((item) => item.route !== '/' && route.path.startsWith(`${item.route}/`))
    .sort((left, right) => right.route.length - left.route.length)[0]?.route
    ?? visibleItems.value[0]?.route
    ?? '';
});

function navigate(event: Event): void {
  const target = event.target as HTMLSelectElement;
  if (target.value && target.value !== route.path) void router.push(target.value);
}
</script>

<template>
  <nav class="module-secondary-navigation" :class="`module-secondary-navigation--${variant || 'primary'}`" :aria-label="label">
    <div class="module-secondary-navigation__links">
      <RouterLink
        v-for="item in visibleItems"
        :key="item.key"
        :to="item.route"
        class="module-secondary-navigation__link"
        :aria-current="currentRoute === item.route ? 'page' : undefined"
      >
        {{ item.label }}
      </RouterLink>
    </div>
    <label class="module-secondary-navigation__select-label">
      <span>{{ label }}</span>
      <select class="form-select" :value="currentRoute" @change="navigate">
        <option v-for="item in visibleItems" :key="item.key" :value="item.route">{{ item.label }}</option>
      </select>
    </label>
  </nav>
</template>

<style scoped>
.module-secondary-navigation {
  margin-bottom: 1rem;
}
.module-secondary-navigation__links {
  display: flex;
  gap: .25rem;
  overflow-x: auto;
  padding: 0 .1rem;
  scrollbar-width: thin;
}
.module-secondary-navigation__link {
  color: #475569;
  font-size: .9rem;
  font-weight: 800;
  text-decoration: none;
  white-space: nowrap;
}
.module-secondary-navigation--primary {
  background: rgba(248, 250, 252, .96);
  border: 1px solid #dbe3ef;
  border-radius: 1rem;
  box-shadow: 0 10px 28px rgba(15, 23, 42, .04);
  padding: .35rem;
}
.module-secondary-navigation--primary .module-secondary-navigation__link {
  border-radius: .75rem;
  padding: .7rem 1rem;
}
.module-secondary-navigation--primary .module-secondary-navigation__link:hover,
.module-secondary-navigation--primary .module-secondary-navigation__link:focus-visible {
  background: #eef5ff;
  color: #123b72;
}
.module-secondary-navigation--primary .module-secondary-navigation__link[aria-current='page'] {
  background: #fff;
  box-shadow: 0 8px 22px rgba(15, 23, 42, .08);
  color: #0f172a;
}
.module-secondary-navigation--compact {
  border-bottom: 1px solid #d8dee8;
}
.module-secondary-navigation--compact .module-secondary-navigation__link {
  border-bottom: 2px solid transparent;
  font-weight: 600;
  padding: .65rem .75rem .55rem;
}
.module-secondary-navigation__link:hover,
.module-secondary-navigation__link:focus-visible {
  color: #0f172a;
}
.module-secondary-navigation--compact .module-secondary-navigation__link[aria-current='page'] {
  border-bottom-color: #2563eb;
  color: #1d4ed8;
}
.module-secondary-navigation__select-label { display: none; }
@media (max-width: 680px) {
  .module-secondary-navigation { border: 0; box-shadow: none; padding: 0; }
  .module-secondary-navigation__links { display: none; }
  .module-secondary-navigation__select-label { display: grid; gap: .35rem; }
  .module-secondary-navigation__select-label span { font-size: .82rem; font-weight: 600; }
}
</style>
