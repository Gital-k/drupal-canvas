import { expect } from '@playwright/test';

import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

import type { FrameLocator, Page } from '@playwright/test';

async function getImageOrder(page: Page): Promise<string[]> {
  const items = await page
    .locator('[data-testid="canvas-contextual-panel"] .js-media-library-item')
    .all();
  const alts: string[] = [];
  for (const item of items) {
    const alt = await item
      .locator('.js-media-library-item-preview img')
      .getAttribute('alt');
    if (alt) {
      alts.push(alt);
    }
  }
  return alts;
}

async function getPreviewImageOrder(
  previewFrame: FrameLocator,
): Promise<string[]> {
  const figure = previewFrame.locator('main figure').first();
  const images = await figure.locator('img').all();
  const alts: string[] = [];
  for (const img of images) {
    const alt = await img.getAttribute('alt');
    if (alt) {
      alts.push(alt);
    }
  }
  return alts;
}

test.describe('Media Multi-Cardinality', () => {
  test.beforeAll(
    'Setup test site with media entities',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.setupCanvasTestSite();
      await page.close();
    },
  );

  test('Can add multiple media items', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    await drupal.loginAsAdmin();
    await drupal.createCanvasPage('Gallery Multi Test', '/gallery-multi');
    await page.goto('/gallery-multi');
    await canvasEditor.goToEditor();
    await canvasEditor.openLibraryPanel();
    await canvasEditor.addComponent({
      id: 'sdc.canvas_test_sdc.image-gallery',
    });

    await page
      .locator('[data-testid="canvas-contextual-panel"]')
      .getByRole('button', { name: 'Add media' })
      .first()
      .click();

    await page.waitForSelector('.media-library-widget-modal', {
      state: 'visible',
    });

    const catCheckbox = page.locator(
      `.js-media-library-item:has(img[alt="A cat on top of a cat tree trying to reach a Christmas tree"]) input[type="checkbox"]`,
    );
    await catCheckbox.setChecked(true);

    const pubCheckbox = page.locator(
      `.js-media-library-item:has(img[alt="A pub called The Princes Head surrounded by trees and two red London phone boxes"]) input[type="checkbox"]`,
    );
    await pubCheckbox.setChecked(true);

    await page
      .getByRole('button', { name: 'Insert selected', exact: true })
      .click();

    await page.waitForSelector('.media-library-widget-modal', {
      state: 'hidden',
    });

    await expect(
      page.locator(
        '[data-testid="canvas-contextual-panel"] .js-media-library-item',
      ),
    ).toHaveCount(2);

    const initialOrder = await getImageOrder(page);
    expect(initialOrder.length).toBe(2);
    expect(initialOrder).toContain(
      'A cat on top of a cat tree trying to reach a Christmas tree',
    );
    expect(initialOrder).toContain(
      'A pub called The Princes Head surrounded by trees and two red London phone boxes',
    );

    await page.waitForTimeout(1000);

    const previewFrame = await canvasEditor.getActivePreviewFrame();
    const previewInitialOrder = await getPreviewImageOrder(previewFrame);
    expect(previewInitialOrder.length).toBe(2);
    expect(previewInitialOrder).toEqual(initialOrder);
  });
});
