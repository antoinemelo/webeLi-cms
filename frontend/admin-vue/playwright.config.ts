import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir: './tests/e2e',
  // The release profile runs the isolated PHP server with one browser worker.
  // Under sustained load, a valid API round-trip can exceed Playwright's
  // defaults; keep assertions strict while giving the full stack time to settle.
  timeout: 120_000,
  expect: { timeout: 60_000 },
  fullyParallel: false,
  workers: 1,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  outputDir: 'test-results/artifacts',
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8080',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
  reporter: [['list'], ['junit', { outputFile: 'test-results/e2e-junit.xml' }]],
});
