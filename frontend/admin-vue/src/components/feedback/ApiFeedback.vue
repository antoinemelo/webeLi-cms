<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps<{ error?: string; message?: string; success?: string; nonce?: number }>();
const visible = ref(false);
let timer: number | undefined;

const feedback = computed(() => {
  const text = props.error || props.message || props.success || '';
  const isError = Boolean(props.error);
  return {
    text,
    kindClass: isError ? 'text-bg-danger' : 'text-bg-success',
    title: isError ? 'Erreur' : 'Succès',
    icon: isError ? '!' : '✓'
  };
});

let generation = 0;

watch(
  () => [feedback.value.text, Boolean(props.error), props.nonce ?? 0] as const,
  async ([text, isError]) => {
    generation += 1;
    const currentGeneration = generation;
    if (timer) window.clearTimeout(timer);

    if (!text) {
      visible.value = false;
      return;
    }

    // Relance volontairement l’affichage, même si le texte est identique au précédent.
    // Les erreurs répétées de l’éditeur visuel doivent produire un nouveau toast immédiatement,
    // sans attendre un changement d’onglet ou une autre mutation de l’état global.
    visible.value = false;
    await nextTick();
    if (currentGeneration !== generation) return;

    visible.value = true;
    timer = window.setTimeout(() => {
      if (currentGeneration === generation) visible.value = false;
    }, isError ? 7000 : 3600);
  },
  { immediate: true }
);
</script>

<template>
  <Teleport to="body">
    <Transition name="toast-slide">
      <div v-if="visible && feedback.text" class="toast-container position-fixed top-0 start-50 translate-middle-x p-3 admin-toast-container">
        <div class="toast show border-0 shadow-lg admin-toast" :class="feedback.kindClass" role="status" aria-live="polite" aria-atomic="true">
          <div class="admin-toast__content">
            <span class="admin-toast__icon" aria-hidden="true">{{ feedback.icon }}</span>
            <div class="admin-toast__text">
              <strong>{{ feedback.title }}</strong>
              <span>{{ feedback.text }}</span>
            </div>
            <button type="button" class="btn-close btn-close-white admin-toast__close" aria-label="Fermer" @click="visible = false"></button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
