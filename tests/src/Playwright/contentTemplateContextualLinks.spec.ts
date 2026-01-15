/**
 * @file
 * Playwright test for verifying contextual links work with ContentTemplates.
 *
 * This test uses Playwright instead of a functional test because contextual
 * links are not part of the DOM when JavaScript is disabled. Functional tests
 * run without JavaScript, making it impossible to test contextual link
 * behavior in that environment.
 *
 * This specifically tests that the basic node contextual links (like "Edit")
 * continue to work when ContentTemplates are enabled.
 *
 * @see \Drupal\Tests\contextual\FunctionalJavascript\ContextualLinksTest
 */

import { getModuleDir } from '@drupal-canvas/test-utils';
import { expect } from '@playwright/test';

import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

test.describe('ContentTemplate Contextual Links', () => {
  test.beforeAll(
    'Setup test site with Drupal Canvas',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.setupCanvasTestSite();
      await drupal.installModules(['contextual', 'toolbar']);
      await page.close();
    },
  );

  test('Verify contextual links work with published ContentTemplate', async ({
    page,
    drupal,
  }) => {
    const moduleDir = await getModuleDir();
    // Apply recipe that creates a published ContentTemplate with a component.
    await drupal.applyRecipe(
      `${moduleDir}/canvas/tests/fixtures/recipes/article_content_template`,
    );

    await drupal.loginAsAdmin();
    // View the node for the first time after the template is published.
    await page.goto('/i-am-an-empty-node');

    // Enable contextual links UI
    await page.getByRole('button', { name: 'Edit', pressed: false }).click();

    await expect(
      page.getByRole('button', { name: 'Edit', pressed: true }),
    ).toBeVisible();

    // Wait a bit for contextual links JavaScript to fully initialize.
    await page.waitForTimeout(1000);

    // Verify that contextual links exist on the page that contain "Edit"
    // This confirms the fix works - contextual links are rendered with ContentTemplates
    const editLink = page.getByRole('link', { name: 'Edit' }).first();
    await expect(editLink).toBeVisible();
  });
});
