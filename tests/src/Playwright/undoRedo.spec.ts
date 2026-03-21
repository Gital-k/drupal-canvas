import { expect } from '@playwright/test';

import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

test.describe('Undo/Redo functionality', () => {
  test.beforeAll(
    'Setup test site with Drupal Canvas',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.setupCanvasTestSite();
      await page.close();
    },
  );

  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();
  });

  test('Performs a basic interaction with Undo/Redo', async ({
    page,
    canvasEditor,
  }) => {
    await page.goto('/first');
    await canvasEditor.goToEditor();
    await canvasEditor.openLibraryPanel();

    // Assert that the undo button is disabled initially.
    await expect(page.locator('button[aria-label="Undo"]')).toBeDisabled();

    // Check there are two heroes initially (seeded via the test_site recipe).
    await expect(
      page.locator(
        '#canvasPreviewOverlay [data-canvas-component-id="sdc.canvas_test_sdc.my-hero"]',
      ),
    ).toHaveCount(2);

    // Insert a Hero component (making 3 heroes total).
    await canvasEditor.addComponent({ id: 'sdc.canvas_test_sdc.my-hero' });

    await expect(
      page.locator(
        '#canvasPreviewOverlay [data-canvas-component-id="sdc.canvas_test_sdc.my-hero"]',
      ),
    ).toHaveCount(3);

    // Undo — hero count should return to 2.
    await page.keyboard.press('Meta+Z');
    await expect(
      page.locator(
        '#canvasPreviewOverlay [data-canvas-component-id="sdc.canvas_test_sdc.my-hero"]',
      ),
    ).toHaveCount(2);

    // Redo — hero count should return to 3.
    await page.keyboard.press('Meta+Shift+Z');
    await expect(
      page.locator(
        '#canvasPreviewOverlay [data-canvas-component-id="sdc.canvas_test_sdc.my-hero"]',
      ),
    ).toHaveCount(3);
  });

  test('Component instance form values are included in Undo/Redo', async ({
    page,
    canvasEditor,
  }) => {
    await page.goto('/first');
    await canvasEditor.goToEditor();

    // Click the "hello, world!" hero (in column_two of the seeded layout).
    // Target by UUID to avoid ambiguity with the "meow!" hero in column_one.
    const helloWorldHeroUuid = 'a9727e4b-42a7-4621-a502-be43cab1e20a';
    const helloWorldHero = page.locator(
      `#canvasPreviewOverlay [data-canvas-uuid="${helloWorldHeroUuid}"]`,
    );
    await helloWorldHero.evaluate((el) => {
      el.scrollIntoView({ behavior: 'instant', block: 'center' });
      el.dispatchEvent(
        new MouseEvent('mousedown', {
          view: window,
          bubbles: true,
          cancelable: true,
          button: 0,
          buttons: 1,
        }),
      );
      el.dispatchEvent(
        new MouseEvent('mouseup', {
          view: window,
          bubbles: true,
          cancelable: true,
          button: 0,
          buttons: 0,
        }),
      );
      el.dispatchEvent(
        new MouseEvent('click', {
          view: window,
          bubbles: true,
          cancelable: true,
          button: 0,
          buttons: 0,
        }),
      );
    });
    await canvasEditor.waitForContextualPanel();

    const headingInput = page.getByLabel('Heading', { exact: true });
    const heroHeading = (await canvasEditor.getActivePreviewFrame()).locator(
      `[data-canvas-uuid="${helloWorldHeroUuid}"] .my-hero__heading`,
    );

    // Add " one" to the heading field.
    await headingInput.click();
    await headingInput.type(' one');

    // Assert preview and input both reflect "hello, world! one".
    await expect(heroHeading).toContainText('hello, world! one');
    await expect(headingInput).toHaveValue('hello, world! one');

    // Add " two" to the heading field, then blur and wait for debounce.
    await headingInput.click();
    await headingInput.type(' two');
    await headingInput.blur();
    // Wait for the debounce to flush so the undo history entry is recorded.
    await page.waitForTimeout(500);

    await expect(headingInput).toHaveValue('hello, world! one two');
    await expect(heroHeading).toContainText('hello, world! one two');

    // Undo — value should revert to "hello, world! one".
    await page.keyboard.press('Meta+Z');
    await expect(headingInput).toHaveValue('hello, world! one');
    await expect(heroHeading).toContainText('hello, world! one');

    // Redo — value should return to "hello, world! one two".
    await page.keyboard.press('Meta+Shift+Z');
    await expect(headingInput).toHaveValue('hello, world! one two');
    await expect(heroHeading).toContainText('hello, world! one two');

    // Undo twice — value should be back to the original "hello, world!".
    await page.keyboard.press('Meta+Z');
    await page.keyboard.press('Meta+Z');
    await expect(headingInput).toHaveValue('hello, world!');
  });
});
