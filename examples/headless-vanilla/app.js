import { AmCmsApiError, createAmCmsClient } from '../../packages/amcms-client/dist/index.js';

const client = createAmCmsClient({
  baseUrl: 'https://example.com/cms/site_a',
  token: '',
  site: 'site_a',
  lang: 'fr'
});

function render(id, payload) {
  document.getElementById(id).textContent = JSON.stringify(payload, null, 2);
}

function renderError(id, error) {
  const element = document.getElementById(id);
  element.classList.add('error');

  if (error instanceof AmCmsApiError) {
    element.textContent = JSON.stringify({
      status: error.status,
      code: error.code,
      message: error.message,
      details: error.details,
      requestId: error.requestId
    }, null, 2);
    return;
  }

  element.textContent = error instanceof Error ? error.message : String(error);
}

async function loadSection(id, loader) {
  try {
    const payload = await loader();
    render(id, payload);
  } catch (error) {
    renderError(id, error);
  }
}

await Promise.all([
  loadSection('route', () => client.getRoute('/')),
  loadSection('menu', () => client.getMenu('primary')),
  loadSection('articles', () => client.getContent('article', { limit: 5 })),
  loadSection('search', () => client.search('test', { limit: 5 }))
]);
