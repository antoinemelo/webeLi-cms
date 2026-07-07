<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

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
const button = ref<HTMLElement | null>(null);
const popoverStyle = ref<Record<string, string>>({});
const popoverId = computed(() => `info-hint-${Math.random().toString(36).slice(2, 10)}`);

function updatePosition() {
  const trigger = button.value;
  if (!trigger || typeof window === 'undefined') return;
  const rect = trigger.getBoundingClientRect();
  const viewportPadding = 16;
  const gap = 8;
  const width = Math.min(416, Math.max(240, window.innerWidth - viewportPadding * 2));
  const preferredLeft = props.placement === 'start' ? rect.right - width : rect.left;
  const left = Math.min(Math.max(preferredLeft, viewportPadding), Math.max(viewportPadding, window.innerWidth - width - viewportPadding));
  const top = Math.min(rect.bottom + gap, Math.max(viewportPadding, window.innerHeight - viewportPadding));

  popoverStyle.value = {
    left: `${left}px`,
    top: `${top}px`,
    width: `${width}px`,
  };
}

async function show() {
  open.value = true;
  await nextTick();
  updatePosition();
}

function toggle() {
  if (open.value) close();
  else void show();
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

function onViewportChange() {
  if (open.value) updatePosition();
}

onMounted(() => {
  document.addEventListener('click', onDocumentClick);
  document.addEventListener('keydown', onEscape);
  window.addEventListener('resize', onViewportChange);
  window.addEventListener('scroll', onViewportChange, true);
});

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick);
  document.removeEventListener('keydown', onEscape);
  window.removeEventListener('resize', onViewportChange);
  window.removeEventListener('scroll', onViewportChange, true);
});
</script>

<template>
  <span ref="root" class="info-hint" :class="`info-hint--${placement}`" @mouseenter="show" @mouseleave="close" @focusin="show" @focusout="close">
    <button
      ref="button"
      type="button"
      class="info-hint__button"
      :aria-label="label"
      :aria-expanded="open"
      :aria-controls="popoverId"
      @click.stop="toggle"
    >
      <svg class="info-hint__icon" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
        <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14Zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16Z" />
        <path d="m8.93 6.588-2.29.287-.082.38.45.083c.294.07.352.176.288.469l-.738 3.468c-.194.897.105 1.319.808 1.319.545 0 1.178-.252 1.465-.598l.088-.416c-.2.176-.492.246-.686.246-.275 0-.375-.193-.304-.533l1.002-4.705ZM9 4.5a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z" />
      </svg>
    </button>
    <Teleport to="body">
      <span v-if="open" :id="popoverId" class="info-hint__popover" role="status" :style="popoverStyle">
        {{ text }}
      </span>
    </Teleport>
  </span>
</template>

<style scoped>
.info-hint { position: relative; z-index: 1; display: inline-flex; align-items: center; vertical-align: middle; }
.info-hint__button { width: 1.45rem; height: 1.45rem; border: 0; border-radius: 999px; padding: 0; display: inline-grid; place-items: center; background: transparent; color: #64748b; cursor: pointer; line-height: 1; }
.info-hint__button:hover, .info-hint__button:focus-visible { background: rgba(var(--bs-primary-rgb, 13, 110, 253), .1); color: var(--bs-primary, #0d6efd); outline: none; }
.info-hint__icon { width: 1rem; height: 1rem; fill: currentColor; display: block; flex: 0 0 auto; }
.info-hint__popover { position: fixed; z-index: 2147483647; max-width: calc(100vw - 2rem); padding: .7rem .8rem; border: 1px solid #dbe3ef; border-radius: .85rem; background: #fff; color: #334155; box-shadow: 0 18px 42px rgba(15, 23, 42, .14); font-size: .9rem; font-weight: 500; line-height: 1.4; text-align: left; pointer-events: none; }
</style>
