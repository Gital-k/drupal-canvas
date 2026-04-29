import {
  Drupal,
  isolatedPerTest as isolatedPerTestBase,
  parallelWorker as parallelWorkerBase,
} from '@drupal/playwright';
import { test as base } from '@playwright/test';

import { Ai } from '../objects/Ai.js';
import { Canvas } from '../objects/Canvas.js';
import { createSnapshotState } from '../snapshot.js';
import { setupSite } from '../setup.js';

import type { DrupalSite } from '@drupal/playwright';
import type { Browser } from '@playwright/test';
import type { SnapshotState } from '../snapshot.js';

export const isolatedPerTest = isolatedPerTestBase.extend<{
  canvas: Canvas;
  ai: Ai;
  beforeEach: void;
  modules: string[];
  enableTestExtensions: boolean;
}>({
  modules: [[], { option: true }],
  enableTestExtensions: [false, { option: true }],
  beforeEach: [
    async ({ drupal, modules, enableTestExtensions }, use) => {
      await setupSite({ drupal, modules, enableTestExtensions });
      await use();
    },
    { auto: true },
  ],
  canvas: [
    async ({ drupal }, use) => {
      const canvas = new Canvas({ drupal });
      await use(canvas);
    },
    { auto: true },
  ],
  ai: [
    async ({ page }, use) => {
      const ai = new Ai({ page });
      await use(ai);
    },
    { auto: true },
  ],
});

export const parallelWorker = parallelWorkerBase.extend<
  { canvas: Canvas; ai: Ai },
  { browser: Browser; drupalSite: DrupalSite; beforeAll: void }
>({
  beforeAll: [
    async ({ browser, drupalSite }, use, testInfo) => {
      const baseURL = testInfo.project?.use?.baseURL || '';
      const context = await browser.newContext({
        baseURL,
      });
      const page = await context.newPage();
      const drupal = new Drupal({ page, drupalSite });
      await setupSite({ drupal });
      await page.close();
      await context.close();
      await use();
    },
    { scope: 'worker', auto: true },
  ],
  canvas: [
    async ({ drupal }, use) => {
      const canvas = new Canvas({ drupal });
      await use(canvas);
    },
    { auto: true },
  ],
  ai: [
    async ({ page }, use) => {
      const ai = new Ai({ page });
      await use(ai);
    },
    { auto: true },
  ],
});

export const snapshotPerTest = base.extend<
  { drupal: Drupal; canvas: Canvas; ai: Ai },
  { drupalSite: DrupalSite; snapshotState: SnapshotState }
>({
  snapshotState: [
    async ({ browser }, use, testInfo) => {
      console.log('snapshotState: worker init for '+(testInfo.project?.use?.baseURL || 'there is no baseURL'));
      const startTime = performance.now();
      const snapshotState = await createSnapshotState({
        baseURL: testInfo.project?.use?.baseURL,
        browser,
      });
      console.log('snapshotState: Snapshot created in ' + (performance.now() - startTime));

      await use(snapshotState);
      await snapshotState.drupalSite.teardown();
      await snapshotState.cleanup();
    },
    { scope: 'worker' },
  ],
  drupalSite: [
    async ({ snapshotState }, use) => {
      await use(snapshotState.drupalSite);
    },
    { scope: 'worker' },
  ],
  drupal: [
    async ({ page, snapshotState }, use) => {
      console.log('drupal: Starting to restore the snapshot')
      const startTime = performance.now();
      await snapshotState.restore();
      console.log('drupal: Snapshot restored in ' + (performance.now() - startTime));
      const drupal = new Drupal({ page, drupalSite: snapshotState.drupalSite });
      await drupal.setTestCookie();
      await use(drupal);
    },
    { auto: true },
  ],
  canvas: [
    async ({ drupal }, use) => {
      const canvas = new Canvas({ drupal });
      await use(canvas);
    },
    { auto: true },
  ],
  ai: [
    async ({ page }, use) => {
      const ai = new Ai({ page });
      await use(ai);
    },
    { auto: true },
  ],
});
