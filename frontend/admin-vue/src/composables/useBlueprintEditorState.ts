import { computed, ref, type Ref } from 'vue';

export function useBlueprintEditorState<TBlueprint, TFieldset>(
  blueprint: Ref<TBlueprint>,
  fieldset: Ref<TFieldset|null>,
  isNewBlueprint: Ref<boolean>,
  isNewFieldset: Ref<boolean>
) {
  const blueprintBaseline = ref('');
  const fieldsetBaseline = ref('');
  const serialize = (value: unknown): string => JSON.stringify(value);
  const blueprintDirty = computed(() => isNewBlueprint.value || (blueprintBaseline.value !== '' && serialize(blueprint.value) !== blueprintBaseline.value));
  const fieldsetDirty = computed(() => Boolean(fieldset.value) && (isNewFieldset.value || (fieldsetBaseline.value !== '' && serialize(fieldset.value) !== fieldsetBaseline.value)));
  const hasUnsavedChanges = computed(() => blueprintDirty.value || fieldsetDirty.value);

  function markBlueprintLoaded(value: TBlueprint): void { blueprintBaseline.value = serialize(value); }
  function markFieldsetLoaded(value: TFieldset): void { fieldsetBaseline.value = serialize(value); }
  function resetBlueprint(): void { blueprintBaseline.value = ''; }
  function resetFieldset(): void { fieldsetBaseline.value = ''; }

  return { blueprintBaseline, fieldsetBaseline, blueprintDirty, fieldsetDirty, hasUnsavedChanges, markBlueprintLoaded, markFieldsetLoaded, resetBlueprint, resetFieldset };
}
