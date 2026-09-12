import { createAmCmsClient } from '@amcms/client';

export const amcms = createAmCmsClient({
  baseUrl: import.meta.env.AMCMS_BASE_URL ?? 'https://example.com/cms/site_a',
  token: import.meta.env.AMCMS_TOKEN || undefined,
  site: import.meta.env.AMCMS_SITE ?? 'site_a',
  lang: import.meta.env.AMCMS_LANG ?? 'fr'
});
