// cspell:ignore lauris
import { expect } from '@playwright/test';

import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

/**
 * Extracts the canvas entity path (e.g. "canvas_page/3") from a canvas editor URL.
 */
function extractEntityPath(url: string): string {
  const match = url.match(/\/canvas\/editor\/([^/]+\/[^/]+)/);
  if (!match) throw new Error(`Could not extract entity path from URL: ${url}`);
  return match[1];
}

test.describe('Auto path alias generation', () => {
  test.beforeAll(
    'Setup test site with Drupal Canvas',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.installModules(['canvas']);
      await page.close();
    },
  );

  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();
  });

  test('does not auto update path alias based on title if path is already set', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    await drupal.createCanvasPage('Test Page', '/test-page');
    await page.goto('/test-page');
    await canvasEditor.goToEditor();

    await expect(page.getByLabel('Title')).toBeVisible();
    await expect(page.getByLabel('URL Alias')).toBeVisible();
    await expect(page.getByLabel('URL Alias')).toHaveValue('/test-page');

    await page.getByLabel('Title').fill('New page title');
    await page.getByLabel('Title').blur();
    await expect(page.getByLabel('URL Alias')).toHaveValue('/test-page');
  });

  test('on a new page path is generated automatically until manually overridden', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    // Create a starting page to open the editor from.
    await drupal.createCanvasPage('Start Page', '/start-page');
    await page.goto('/start-page');
    await canvasEditor.goToEditor();

    // Create a new page via the navigation UI.
    await page.getByTestId('canvas-navigation-button').click();
    await page.getByTestId('canvas-navigation-new-button').click();
    await page.getByTestId('canvas-navigation-new-page-button').click();

    // Wait until the editor navigates to the new page.
    await canvasEditor.waitForEditorUi();
    await expect(page).not.toHaveURL(/\/start-page/);
    await expect(page.getByTestId('canvas-topbar')).toContainText('Draft');

    // Extract the new page entity path from the URL so we can reload it later.
    const entityPath = extractEntityPath(page.url());

    // Make sure that the path alias is empty to begin with.
    await expect(page.getByLabel('URL Alias')).toHaveValue('');

    // Make sure that alias is empty even after the page is reloaded.
    await page.goto(`/canvas/editor/${entityPath}`);
    await canvasEditor.waitForEditorUi();
    await expect(page.getByLabel('URL Alias')).toHaveValue('');

    // Set the title and make sure that the path alias is generated automatically.
    await page.getByLabel('Title').clear();
    const updatePreview = page.waitForResponse(
      (response) =>
        response.url().includes('/canvas/api/v0/layout/') &&
        response.request().method() === 'POST',
    );
    await page.getByLabel('Title').fill("Lauri's new page");
    await updatePreview;
    await page.getByLabel('Title').blur();
    await expect(page.getByLabel('URL Alias')).toHaveValue('/lauris-new-page');
    await expect(page.getByText('Review 1 change')).toBeAttached();

    // Refresh the page and make sure that the path alias is still set.
    await page.goto(`/canvas/editor/${entityPath}`);
    await canvasEditor.waitForEditorUi();
    await expect(page.getByLabel('URL Alias')).toHaveValue('/lauris-new-page');

    // Update the title and make sure that the path alias is updated automatically.
    await page.getByLabel('Title').fill("Lauri's updated page");
    await page.getByLabel('Title').blur();
    await expect(page.getByLabel('URL Alias')).toHaveValue(
      '/lauris-updated-page',
    );

    // Set the path alias manually and make sure that the auto-generated path alias is not updated anymore.
    await page.getByLabel('URL Alias').fill('/custom-url');
    await page.getByLabel('URL Alias').blur();
    await page.getByLabel('Title').clear();
    await page.getByLabel('Title').fill("Lauri's page");
    const updatePreview2 = page.waitForResponse(
      (response) =>
        response.url().includes('/canvas/api/v0/layout/') &&
        response.request().method() === 'POST',
    );
    await page.getByLabel('Title').blur();
    await updatePreview2;
    await expect(page.getByLabel('URL Alias')).toHaveValue('/custom-url');

    // Refresh the page and make sure that the path alias is still set to the
    // custom value and doesn't get updated.
    await page.goto(`/canvas/editor/${entityPath}`);
    await canvasEditor.waitForEditorUi();
    await expect(page.getByLabel('URL Alias')).toHaveValue('/custom-url');
    await page.getByLabel('Title').fill("Lauri's updated page");
    await page.getByLabel('Title').blur();
    await expect(page.getByLabel('URL Alias')).toHaveValue('/custom-url');
  });

  test('after changes are published, new path is no longer generated automatically', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    // Create a starting page to open the editor from.
    await drupal.createCanvasPage('Start Page 2', '/start-page-2');
    await page.goto('/start-page-2');
    await canvasEditor.goToEditor();

    // Create a new page via the navigation UI.
    await page.getByTestId('canvas-navigation-button').click();
    await page.getByTestId('canvas-navigation-new-button').click();
    await page.getByTestId('canvas-navigation-new-page-button').click();

    // Wait until the editor navigates to the new page.
    await canvasEditor.waitForEditorUi();
    await expect(page).not.toHaveURL(/\/start-page-2/);
    await expect(page.getByTestId('canvas-topbar')).toContainText('Draft');

    // Set the title and make sure that the path alias is generated automatically.
    await page.getByLabel('Title').clear();
    const updatePreview = page.waitForResponse(
      (response) =>
        response.url().includes('/canvas/api/v0/layout/') &&
        response.request().method() === 'POST',
    );
    await page.getByLabel('Title').fill("Lauri's another page");
    await updatePreview;
    await page.getByLabel('Title').blur();
    await expect(page.getByLabel('URL Alias')).toHaveValue(
      '/lauris-another-page',
    );

    // Wait for the publish review button to become available.
    await expect(
      page.getByRole('button', { name: /Review \d+ changes?/ }),
    ).toBeVisible({ timeout: 20_000 });

    await canvasEditor.publishAllChanges(["Lauri's another page"]);

    // Close the publish review dialog.
    await page.getByLabel('Close').click();

    // Change the title and verify the path alias is no longer auto-updated after publishing.
    await page.getByLabel('Title').fill("Lauri's updated page");
    await page.getByLabel('Title').blur();
    await expect(page.getByLabel('URL Alias')).toHaveValue(
      '/lauris-another-page',
    );
  });

  test('will generate path alias based on title for published content if path is empty', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    // Create a published page with no path alias.
    await drupal.drush(
      `php-eval "\\\\Drupal\\\\canvas\\\\Entity\\\\Page::create(['title' => 'No Alias Page', 'type' => 'canvas_page', 'status' => TRUE])->save();"`,
    );

    // Create a helper page to enter the editor from.
    await drupal.createCanvasPage('Navigate From', '/navigate-from');
    await page.goto('/navigate-from');
    await canvasEditor.goToEditor();

    // Open the pages panel and navigate to the no-alias page.
    await canvasEditor.openPagesPanel();
    await page
      .getByTestId('canvas-page-list')
      .getByText('No Alias Page')
      .click();
    await canvasEditor.waitForEditorUi();

    await expect(page.getByLabel('URL Alias')).toHaveValue('');
    await expect(page.getByTestId('canvas-topbar')).toContainText('Published');

    await page.getByLabel('Title').fill('My new page title');
    await page.getByLabel('Title').blur();
    await expect(page.getByLabel('URL Alias')).toHaveValue(
      '/my-new-page-title',
    );
  });
});
