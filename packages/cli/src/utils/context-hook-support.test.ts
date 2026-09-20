import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import {
  checkInstalledPackage,
  compareVersions,
  evaluateContextHookSupport,
} from './context-hook-support';

async function writePackage(
  root: string,
  version: string,
  declarations = 'export declare function usePageContext(): unknown;\nexport declare function useSiteContext(): unknown;\n',
): Promise<void> {
  const dir = path.join(root, 'node_modules', 'drupal-canvas');
  await fs.mkdir(path.join(dir, 'dist'), { recursive: true });
  await fs.writeFile(
    path.join(dir, 'package.json'),
    JSON.stringify({
      name: 'drupal-canvas',
      version,
      exports: {
        '.': { types: './dist/index.d.ts', import: './dist/index.js' },
      },
    }),
  );
  await fs.writeFile(path.join(dir, 'dist', 'index.d.ts'), declarations);
  await fs.writeFile(path.join(dir, 'dist', 'index.js'), 'export {};');
}

describe('context hook support', () => {
  let root: string;

  beforeEach(async () => {
    root = await fs.mkdtemp(path.join(os.tmpdir(), 'context-hooks-'));
    await fs.writeFile(path.join(root, 'package.json'), '{"name":"app"}');
  });

  afterEach(async () => {
    await fs.rm(root, { recursive: true, force: true });
  });

  it('compares versions numerically', () => {
    expect(compareVersions('0.6.0', '0.6.0')).toBe(0);
    expect(compareVersions('0.5.1', '0.6.0')).toBeLessThan(0);
    expect(compareVersions('1.0.0-beta.1', '0.6.0')).toBeGreaterThan(0);
    expect(compareVersions('0.10.0', '0.6.0')).toBeGreaterThan(0);
  });

  it('requires the installed package to export both hooks', async () => {
    expect((await checkInstalledPackage(root)).ok).toBe(false);
    await writePackage(root, '0.5.1');
    expect((await checkInstalledPackage(root)).reason).toContain('0.5.1');
    await writePackage(root, '0.6.0', 'export declare const cn: unknown;');
    expect((await checkInstalledPackage(root)).reason).toContain(
      'does not export',
    );
    await writePackage(root, '0.6.0');
    expect(await checkInstalledPackage(root)).toEqual({ ok: true });
  });

  it('requires the site to advertise context hooks', async () => {
    await writePackage(root, '0.6.0');
    const supported = await evaluateContextHookSupport({
      siteUrl: 'https://drupal.example',
      projectRoot: root,
      fetchSiteData: async () => ({ capabilities: { contextHooks: true } }),
    });
    expect(supported).toEqual({ supported: true, reasons: [] });

    const older = await evaluateContextHookSupport({
      siteUrl: 'https://drupal.example',
      projectRoot: root,
      fetchSiteData: async () => ({ baseUrl: 'https://drupal.example' }),
    });
    expect(older.supported).toBe(false);
    expect(older.reasons[0]).toContain('does not advertise');

    const unreachable = await evaluateContextHookSupport({
      siteUrl: 'https://drupal.example',
      projectRoot: root,
      fetchSiteData: async () => {
        throw new Error('ECONNREFUSED');
      },
    });
    expect(unreachable.supported).toBe(false);
    expect(unreachable.reasons[0]).toContain('could not be verified');
  });

  it('reports both limitations when both gates fail', async () => {
    const result = await evaluateContextHookSupport({
      siteUrl: 'https://drupal.example',
      projectRoot: root,
      fetchSiteData: async () => ({}),
    });
    expect(result.supported).toBe(false);
    expect(result.reasons).toHaveLength(2);
  });
});
