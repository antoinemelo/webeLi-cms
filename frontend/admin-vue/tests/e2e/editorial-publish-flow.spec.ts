import { test, expect, type APIResponse, type Page } from '@playwright/test';

const baseUrl = process.env.E2E_BASE_URL;
const email = process.env.E2E_ADMIN_EMAIL;
const password = process.env.E2E_ADMIN_PASSWORD;
const hasDedicatedEnvironment = Boolean(baseUrl && email && password);

type ApiEnvelope<TData = Record<string, unknown>> = {
  data: TData;
  meta: { contract: string; [key: string]: unknown };
};

type AdminContextData = {
  csrf_token: string;
  site: { id: number; default_language_code?: string };
  current_content_language_code?: string;
  current_language_code?: string;
};

type SaveDraftData = {
  entry_id: number;
  revision_id: number;
  content_type_key: string;
  language_code: string;
  status: string;
};

type PublishData = {
  entry_id: number;
  language_code: string;
  published_revision_id: number;
  status: string;
  workflow_state: string;
};

type TaxonomyTerm = {
  id: number;
  taxonomy_key: string;
  term_key?: string;
  slug?: string;
};

function cmsPath(path: string): string {
  if (!baseUrl) return path;
  const configured = new URL(baseUrl);
  const prefix = configured.pathname.replace(/\/+$/, '');
  return `${prefix}/${path.replace(/^\/+/, '')}`.replace(/\/{2,}/g, '/');
}

async function signIn(page: Page): Promise<void> {
  await page.goto(cmsPath('/admin/login'));
  await page.getByLabel('Email').fill(email!);
  await page.getByRole('button', { name: /continuer/i }).click();
  await page.locator('input[name="password"]').fill(password!);
  await page.getByRole('button', { name: /se connecter/i }).click();
  await expect(page).toHaveURL(/\/admin\/app(?:\/|$)/);
}

async function jsonEnvelope<TData>(response: APIResponse, expectedContract: string, label: string): Promise<ApiEnvelope<TData>> {
  const text = await response.text();
  expect(response.status(), `${label} returned HTTP 500:\n${text}`).not.toBe(500);
  expect(response.ok(), `${label} failed with HTTP ${response.status()}:\n${text}`).toBeTruthy();
  const payload = JSON.parse(text) as ApiEnvelope<TData>;
  expect(payload.meta.contract, `${label} contract`).toBe(expectedContract);
  return payload;
}

function adminHeaders(csrfToken: string): Record<string, string> {
  return {
    'Content-Type': 'application/json',
    'X-Contract-Version': 'admin-api-v1',
    'X-CSRF-Token': csrfToken,
  };
}

function uniqueSuffix(kind: string): string {
  return `${kind}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
}

async function publishEditorialEntry(
  page: Page,
  context: AdminContextData,
  contentTypeKey: 'page' | 'article',
): Promise<{ title: string; body: string; slug: string; publicPath: string; entryId: number; revisionId: number }> {
  const siteId = context.site.id;
  const languageCode = context.current_content_language_code ?? context.current_language_code ?? context.site.default_language_code ?? 'fr';
  const suffix = uniqueSuffix(contentTypeKey);
  const title = `P1-04 ${contentTypeKey} ${suffix}`;
  const body = `Contenu éditorial E2E ${suffix}`;
  const slug = `p1-04-${suffix}`;
  const publicPath = contentTypeKey === 'page' ? `/${slug}` : `/articles/${slug}`;
  const categoryTermId = contentTypeKey === 'article'
    ? await firstCategoryTermId(page, siteId, languageCode)
    : null;

  const draftPayload = {
    data: {
      site_id: siteId,
      language_code: languageCode,
      content_type_key: contentTypeKey,
      entry_key: slug,
      title,
      slug,
      meta_title: title,
      meta_description: body,
      meta_robots: 'index,follow',
      fields: contentTypeKey === 'article' ? { author_name: 'E2E Administrator' } : {},
      taxonomy_terms: categoryTermId !== null ? { categories: [categoryTermId] } : {},
      blocks: [
        {
          type: 'markdown',
          data: { text: body },
          editorial_status: 'published',
        },
      ],
      change_notes: 'P1-04 E2E editorial flow',
    },
  };

  const draft = await jsonEnvelope<SaveDraftData>(
    await page.request.post(cmsPath('/admin/api/entries'), {
      headers: adminHeaders(context.csrf_token),
      data: draftPayload,
    }),
    'admin.entries.save_draft.v1',
    `${contentTypeKey} draft creation`,
  );
  expect(draft.data.content_type_key).toBe(contentTypeKey);
  expect(draft.data.status).toBe('draft');
  expect(draft.data.entry_id).toBeGreaterThan(0);
  expect(draft.data.revision_id).toBeGreaterThan(0);

  const entryId = draft.data.entry_id;
  const revisionId = draft.data.revision_id;
  const contextQuery = `site_id=${siteId}&language_code=${encodeURIComponent(languageCode)}`;

  const preview = await jsonEnvelope<Record<string, unknown>>(
    await page.request.get(cmsPath(`/admin/api/entries/${entryId}/preview?${contextQuery}&revision_id=${revisionId}`)),
    'admin.entries.preview.v1',
    `${contentTypeKey} preview`,
  );
  expect(String(preview.data.preview_url ?? '')).toContain(`/preview/${entryId}`);

  const revisions = await jsonEnvelope<{ revisions: Array<{ id: number }> }>(
    await page.request.get(cmsPath(`/admin/api/entries/${entryId}/revisions?${contextQuery}`)),
    'admin.entries.revisions.index.v1',
    `${contentTypeKey} revisions`,
  );
  expect(revisions.data.revisions.some((revision) => revision.id === revisionId)).toBeTruthy();

  const publish = await jsonEnvelope<PublishData>(
    await page.request.post(cmsPath(`/admin/api/entries/${entryId}/publish`), {
      headers: adminHeaders(context.csrf_token),
      data: {
        data: {
          site_id: siteId,
          language_code: languageCode,
          revision_id: revisionId,
          expected_working_revision_id: revisionId,
        },
      },
    }),
    'admin.entries.publish.v1',
    `${contentTypeKey} publication`,
  );
  expect(publish.data.status).toBe('published');
  expect(publish.data.workflow_state).toBe('published');
  expect(publish.data.published_revision_id).toBe(revisionId);

  const show = await jsonEnvelope<{ published_revision: { id: number } | null; routes: Array<{ full_path: string; status: string }> }>(
    await page.request.get(cmsPath(`/admin/api/entries/${entryId}?${contextQuery}`)),
    'admin.entries.show.v1',
    `${contentTypeKey} published entry`,
  );
  expect(show.data.published_revision?.id).toBe(revisionId);
  expect(show.data.routes.some((route) => route.full_path === publicPath && route.status === 'active')).toBeTruthy();

  const ssr = await page.request.get(cmsPath(`${publicPath}?lang=${encodeURIComponent(languageCode)}`));
  const ssrText = await ssr.text();
  expect(ssr.status(), `${contentTypeKey} SSR returned HTTP 500:\n${ssrText}`).not.toBe(500);
  expect(ssr.ok(), `${contentTypeKey} SSR failed with HTTP ${ssr.status()}:\n${ssrText}`).toBeTruthy();
  expect(ssrText).toContain(title);
  expect(ssrText).toContain(body);

  const headless = await jsonEnvelope<{
    system: { content_type: string; status: string };
    editorial: { title: string; slug: string; path: string };
  }>(
    await page.request.get(cmsPath(`/api/v1/content-by-path?path=${encodeURIComponent(publicPath)}&lang=${encodeURIComponent(languageCode)}`)),
    'public.content.by_path.v1',
    `${contentTypeKey} headless by path`,
  );
  expect(headless.data.system.content_type).toBe(contentTypeKey);
  expect(headless.data.system.status).toBe('published');
  expect(headless.data.editorial.title).toBe(title);
  expect(headless.data.editorial.slug).toBe(slug);
  expect(headless.data.editorial.path).toBe(publicPath);

  return { title, body, slug, publicPath, entryId, revisionId };
}

async function firstCategoryTermId(page: Page, siteId: number, languageCode: string): Promise<number> {
  const terms = await jsonEnvelope<TaxonomyTerm[]>(
    await page.request.get(cmsPath(`/admin/api/taxonomy-terms?site_id=${siteId}&lang=${encodeURIComponent(languageCode)}&taxonomy=categories`)),
    'admin.taxonomies.terms.v1',
    'article category terms',
  );
  const category = terms.data.find((term) => term.taxonomy_key === 'categories' && term.id > 0);
  expect(category?.id, 'Native article fixtures must provide at least one categories term.').toBeGreaterThan(0);
  return category!.id;
}

test.describe('editorial publication flow', () => {
  test.skip(!hasDedicatedEnvironment, 'Dedicated E2E_BASE_URL, E2E_ADMIN_EMAIL and E2E_ADMIN_PASSWORD are required');
  test.setTimeout(120_000);

  test.beforeEach(async ({ page }) => {
    await signIn(page);
  });

  test('creates, previews, publishes and reads a page through SSR and headless API', async ({ page }) => {
    const context = await jsonEnvelope<AdminContextData>(
      await page.request.get(cmsPath('/admin/api/context')),
      'admin.context.v1',
      'admin context',
    );

    await publishEditorialEntry(page, context.data, 'page');
  });

  test('creates, previews, publishes and reads an article through SSR and headless API', async ({ page }) => {
    const context = await jsonEnvelope<AdminContextData>(
      await page.request.get(cmsPath('/admin/api/context')),
      'admin.context.v1',
      'admin context',
    );

    await publishEditorialEntry(page, context.data, 'article');
  });
});
