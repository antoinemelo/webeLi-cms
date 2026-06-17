import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL, fileURLToPath } from 'node:url';

const projectRoot = path.resolve(fileURLToPath(new URL('../..', import.meta.url)));
const adminRoot = path.resolve(fileURLToPath(new URL('.', import.meta.url)));

function readOpsEnv(): Record<string, string> {
  const envPath = path.join(projectRoot, 'ops', '.env');
  if (!fs.existsSync(envPath)) return {};

  return Object.fromEntries(
    fs.readFileSync(envPath, 'utf8')
      .split(/\r?\n/)
      .map((line) => line.trim())
      .filter((line) => line && !line.startsWith('#') && line.includes('='))
      .map((line) => {
        const [key, ...rest] = line.split('=');
        return [key.trim(), rest.join('=').trim().replace(/^['"]|['"]$/g, '')];
      })
      .filter(([key]) => key)
  );
}

function resolveProjectPath(value: string): string {
  const normalized = value || './vendor/node_modules/';
  return path.isAbsolute(normalized)
    ? normalized
    : path.resolve(projectRoot, normalized.replace(/^\.\//, ''));
}

const opsEnv = readOpsEnv();
const nodeModulesPath = resolveProjectPath(
  opsEnv.APP_VUE_NODE_MODULES_PATH || opsEnv.APP_NODE_MODULES_PATH || './vendor/node_modules/'
);

function packageRoot(packageName: string): string {
  return path.join(nodeModulesPath, ...packageName.split('/'));
}

async function importPackage(packageName: string) {
  const root = packageRoot(packageName);
  const packageJson = path.join(root, 'package.json');
  if (!fs.existsSync(packageJson)) {
    return import(packageName);
  }
  const metadata = JSON.parse(fs.readFileSync(packageJson, 'utf8')) as { module?: string; main?: string; exports?: unknown };
  const entry = typeof metadata.module === 'string'
    ? metadata.module
    : typeof metadata.main === 'string'
      ? metadata.main
      : 'dist/index.mjs';
  return import(pathToFileURL(path.join(root, entry)).href);
}

const vuePluginModule = await importPackage('@vitejs/plugin-vue');
const vue = vuePluginModule.default;

const packageJson = JSON.parse(fs.readFileSync(path.join(adminRoot, 'package.json'), 'utf8')) as {
  dependencies?: Record<string, string>;
  devDependencies?: Record<string, string>;
};
const packageAliases = Object.fromEntries(
  Object.keys({ ...(packageJson.dependencies || {}), ...(packageJson.devDependencies || {}) })
    .map((packageName) => [packageName, packageRoot(packageName)])
    .filter(([, packagePath]) => fs.existsSync(packagePath))
);

export default {
  plugins: [vue()],
  base: './',
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
      ...packageAliases
    },
    dedupe: ['vue']
  },
  build: {
    outDir: '../../admin-app',
    emptyOutDir: true,
    sourcemap: false,
    manifest: true
  },
  server: {
    proxy: {
      '/admin/api': 'http://127.0.0.1:8080'
    }
  }
};
