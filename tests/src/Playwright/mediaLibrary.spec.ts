import { expect } from '@playwright/test';

import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

test.describe('Media Library', () => {
  test.beforeAll(
    'Setup test site with Drupal Canvas',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.setupCanvasTestSite();
      await drupal.installModules(['canvas_test_e2e_code_components']);
      await page.close();
    },
  );

  test('Can remove an optional SDC image without example and there is no image in the preview', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    await drupal.loginAsAdmin();
    await drupal.createCanvasPage(
      'Media Library Optional No Example',
      '/media-library-optional-no-example',
    );
    await page.goto('/media-library-optional-no-example');
    await canvasEditor.goToEditor();

    await canvasEditor.openLibraryPanel();
    await canvasEditor.addComponent(
      { id: 'sdc.canvas_test_sdc.image-optional-without-example' },
      { skipDimensionCheck: true },
    );

    // No image should be in the preview for a component with no example.
    let frame = await canvasEditor.getActivePreviewFrame();
    await expect(frame.locator('.layout-content img')).toHaveCount(0);

    await page
      .locator(
        '[class*="contextualPanel"] .js-media-library-open-button[data-once="drupal-ajax"]',
      )
      .first()
      .click();
    await expect(page.locator('div[role="dialog"]')).toBeVisible();
    await page.getByLabel('Select Hero image').check();
    await page.getByRole('button', { name: 'Insert selected' }).click();
    await expect(page.locator('div[role="dialog"]')).not.toBeVisible();

    frame = await canvasEditor.getActivePreviewFrame();
    await expect(
      frame.locator('img[alt="A pub called The Princes Head surrounded by trees and two red London phone boxes"]'),
    ).toBeVisible();

    await page
      .locator('[class*="contextualPanel"]')
      .getByLabel('Remove Hero image')
      .click();

    // Confirms the removed optional image prop is not rendered at all, vs the
    // example/default value reappearing.
    frame = await canvasEditor.getActivePreviewFrame();
    await expect(frame.locator('.layout-content img')).toHaveCount(0);
  });

  test('Can remove an optional SDC image with example and there is no image in the preview', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    await drupal.loginAsAdmin();
    await drupal.createCanvasPage(
      'Media Library Optional With Example',
      '/media-library-optional-with-example',
    );
    await page.goto('/media-library-optional-with-example');
    await canvasEditor.goToEditor();

    await canvasEditor.openLibraryPanel();
    await canvasEditor.addComponent(
      { id: 'sdc.canvas_test_sdc.image-optional-with-example' },
      { skipDimensionCheck: true },
    );

    let frame = await canvasEditor.getActivePreviewFrame();
    await expect(
      frame.locator('.layout-content img[alt="Boring placeholder"]'),
    ).toBeVisible();

    await page
      .locator(
        '[class*="contextualPanel"] .js-media-library-open-button[data-once="drupal-ajax"]',
      )
      .first()
      .click();
    await expect(page.locator('div[role="dialog"]')).toBeVisible();
    await page.getByLabel('Select Hero image').check();
    await page.getByRole('button', { name: 'Insert selected' }).click();
    await expect(page.locator('div[role="dialog"]')).not.toBeVisible();

    frame = await canvasEditor.getActivePreviewFrame();
    await expect(
      frame.locator('img[alt="A pub called The Princes Head surrounded by trees and two red London phone boxes"]'),
    ).toBeVisible();

    await page
      .locator('[class*="contextualPanel"]')
      .getByLabel('Remove Hero image')
      .click();

    // Confirms the removed optional image prop is not rendered at all, vs the
    // example/default value reappearing.
    frame = await canvasEditor.getActivePreviewFrame();
    await expect(frame.locator('.layout-content img')).toHaveCount(0);
  });

  test('Can remove an optional code component image with example and there is no image in the preview', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    await drupal.loginAsAdmin();
    await drupal.createCanvasPage(
      'Media Library Optional Code Component',
      '/media-library-optional-code-component',
    );
    await page.goto('/media-library-optional-code-component');
    await canvasEditor.goToEditor();

    await canvasEditor.openLibraryPanel();
    await canvasEditor.addComponent({
      id: 'js.canvas_test_e2e_code_components_optional_image',
    });

    let frame = await canvasEditor.getActivePreviewFrame();
    await expect(
      frame.locator('.layout-content img[alt="Example image placeholder"]'),
    ).toBeVisible();

    await page
      .locator(
        '[class*="contextualPanel"] .js-media-library-open-button[data-once="drupal-ajax"]',
      )
      .first()
      .click();
    await expect(page.locator('div[role="dialog"]')).toBeVisible();
    await page.getByLabel('Select Hero image').check();
    await page.getByRole('button', { name: 'Insert selected' }).click();
    await expect(page.locator('div[role="dialog"]')).not.toBeVisible();

    frame = await canvasEditor.getActivePreviewFrame();
    await expect(
      frame.locator('img[alt="A pub called The Princes Head surrounded by trees and two red London phone boxes"]'),
    ).toBeVisible();
    await expect(
      frame.locator('.layout-content img[alt="Example image placeholder"]'),
    ).not.toBeVisible();

    // Update the text prop and confirm it renders in the preview.
    const textField = page.getByLabel('text', { exact: true });
    await textField.fill('A new value');
    frame = await canvasEditor.getActivePreviewFrame();
    await expect(frame.locator('p')).toContainText('A new value');
    await expect(textField).toHaveValue('A new value');

    await page
      .locator('[class*="contextualPanel"]')
      .getByLabel('Remove Hero image')
      .click();

    // Add a fixed wait to give time for the placeholder image to potentially
    // reappear. It isn't supposed to reappear, but we need time to be certain.
    await page.waitForTimeout(2000);

    // Confirms the removed optional image prop is not rendered at all, vs the
    // example/default value reappearing.
    frame = await canvasEditor.getActivePreviewFrame();
    await expect(frame.locator('.layout-content img')).toHaveCount(0);

    // Text prop is still intact after image removal.
    await expect(frame.locator('p')).toContainText('A new value');

    // Confirm other props still work.
    await textField.fill('Further changes to the value');
    await expect(textField).toHaveValue('Further changes to the value');
    frame = await canvasEditor.getActivePreviewFrame();
    await expect(frame.locator('p')).toContainText(
      'Further changes to the value',
    );
  });

  test('Can remove a required code component image with example and there is no image in the preview', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    await drupal.loginAsAdmin();
    await drupal.createCanvasPage(
      'Media Library Required Code Component',
      '/media-library-required-code-component',
    );
    await page.goto('/media-library-required-code-component');
    await canvasEditor.goToEditor();

    await canvasEditor.openLibraryPanel();
    await canvasEditor.addComponent({
      id: 'js.canvas_test_e2e_code_components_req_image',
    });

    let frame = await canvasEditor.getActivePreviewFrame();
    await expect(
      frame.locator('.layout-content img[alt="Example image placeholder"]'),
    ).toBeVisible();

    await page
      .locator(
        '[class*="contextualPanel"] .js-media-library-open-button[data-once="drupal-ajax"]',
      )
      .first()
      .click();
    await expect(page.locator('div[role="dialog"]')).toBeVisible();
    await page.getByLabel('Select Hero image').check();
    await page.getByRole('button', { name: 'Insert selected' }).click();
    await expect(page.locator('div[role="dialog"]')).not.toBeVisible();

    frame = await canvasEditor.getActivePreviewFrame();
    await expect(
      frame.locator('img[alt="A pub called The Princes Head surrounded by trees and two red London phone boxes"]'),
    ).toBeVisible();
    await expect(
      frame.locator('.layout-content img[alt="Example image placeholder"]'),
    ).not.toBeVisible();

    // Update the text prop and confirm it renders in the preview.
    const textField = page.getByLabel('text', { exact: true });
    await textField.fill('A new value');
    await expect(textField).toHaveValue('A new value');
    frame = await canvasEditor.getActivePreviewFrame();
    await expect(frame.locator('p')).toContainText('A new value');

    await page
      .locator('[class*="contextualPanel"]')
      .getByLabel('Remove Hero image')
      .click();

    // Confirm the widget is now empty.
    await expect(
      page.locator('.js-media-library-widget .description'),
    ).toContainText('One media item remaining.');

    // The previously added image is still in the preview due to it being a
    // required prop.
    frame = await canvasEditor.getActivePreviewFrame();
    await expect(
      frame.locator('img[alt="A pub called The Princes Head surrounded by trees and two red London phone boxes"]'),
    ).toBeVisible();

    // Confirms the example does not return.
    await expect(
      frame.locator('.layout-content img[alt="Example image placeholder"]'),
    ).not.toBeVisible();

    // Add an arbitrary wait to confirm the absence of rendering errors, which
    // can appear shortly after the image is removed but with a delay.
    await page.waitForTimeout(1000);

    await expect(frame.locator('.layout-content')).not.toContainText(
      'Component failed to render',
    );
    await expect(frame.locator('.layout-content')).not.toContainText(
      'Exception',
    );

    // Text prop is still intact after image removal.
    await expect(frame.locator('p')).toContainText('A new value');

    await textField.fill('Further changes to the value');
    await expect(textField).toHaveValue('Further changes to the value');
    frame = await canvasEditor.getActivePreviewFrame();
    await expect(frame.locator('p')).toContainText(
      'Further changes to the value',
    );
  });
});
