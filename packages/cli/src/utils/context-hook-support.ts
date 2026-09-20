/**
 * @file
 * The capability gates for the pull codemod: the connected Drupal site must
 * advertise context-hook support, and the project's installed `drupal-canvas`
 * package must export both hooks. When either cannot be verified, getter
 * calls stay unchanged and the limitation is reported.
 */

import fs from 'node:fs/promises';
import { createRequire } from 'node:module';
import path from 'node:path';
import axios from 'axios';

export interface ContextHookSupport {
  /** Whether both gates passed. */
  supported: boolean;
  /** Why conversion is disabled, one entry per failed gate. */
  reasons: string[];
}

export interface ContextHookSupportOptions {
  siteUrl: string;
  projectRoot: string;
  /** Injectable for tests. */
  fetchSiteData?: (siteUrl: string) => Promise<unknown>;
}

async function fetchSiteData(siteUrl: string): Promise<unknown> {
  const response = await axios.get(
    `${siteUrl.replace(/\/+$/, '')}/canvas/api/v0/site-data`,
    {
      headers: { Accept: 'application/json', 'X-Canvas-CLI': '1' },
      timeout: 10000,
    },
  );
  return response.data;
}

async function readManifestName(candidate: string): Promise<string | null> {
  try {
    const manifest = JSON.parse(await fs.readFile(candidate, 'utf-8')) as {
      name?: string;
    };
    return manifest.name ?? null;
  } catch {
    return null;
  }
}

/**
 * Locates the `package.json` of the `drupal-canvas` package a project
 * resolves: through Node's resolution first (the exports map hides
 * `package.json`, so the entry is resolved and the package root found from
 * it), then by walking the project's `node_modules` ancestors.
 */
async function locatePackageJson(projectRoot: string): Promise<string | null> {
  try {
    const require = createRequire(path.join(projectRoot, 'package.json'));
    let directory = path.dirname(require.resolve('drupal-canvas'));
    while (directory !== path.dirname(directory)) {
      const candidate = path.join(directory, 'package.json');
      if ((await readManifestName(candidate)) === 'drupal-canvas') {
        return candidate;
      }
      directory = path.dirname(directory);
    }
  } catch {
    // Fall through to the directory walk (e.g. an exports map without a
    // condition Node's CommonJS resolver accepts).
  }
  let directory = path.resolve(projectRoot);
  while (true) {
    const candidate = path.join(
      directory,
      'node_modules',
      'drupal-canvas',
      'package.json',
    );
    if ((await readManifestName(candidate)) === 'drupal-canvas') {
      return candidate;
    }
    const parent = path.dirname(directory);
    if (parent === directory) {
      return null;
    }
    directory = parent;
  }
}

/**
 * Whether a declaration file exports `name`: on an `export` statement (a
 * direct `export declare function name` or an `export { ..., name, ... }`
 * list), not merely mentioned somewhere in the file.
 */
export function declaresExport(declarations: string, name: string): boolean {
  const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return new RegExp(
    `^export\\b(?:[^\\n{]*\\b${escaped}\\b|\\s*(?:type\\s+)?\\{[^}]*(?:^|[\\s,])${escaped}\\s*(?:,|\\}))`,
    'm',
  ).test(declarations);
}

/**
 * Checks whether the installed `drupal-canvas` package of a project exports
 * the context hooks, whatever version it reports.
 */
export async function checkInstalledPackage(
  projectRoot: string,
): Promise<{ ok: boolean; reason?: string }> {
  const packageJsonPath = await locatePackageJson(projectRoot);
  if (packageJsonPath === null) {
    return {
      ok: false,
      reason:
        'the `drupal-canvas` package is not installed in this project (run `npm install drupal-canvas@latest`)',
    };
  }
  let typesEntry = '';
  try {
    const manifest = JSON.parse(
      await fs.readFile(packageJsonPath, 'utf-8'),
    ) as {
      exports?: Record<string, { types?: string } | string>;
    };
    const rootExport = manifest.exports?.['.'];
    typesEntry =
      typeof rootExport === 'object' && rootExport?.types
        ? rootExport.types
        : 'dist/index.d.ts';
  } catch {
    return {
      ok: false,
      reason: 'the installed `drupal-canvas` package could not be read',
    };
  }
  // The capability is what the installed package exports, whatever its
  // version says: the root entry's declarations must export both hooks.
  try {
    const declarations = await fs.readFile(
      path.join(path.dirname(packageJsonPath), typesEntry),
      'utf-8',
    );
    if (
      !declaresExport(declarations, 'usePageContext') ||
      !declaresExport(declarations, 'useSiteContext')
    ) {
      return {
        ok: false,
        reason:
          'the installed `drupal-canvas` package does not export `usePageContext()` and `useSiteContext()` (run `npm install drupal-canvas@latest`)',
      };
    }
  } catch {
    return {
      ok: false,
      reason:
        'the installed `drupal-canvas` package exports could not be verified',
    };
  }
  return { ok: true };
}

/**
 * Evaluates both capability gates.
 */
export async function evaluateContextHookSupport(
  options: ContextHookSupportOptions,
): Promise<ContextHookSupport> {
  const reasons: string[] = [];
  try {
    const data = (await (options.fetchSiteData ?? fetchSiteData)(
      options.siteUrl,
    )) as { capabilities?: { contextHooks?: unknown } } | null;
    if (data?.capabilities?.contextHooks !== true) {
      reasons.push(
        'the site does not advertise context-hook support at /canvas/api/v0/site-data (update the Drupal Canvas module)',
      );
    }
  } catch (error) {
    reasons.push(
      `context-hook support could not be verified at /canvas/api/v0/site-data (${
        error instanceof Error ? error.message : String(error)
      })`,
    );
  }
  const installed = await checkInstalledPackage(options.projectRoot);
  if (!installed.ok && installed.reason) {
    reasons.push(installed.reason);
  }
  return { supported: reasons.length === 0, reasons };
}
