<script setup lang="ts">
import { computed } from 'vue';
import type { TaxonomyPolicyItem, TaxonomyTermOption } from '@/api/contracts';
import InfoHint from '@/components/ui/InfoHint.vue';

defineOptions({ name: 'TaxonomyTermSelector' });

const props = defineProps<{
  taxonomies: TaxonomyPolicyItem[];
  modelValue: Record<string, number[]>;
  languageCode?: string;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: Record<string, number[]>] }>();

const visibleTaxonomies = computed(() => props.taxonomies.filter((taxonomy) => taxonomy.terms.length > 0));
const emptyTaxonomies = computed(() => props.taxonomies.filter((taxonomy) => taxonomy.terms.length === 0));

function selectedIds(taxonomyKey: string): number[] {
  return Array.isArray(props.modelValue[taxonomyKey]) ? props.modelValue[taxonomyKey] : [];
}
function termLabel(term: TaxonomyTermOption): string {
  const localized = props.languageCode ? term.localizations?.[props.languageCode] : undefined;
  return String(localized?.name || term.name || term.term_key || `#${term.id}`);
}
function termPath(term: TaxonomyTermOption): string {
  const localized = props.languageCode ? term.localizations?.[props.languageCode] : undefined;
  return String(localized?.full_path || term.full_path || term.slug || term.term_key || '');
}
function updateTaxonomy(taxonomyKey: string, ids: number[]) {
  const next: Record<string, number[]> = { ...props.modelValue };
  const clean = Array.from(new Set(ids.filter((id) => Number.isInteger(id) && id > 0)));
  if (clean.length === 0) delete next[taxonomyKey];
  else next[taxonomyKey] = clean;
  emit('update:modelValue', next);
}
function setSingle(taxonomyKey: string, raw: string | number) {
  const id = Number(raw);
  updateTaxonomy(taxonomyKey, id > 0 ? [id] : []);
}
function toggleMulti(taxonomyKey: string, termId: number, checked: boolean) {
  const ids = new Set(selectedIds(taxonomyKey));
  if (checked) ids.add(termId);
  else ids.delete(termId);
  updateTaxonomy(taxonomyKey, Array.from(ids));
}
function isSelected(taxonomyKey: string, termId: number): boolean {
  return selectedIds(taxonomyKey).includes(termId);
}
</script>

<template>
  <section class="schema-section taxonomy-selector">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
      <div>
        <p class="eyebrow mb-1">Classement éditorial</p>
        <h2>Taxonomie <InfoHint text="Choisissez une catégorie principale et plusieurs tags selon les vocabulaires autorisés pour ce type de contenu." placement="end" /></h2>
      </div>
      <span class="badge text-bg-light">{{ languageCode || 'langue courante' }}</span>
    </div>

    <div v-if="taxonomies.length === 0" class="alert alert-secondary mb-0">
      Aucune taxonomie n’est encore autorisée pour ce type de contenu.
    </div>

    <div v-else class="row g-3">
      <div v-for="taxonomy in visibleTaxonomies" :key="taxonomy.taxonomy_key" class="col-12 col-xl-6">
        <div class="card h-100 taxonomy-picker-card">
          <div class="card-header d-flex justify-content-between align-items-start gap-2">
            <div>
              <h3 class="h6 mb-1">{{ taxonomy.name || taxonomy.taxonomy_key }}</h3>
              <small class="text-muted"><code>{{ taxonomy.taxonomy_key }}</code></small>
            </div>
            <div class="d-flex gap-1 flex-wrap justify-content-end">
              <span v-if="taxonomy.is_required" class="badge text-bg-warning">Obligatoire</span>
              <span class="badge text-bg-light">{{ taxonomy.max_terms === 1 ? 'Catégorie unique' : 'Tags multiples' }}</span>
            </div>
          </div>

          <div class="card-body">
            <label v-if="taxonomy.max_terms === 1" class="form-field">
              Catégorie sélectionnée
              <select class="form-select" :value="selectedIds(taxonomy.taxonomy_key)[0] || ''" @change="setSingle(taxonomy.taxonomy_key, ($event.target as HTMLSelectElement).value)">
                <option value="">Aucun terme</option>
                <option v-for="term in taxonomy.terms" :key="term.id" :value="term.id">
                  {{ termLabel(term) }}
                </option>
              </select>
            </label>

            <div v-else class="taxonomy-checkbox-list">
              <label v-for="term in taxonomy.terms" :key="term.id" class="form-check taxonomy-check-item">
                <input class="form-check-input" type="checkbox" :checked="isSelected(taxonomy.taxonomy_key, term.id)" @change="toggleMulti(taxonomy.taxonomy_key, term.id, ($event.target as HTMLInputElement).checked)" />
                <span class="form-check-label">
                  <strong>{{ termLabel(term) }}</strong>
                  <small v-if="termPath(term)" class="d-block text-muted"><code>{{ termPath(term) }}</code></small>
                </span>
              </label>
            </div>
          </div>

          <div v-if="selectedIds(taxonomy.taxonomy_key).length" class="card-footer d-flex gap-2 flex-wrap">
            <span v-for="termId in selectedIds(taxonomy.taxonomy_key)" :key="termId" class="badge rounded-pill text-bg-primary">
              {{ termLabel(taxonomy.terms.find((term) => term.id === termId) || { id: termId, term_key: String(termId), name: `#${termId}`, slug: '', full_path: '', sort_order: 0, localizations: {} }) }}
            </span>
          </div>
        </div>
      </div>
    </div>

    <div v-if="emptyTaxonomies.length" class="alert alert-warning mt-3 mb-0">
      {{ emptyTaxonomies.length }} taxonomie(s) sont autorisées mais ne contiennent aucun terme actif. Ajoutez des termes dans l’écran Taxonomies.
    </div>
  </section>
</template>
