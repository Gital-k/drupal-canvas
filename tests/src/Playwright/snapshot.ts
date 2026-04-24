import { Drupal } from '@drupal/playwright';

import { mkdtemp, mkdir, readFile, rm, cp, copyFile } from 'node:fs/promises';
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

export async function createSnapshotState({
  baseURL,
  browser,
}: {
  baseURL?: string;
  browser: Browser;
}): Promise<SnapshotState> {
  const drupalSite = await Drupal.install();
  const rootDir = getDrupalRoot();
  const sitePath = path.join(rootDir, drupalSite.sitePath);
  const settingsPath = path.join(sitePath, 'settings.php');
  const context =
    baseURL && baseURL.length
      ? await browser.newContext({ baseURL })
      : await browser.newContext();
  const page = await context.newPage();
  const drupal = new Drupal({ page, drupalSite });

  // Ensure public:// files dir exists and is writable before site setup.
  const filesDir = path.join(sitePath, 'files');
  await mkdir(filesDir, { recursive: true });
  execSync(`chmod -R 777 "${sitePath}"`);

  await drupal.setTestCookie();
  await setupSite({ drupal });
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
  const snapshotRoot = await mkdtemp(
    path.join(os.tmpdir(), 'canvas-playwright-snapshot-'),
  );
  const snapshotSitePath = path.join(snapshotRoot, 'site');
  const snapshotDbPath = path.join(snapshotRoot, '.ht.sqlite');

  await cp(sitePath, snapshotSitePath, {
    preserveTimestamps: true,
    recursive: true,
  });
  await copyFile(sqlitePath, snapshotDbPath);

  return {
    drupalSite,
    async restore() {
      await mkdir(path.dirname(sqlitePath), { recursive: true });
      await mkdir(path.dirname(sitePath), { recursive: true });
      await rm(sitePath, { force: true, recursive: true });
      await cp(snapshotSitePath, sitePath, {
        preserveTimestamps: true,
        recursive: true,
      });
      await copyFile(snapshotDbPath, sqlitePath);
    },
    async cleanup() {
      await rm(snapshotRoot, { force: true, recursive: true });
    },
  };
}
