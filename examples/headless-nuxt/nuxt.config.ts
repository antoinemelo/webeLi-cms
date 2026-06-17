export default defineNuxtConfig({
  devtools: { enabled: false },
  runtimeConfig: {
    amcmsToken: process.env.AMCMS_TOKEN || '',
    public: {
      amcmsBaseUrl: process.env.AMCMS_BASE_URL || '',
      amcmsSite: process.env.AMCMS_SITE || '',
      amcmsLang: process.env.AMCMS_LANG || '',
    },
  },
});
