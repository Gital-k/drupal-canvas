import { Drupal } from '@drupal/playwright';

import { mkdir, readFile, rm, cp, copyFile, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { execSync } from 'node:child_process';
import os from 'node:os';
import path from 'node:path';

import { setupSite } from './setup.js';

import type { DrupalSite } from '@drupal/playwright';
import type { Browser } from '@playwright/test';

export interface SnapshotState {
  drupalSite: DrupalSite;
  restore: () => Promise<void>;
  cleanup: () => Promise<void>;
}

function getDrupalRoot(): string {
  let current = process.cwd();

  for (let index = 0; index < 15; index += 1) {
    const indexPhp = path.join(current, 'index.php');
    const drupalPhp = path.join(current, 'core', 'lib', 'Drupal.php');

    if (existsSync(indexPhp) && existsSync(drupalPhp)) {
      return current;
    }

    current = path.resolve(current, '..');
  }

  throw new Error('Unable to locate the Drupal root for Playwright snapshots.');
}

function getLastMatch(
  settingsContents: string,
  pattern: RegExp,
): string | undefined {
  const matches = Array.from(settingsContents.matchAll(pattern));
  return matches.at(-1)?.[1];
}

function getSqliteSettings(settingsContents: string) {
  const databasePath = getLastMatch(
    settingsContents,
    /'database' => '([^']+)'/g,
  );
  const databasePrefix = getLastMatch(
    settingsContents,
    /'prefix' => '([^']+)'/g,
  );
  const driver = getLastMatch(settingsContents, /'driver' => '([^']+)'/g);

  if (!databasePath || !databasePrefix || !driver) {
    throw new Error('Unable to parse the generated Drupal test site settings.');
  }

  if (driver !== 'sqlite') {
    throw new Error(
      `snapshotPerTest currently only supports sqlite, found ${driver}.`,
    );
  }

  return {
    databasePath,
    databasePrefix,
  };
}

// Deterministic path keyed by CI job + shard so retries (new workers) reuse the same snapshot.
function getSnapshotRoot(): string {
  const shard = (process.env.SHARD || '0-0').replace('/', '-');
  const jobId = process.env.CI_JOB_ID || 'local';
  return path.join(os.tmpdir(), `canvas-snapshot-${jobId}-${shard}`);
}

interface SnapshotMeta {
  dbPrefix: string;
  userAgent: string;
  sitePath: string;
  url: string;
  sqlitePath: string;
}

export async function createSnapshotState({
  baseURL,
  browser,
}: {
  baseURL?: string;
  browser: Browser;
}): Promise<SnapshotState> {
  const snapshotRoot = getSnapshotRoot();
  const snapshotSitePath = path.join(snapshotRoot, 'site');
  const snapshotDbPath = path.join(snapshotRoot, '.ht.sqlite');
  const cacheMetaPath = path.join(snapshotRoot, 'meta.json');
  const rootDir = getDrupalRoot();

  // Reuse snapshot from a previous attempt (Playwright retry spawns a new worker,
  // which would otherwise re-run the full Drupal install + setupSite).
  // Only cache in CI — local /tmp persists between runs causing stale-path reuse.
  if (
    process.env.CI_JOB_ID &&
    existsSync(cacheMetaPath) &&
    existsSync(snapshotSitePath) &&
    existsSync(snapshotDbPath)
  ) {
    console.log('snapshotState: reusing cached snapshot from ' + snapshotRoot);
    const meta: SnapshotMeta = JSON.parse(await readFile(cacheMetaPath, 'utf8'));
    const { sqlitePath } = meta;
    const sitePathFull = path.join(rootDir, meta.sitePath);
    const drupalSite: DrupalSite = {
      dbPrefix: meta.dbPrefix,
      userAgent: meta.userAgent,
      sitePath: meta.sitePath,
      url: meta.url,
      teardown: async () => '',
    };
    return {
      drupalSite,
      async restore() {
        await mkdir(path.dirname(sqlitePath), { recursive: true });
        await mkdir(path.dirname(sitePathFull), { recursive: true });
        await rm(sitePathFull, { force: true, recursive: true });
        await cp(snapshotSitePath, sitePathFull, {
          preserveTimestamps: false,
          recursive: true,
        });
        execSync(`chmod -R 777 "${sitePathFull}"`);
        await copyFile(snapshotDbPath, sqlitePath);
      },
      async cleanup() {
        // No-op: keep snapshot alive for further retries.
        // CI containers are ephemeral; disk is reclaimed at job end.
      },
    };
  }

  const drupalSite = await Drupal.install();
  const sitePath = path.join(rootDir, drupalSite.sitePath);
  const settingsPath = path.join(sitePath, 'settings.php');
  const context =
    baseURL && baseURL.length
      ? await browser.newContext({ baseURL })
      : await browser.newContext();
  console.log('createSnapshotState: new context created. BaseURL: ' + baseURL || 'No base URL');
  const page = await context.newPage();
  const drupal = new Drupal({ page, drupalSite });

  // Ensure public:// files dir exists and is writable before site setup.
  const filesDir = path.join(sitePath, 'files');
  await mkdir(filesDir, { recursive: true });
  execSync(`chmod -R 777 "${sitePath}"`);
  console.log('createSnapshotState: files configured in ' + sitePath);

  await drupal.setTestCookie();
  const startTime = performance.now();
  await setupSite({ drupal });
  console.log('createSnapshotState: Site configured in ' + (performance.now() - startTime));
  await page.close();
  await context.close();

  const settingsContents = await readFile(settingsPath, 'utf8');
  const { databasePath, databasePrefix } = getSqliteSettings(settingsContents);

  if (databasePrefix !== drupalSite.dbPrefix) {
    throw new Error(
      `Snapshot database prefix mismatch: expected ${drupalSite.dbPrefix}, found ${databasePrefix}.`,
    );
  }

  const sqlitePath = path.join(rootDir, databasePath);

  await mkdir(snapshotRoot, { recursive: true });
  await cp(sitePath, snapshotSitePath, {
    preserveTimestamps: false,
    recursive: true,
  });
  await copyFile(sqlitePath, snapshotDbPath);

  const meta: SnapshotMeta = {
    dbPrefix: drupalSite.dbPrefix,
    userAgent: drupalSite.userAgent,
    sitePath: drupalSite.sitePath,
    url: drupalSite.url,
    sqlitePath,
  };
  await writeFile(cacheMetaPath, JSON.stringify(meta), 'utf8');

  console.log('createSnapshotState: snapshotRoot', snapshotRoot);
  console.log('createSnapshotState: Database copied');

  return {
    drupalSite,
    async restore() {
      await mkdir(path.dirname(sqlitePath), { recursive: true });
      await mkdir(path.dirname(sitePath), { recursive: true });
      await rm(sitePath, { force: true, recursive: true });
      await cp(snapshotSitePath, sitePath, {
        preserveTimestamps: false,
        recursive: true,
      });
      execSync(`chmod -R 777 "${sitePath}"`);
      await copyFile(snapshotDbPath, sqlitePath);
    },
    async cleanup() {
      // No-op: keep snapshot alive for further retries.
      // CI containers are ephemeral; disk is reclaimed at job end.
    },
  };
}
