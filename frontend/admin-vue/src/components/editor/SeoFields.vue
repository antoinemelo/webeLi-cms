<script setup lang="ts">
import { computed } from 'vue';
import MediaPicker from '@/components/editor/MediaPicker.vue';
import InfoHint from '@/components/ui/InfoHint.vue';
import type { EditorSchema, MediaAsset, MediaVariant } from '@/api/contracts';
import { blueprintFieldHelp, isSeoEnabled } from '@/stores/contentTypes';
export type SeoModel = { meta_title: string; meta_description: string; meta_robots: string; og_image_media_id?: number; og_image_src?: string; twitter_image_media_id?: number; twitter_image_src?: string };
const props = defineProps<{ modelValue: SeoModel; schema?: EditorSchema | null; errors?: Record<string, string[]> }>();
const emit = defineEmits<{ 'update:modelValue': [value: SeoModel] }>();
const policy = computed(() => props.schema?.seo_policy ?? props.schema?.seo ?? {});
function setField(key: keyof SeoModel, value: string|number) { emit('update:modelValue', { ...props.modelValue, [key]: value }); }
function openGraphUrl(asset: MediaAsset): string {
  const preferred = asset.responsive_sets?.open_graph?.find((variant: MediaVariant) => variant.variant_key === 'og_1200x630' && variant.public_url);
  return String(preferred?.public_url || asset.public_url || '');
}
function setOgMedia(asset: MediaAsset | null) {
  if (!asset) { emit('update:modelValue', { ...props.modelValue, og_image_media_id: 0, og_image_src: '' }); return; }
  emit('update:modelValue', { ...props.modelValue, og_image_media_id: Number(asset.id), og_image_src: openGraphUrl(asset) });
}
function setTwitterMedia(asset: MediaAsset | null) {
  if (!asset) { emit('update:modelValue', { ...props.modelValue, twitter_image_media_id: 0, twitter_image_src: '' }); return; }
  emit('update:modelValue', { ...props.modelValue, twitter_image_media_id: Number(asset.id), twitter_image_src: openGraphUrl(asset) });
}
const SEO_FIELD_ALIASES: Record<string, string[]> = {
  meta_title: ['seo_title', 'meta_title'],
  meta_description: ['seo_description', 'meta_description'],
  meta_robots: ['robots', 'meta_robots', 'seo_robots'],
  og_image: ['og_image', 'open_graph_image', 'og_image_media_id', 'og_image_src'],
  twitter_image: ['twitter_image', 'twitter_image_media_id', 'twitter_image_src', 'social_image']
};
function required(key: string): boolean { const item = (policy.value as Record<string, any>)[key]; return Boolean(item?.required); }
function maxLength(key: string, fallback: number): number { const item = (policy.value as Record<string, any>)[key]; return Number(item?.max_length || fallback); }
function err(key: string): string[] { return props.errors?.[key] ?? props.errors?.[`seo.${key}`] ?? []; }
function seoHelp(key: string): string {
  return blueprintFieldHelp(props.schema || null, SEO_FIELD_ALIASES[key] || [key]);
}
</script>
<template>
  <section class="schema-section">
    <h2>SEO <InfoHint text="Les contraintes visibles viennent du seo_policy. La génération finale des balises reste validée côté PHP." placement="end" /></h2>
    <div v-if="!isSeoEnabled(schema || null)" class="card muted">Le blueprint désactive les champs SEO pour ce type de contenu.</div>
    <template v-else>
      <div class="field">
        <label for="seo-title" class="schema-label-with-hint"><span>Meta title <span v-if="required('meta_title')" class="required">*</span></span><InfoHint v-if="seoHelp('meta_title')" :text="seoHelp('meta_title')" placement="end" /></label>
        <input id="seo-title" class="input" :value="modelValue.meta_title" :maxlength="maxLength('meta_title', 70)" :required="required('meta_title')" :aria-invalid="Boolean(err('meta_title').length)" @input="setField('meta_title', ($event.target as HTMLInputElement).value)" />
        <small class="muted">{{ String(modelValue.meta_title || '').length }} / {{ maxLength('meta_title', 70) }} caractères</small>
        <small v-if="err('meta_title').length" class="field-error">{{ err('meta_title').join(' ') }}</small>
      </div>
      <div class="field">
        <label for="seo-description" class="schema-label-with-hint"><span>Meta description <span v-if="required('meta_description')" class="required">*</span></span><InfoHint v-if="seoHelp('meta_description')" :text="seoHelp('meta_description')" placement="end" /></label>
        <textarea id="seo-description" rows="3" :value="modelValue.meta_description" :maxlength="maxLength('meta_description', 170)" :required="required('meta_description')" :aria-invalid="Boolean(err('meta_description').length)" @input="setField('meta_description', ($event.target as HTMLTextAreaElement).value)" />
        <small class="muted">{{ String(modelValue.meta_description || '').length }} / {{ maxLength('meta_description', 170) }} caractères</small>
        <small v-if="err('meta_description').length" class="field-error">{{ err('meta_description').join(' ') }}</small>
      </div>
      <div class="field"><label for="seo-robots" class="schema-label-with-hint"><span>Robots</span><InfoHint v-if="seoHelp('meta_robots')" :text="seoHelp('meta_robots')" placement="end" /></label><select id="seo-robots" class="select" :value="modelValue.meta_robots" @change="setField('meta_robots', ($event.target as HTMLSelectElement).value)"><option value="index,follow">index,follow</option><option value="noindex,follow">noindex,follow</option><option value="noindex,nofollow">noindex,nofollow</option></select></div>
      <MediaPicker v-if="(policy as any).open_graph !== false" accept-type="image" label="Image Open Graph" :help="seoHelp('og_image')" preferred-set="open_graph" :media-id="Number(modelValue.og_image_media_id || 0)" :src="String(modelValue.og_image_src || '')" @update:media-id="setField('og_image_media_id', $event)" @update:src="setField('og_image_src', $event)" @select="setOgMedia" />
      <MediaPicker v-if="(policy as any).twitter !== false" accept-type="image" label="Image Twitter / fallback social" :help="seoHelp('twitter_image')" preferred-set="open_graph" :media-id="Number(modelValue.twitter_image_media_id || 0)" :src="String(modelValue.twitter_image_src || '')" @update:media-id="setField('twitter_image_media_id', $event)" @update:src="setField('twitter_image_src', $event)" @select="setTwitterMedia" />
    </template>
  </section>
</template>
