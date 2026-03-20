import { readFile } from 'fs/promises';
import { getModuleDir } from '@drupal-canvas/test-utils';
import { expect } from '@playwright/test';

import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

/**
 * Tests for isCanvasPreview() function behavior using a code component.
 */
test.describe('isCanvasPreview', () => {
  test.beforeAll(
    'Setup test site with Drupal Canvas and create component',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.installModules(['canvas', 'canvas_test_sdc']);

      // Wait for site to be ready, then login
      await page.goto(drupalSite.url);
      await page.waitForLoadState('networkidle');
      await drupal.loginAsAdmin();

      const { CanvasEditor } = await import('./objects/CanvasEditor');
      const canvasEditor = new CanvasEditor({ page });
      await canvasEditor.goToCanvasRoot();
      const moduleDir = await getModuleDir();
      const code = await readFile(
        `${moduleDir}/canvas/tests/fixtures/code_components/page-elements/IsCanvasPreviewIndicator.jsx`,
        'utf-8',
      );
      await canvasEditor.createCodeComponent('IsCanvasPreviewIndicator', code);
      await canvasEditor.publishAllChanges(['IsCanvasPreviewIndicator']);
      // @note saveCodeComponent assumes you're in an editor, not root.
      // await canvasEditor.saveCodeComponent('js.isCanvasPreviewIndicator');
      await page.getByRole('button', { name: 'Add to components' }).click();
      await page.getByRole('button', { name: 'Add' }).click();
      await page.waitForLoadState('networkidle');
      await canvasEditor.waitForCanvasUi();

      await page.close();
    },
  );

  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();
  });

  test('Returns true in editor iframe with parent on /canvas/editor/', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    // Create a page and add the component
    await drupal.createCanvasPage('isCanvasPreview Test 1', '/isCanvasPreview-test-1');
    await page.goto('/isCanvasPreview-test-1');
    await canvasEditor.goToEditor();

    // Add the component to the page
    await canvasEditor.openLibraryPanel();
    await canvasEditor.addComponent({ id: 'js.isCanvasPreviewIndicator' });

    // Wait for the editor iframe to load
    const preview = canvasEditor.getCodePreviewFrame();
    await preview.waitForLoadState('networkidle');

    // Check that the component shows isPreview as true
    const indicator = preview.getByTestId('isCanvasPreview-indicator');
    await expect(indicator).toBeVisible();
    await expect(indicator).toHaveAttribute('data-is-preview', 'true');
    await expect(indicator.getByText('isCanvasPreview: true')).toBeVisible();
  });

  test('Returns false in preview mode iframe with parent on /canvas/preview/', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    // Create a page and add the component
    await drupal.createCanvasPage('isCanvasPreview Test 2', '/isCanvasPreview-test-2');
    await page.goto('/isCanvasPreview-test-2');
    await canvasEditor.goToEditor();

    // Add the component to the page
    await canvasEditor.openLibraryPanel();
    await canvasEditor.addComponent({ id: 'js.isCanvasPreviewIndicator' });

    // Enter preview mode
    await page.getByText('Preview', { exact: true }).click();
    await expect(page.getByText('Exit Preview')).toBeVisible();

    // Verify we're on the preview route
    await expect(page).toHaveURL(/\/canvas\/preview\/[^/]+\/[^/]+/);

    // Wait for the preview iframe to load
    await page.waitForSelector('iframe[title="Page preview"]', {
      state: 'attached',
    });

    const iframeElement = await page.$('iframe[title="Page preview"]');
    const previewFrame = await iframeElement?.contentFrame();
    if (!previewFrame) {
      throw new Error('Preview iframe not found');
    }
    await previewFrame.waitForLoadState('networkidle');

    // Check that the component shows isPreview as false
    const indicator = previewFrame.getByTestId('isCanvasPreview-indicator');
    await expect(indicator).toBeVisible();
    await expect(indicator).toHaveAttribute('data-is-preview', 'false');
    await expect(indicator.getByText('isCanvasPreview: false')).toBeVisible();
  });

  test('Returns false on live site (not in iframe)', async ({ page, drupal, canvasEditor }) => {
    // Create a page and add the component
    await drupal.createCanvasPage('isCanvasPreview Test 3', '/isCanvasPreview-test-3');
    await page.goto('/isCanvasPreview-test-3');
    await canvasEditor.goToEditor();
    await canvasEditor.openLibraryPanel();
    await canvasEditor.addComponent({ id: 'js.isCanvasPreviewIndicator' });

    // Now visit the live site
    await page.goto('/isCanvasPreview-test-3');

    // Check that the component shows isPreview as false on the live site
    const indicator = page.getByTestId('isCanvasPreview-indicator');
    await expect(indicator).toBeVisible();
    await expect(indicator).toHaveAttribute('data-is-preview', 'false');
    await expect(indicator.getByText('isCanvasPreview: false')).toBeVisible();
  });
});
