import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

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
  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();
    await drupal.applyRecipe('core/recipes/image_media_type');
    await drupal.installModules(['canvas_test_sdc']);
    await drupal.applyRecipe(
      'modules/contrib/canvas/tests/fixtures/recipes/test_site',
    );
    await drupal.logout();
  });
  test('Can add multiple media items', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.loginAsAdmin();
    await canvas.createCanvas();
    await canvas.openLibraryPanel();
    await canvas.addComponent({
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

    // Add first image (cat)
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

    const previewFrame = await canvas.getActivePreviewFrame();
    const previewInitialOrder = await getPreviewImageOrder(previewFrame);
    expect(previewInitialOrder.length).toBe(2);
    expect(previewInitialOrder).toEqual(initialOrder);
  });

  test('Adding media after page reload preserves existing items', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.loginAsAdmin();
    await canvas.createCanvas();
    await canvas.openLibraryPanel();
    await canvas.addComponent({
      id: 'sdc.canvas_test_sdc.image-gallery',
    });

    // Add first image (cat)
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

    // Set up listener for auto-save PATCH before clicking insert
    const autoSavePromise = page.waitForResponse(
      (response) =>
        response.url().includes('/canvas/api/v0/layout/canvas_page/') &&
        response.request().method() === 'PATCH',
    );

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
    ).toHaveCount(1);

    // Wait for auto-save to complete before reloading
    await autoSavePromise;

    // Reload the page
    await page.reload();
    await canvas.waitForEditorUi();

    // Verify image is still there after reload
    await expect(
      page.locator(
        '[data-testid="canvas-contextual-panel"] .js-media-library-item',
      ),
    ).toHaveCount(1);

    // Add second image (pub) after reload
    await page
      .locator('[data-testid="canvas-contextual-panel"]')
      .getByRole('button', { name: 'Add media' })
      .first()
      .click();

    await page.waitForSelector('.media-library-widget-modal', {
      state: 'visible',
    });

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

    // Verify both images are present (not just the newly added one)
    await expect(
      page.locator(
        '[data-testid="canvas-contextual-panel"] .js-media-library-item',
      ),
    ).toHaveCount(2);

    const finalOrder = await getImageOrder(page);
    expect(finalOrder.length).toBe(2);
    expect(finalOrder).toContain(
      'A cat on top of a cat tree trying to reach a Christmas tree',
    );
    expect(finalOrder).toContain(
      'A pub called The Princes Head surrounded by trees and two red London phone boxes',
    );
  });
});
