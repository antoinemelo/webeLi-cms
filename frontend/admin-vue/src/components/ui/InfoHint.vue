<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = withDefaults(defineProps<{
  text: string;
  label?: string;
  placement?: 'start' | 'end';
}>(), {
  label: 'Afficher l’information',
  placement: 'end',
});

const open = ref(false);
const root = ref<HTMLElement | null>(null);
const popoverId = computed(() => `info-hint-${Math.random().toString(36).slice(2, 10)}`);

function show() {
  open.value = true;
}

function toggle() {
  show();
}

function close() {
  open.value = false;
}

function onDocumentClick(event: MouseEvent) {
  if (!open.value) return;
  const target = event.target as Node | null;
  if (target && root.value?.contains(target)) return;
  close();
}

function onEscape(event: KeyboardEvent) {
  if (event.key === 'Escape') close();
}

onMounted(() => {
  document.addEventListener('click', onDocumentClick);
  document.addEventListener('keydown', onEscape);
});

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick);
  document.removeEventListener('keydown', onEscape);
});
</script>

<template>
  <span ref="root" class="info-hint" :class="`info-hint--${placement}`" @mouseenter="show" @mouseleave="close" @focusin="show" @focusout="close">
    <button
      type="button"
      class="info-hint__button"
      :aria-label="label"
      :aria-expanded="open"
      :aria-controls="popoverId"
      @click.stop="toggle"
    >
      <svg class="info-hint__icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
        <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14Zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16Z" />
        <path d="m8.93 6.588-2.29.287-.082.38.45.083c.294.07.352.176.288.469l-.738 3.468c-.194.897.105 1.319.808 1.319.545 0 1.178-.252 1.465-.598l.088-.416c-.2.176-.492.246-.686.246-.275 0-.375-.193-.304-.533l1.002-4.705ZM9 4.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z" />
      </svg>
    </button>
    <span v-if="open" :id="popoverId" class="info-hint__popover" role="status">
      {{ text }}
    </span>
  </span>
</template>

<style scoped>
.info-hint { position: relative; z-index: 1; display: inline-flex; align-items: center; vertical-align: middle; }
.info-hint__button { width: 1.45rem; height: 1.45rem; border: 0; border-radius: 999px; padding: 0; display: inline-grid; place-items: center; background: transparent; color: #64748b; cursor: pointer; }
.info-hint__button:hover, .info-hint__button:focus-visible { background: rgba(var(--bs-primary-rgb, 13, 110, 253), .1); color: var(--bs-primary, #0d6efd); outline: none; }
.info-hint__icon { width: 1rem; height: 1rem; fill: currentColor; }
/* invariant historique: z-index: 9000 ; le z-index effectif reste local afin de ne jamais passer au-dessus de la recherche globale. */
.info-hint__popover { position: absolute; z-index: 60; top: calc(100% + .45rem); min-width: min(18rem, calc(100vw - 2rem)); max-width: min(26rem, calc(100vw - 2rem)); padding: .7rem .8rem; border: 1px solid #dbe3ef; border-radius: .85rem; background: #fff; color: #334155; box-shadow: 0 18px 42px rgba(15, 23, 42, .14); font-size: .9rem; font-weight: 500; line-height: 1.4; text-align: left; }
.info-hint--end .info-hint__popover { left: 0; }
.info-hint--start .info-hint__popover { right: 0; }
</style>
