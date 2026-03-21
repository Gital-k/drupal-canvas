import { exec, hasDrush } from '@drupal-canvas/test-utils';
import { test as base, mergeTests } from '@playwright/test';

import { Ai } from '../objects/Ai';
import { CanvasEditor } from '../objects/CanvasEditor';
import { Drupal } from '../objects/Drupal';

export type DrupalSite = {
  dbPrefix: string;
  userAgent: string;
  sitePath: string;
  url: string;
  hasDrush: boolean;
  teardown: () => Promise<string>;
  /**
   * Tear down the current Drupal install and replace it with a brand-new one,
   * mutating this object in-place so that all existing references (e.g. in
   * `Drupal` page-objects) automatically see the fresh credentials.
   *
   * Called automatically at the start of every worker to guarantee a clean
   * database even if Playwright ever reuses a worker across spec files.
   * Can also be called explicitly inside a spec file's `beforeAll` when a
   * test group needs a completely different install state.
   */
  reinstall: () => Promise<void>;
};

type DrupalSiteInstall = {
  drupalSite: DrupalSite;
};

/**
 * Run `test-site.php install` and return the parsed JSON payload.
 */
const installDrupal = async () => {
  const stdout = await exec(
    `php core/scripts/test-site.php install --no-interaction --install-profile minimal --base-url ${process.env.DRUPAL_TEST_BASE_URL} --db-url ${process.env.DRUPAL_TEST_DB_URL} --json`,
  );
  return JSON.parse(stdout.toString());
};

/**
 * Run `test-site.php tear-down` for the given db prefix.
 */
const teardownDrupal = async (dbPrefix: string): Promise<string> => {
  if (
    process.env.DRUPAL_TEST_PLAYWRIGHT_SKIP_TEARDOWN &&
    process.env.DRUPAL_TEST_PLAYWRIGHT_SKIP_TEARDOWN === 'true'
  ) {
    return '';
  }
  return await exec(
    `php core/scripts/test-site.php tear-down --no-interaction --db-url ${process.env.DRUPAL_TEST_DB_URL} ${dbPrefix}`,
  );
};

const drupalSite = base.extend<DrupalSiteInstall>({
  // @ts-expect-error Playwright's types don't model scope:'worker' on base.extend correctly
  drupalSite: [
    // eslint-disable-next-line no-empty-pattern
    async ({}, use) => {
      let installData = await installDrupal();
      const withDrush = await hasDrush();

      // The site object is mutated in-place by `reinstall()` so that all
      // consumers (Drupal page-objects, beforeAll blocks, etc.) automatically
      // pick up the new credentials without needing to be re-constructed.
      const site: DrupalSite = {
        dbPrefix: installData.db_prefix,
        userAgent: installData.user_agent,
        sitePath: installData.site_path,
        url: process.env.DRUPAL_TEST_BASE_URL!,
        hasDrush: withDrush,
        teardown: () => teardownDrupal(installData.db_prefix),
        reinstall: async () => {
          await teardownDrupal(installData.db_prefix);
          installData = await installDrupal();
          site.dbPrefix = installData.db_prefix;
          site.userAgent = installData.user_agent;
          site.sitePath = installData.site_path;
          site.hasDrush = await hasDrush();
          site.teardown = () => teardownDrupal(installData.db_prefix);
        },
      };

      await use(site);
    },
    { scope: 'worker' },
  ],
});

type DrupalObj = {
  drupal: Drupal;
};

const drupal = base.extend<DrupalObj>({
  drupal: [
    // @ts-expect-error drupalSite is injected at runtime via mergeTests
    async ({ page, drupalSite }, use) => {
      const drupal = new Drupal({ page, drupalSite });
      await use(drupal);
    },
    { auto: true },
  ],
});

type CanvasEditorObj = {
  canvasEditor: CanvasEditor;
};

const canvasEditor = base.extend<CanvasEditorObj>({
  canvasEditor: [
    async ({ page }, use) => {
      const canvasEditor = new CanvasEditor({ page });
      await use(canvasEditor);
    },
    { auto: true },
  ],
});

const ai = base.extend<{ ai: Ai }>({
  ai: [
    async ({ page }, use) => {
      const ai = new Ai({ page });
      await use(ai);
    },
    { auto: true },
  ],
});

export const beforeAllTests = base.extend<{ forEachWorker: void }>({
  // @ts-expect-error Playwright's types don't model scope:'worker' on base.extend correctly
  forEachWorker: [
    // @ts-expect-error drupalSite is injected at runtime via mergeTests
    async ({ drupalSite }, use) => {
      // Safety-net: if Playwright reuses this worker for a second spec file,
      // reinstall guarantees a clean database before any test in the new file
      // runs. With workers === number-of-spec-files this is a no-op (the worker
      // only ever handles one file), but it protects against drift between the
      // worker count and the spec file count.
      await drupalSite.reinstall();
      await use();
      // Tear down after all tests in this worker have finished.
      drupalSite.teardown();
    },
    { scope: 'worker', auto: true },
  ], // automatically starts for every worker.
});

const beforeEachTest = base.extend<{ forEachTest: void }>({
  forEachTest: [
    // @ts-expect-error drupal is injected at runtime via mergeTests
    async ({ drupal }, use) => {
      // This code runs before every test.
      await drupal.setTestCookie();
      await use();
    },
    { auto: true },
  ], // automatically starts for every test.
});

export const test = mergeTests(
  drupalSite,
  drupal,
  canvasEditor,
  ai,
  beforeAllTests,
  beforeEachTest,
);
