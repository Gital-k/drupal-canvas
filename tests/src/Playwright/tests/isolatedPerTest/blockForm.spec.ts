import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

// cspell:ignore cset

test.describe('Block form', () => {
  test('Block settings form with details element', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();
    await canvas.openLibraryPanel();
    // Don't wait for the preview as this user doesn't have permissions to see anything
    // in that menu.
    await canvas.addComponent(
      { id: 'block.system_menu_block.footer' },
      { waitForVisible: false },
    );

    const inputsForm = page.locator(
      '[data-testid="canvas-contextual-panel"] [data-drupal-selector="component-instance-form"]',
    );
    await expect(inputsForm).toContainText('Menu levels');
    await expect(inputsForm.locator('select')).toHaveCount(2);
    await expect(inputsForm.locator('input[type="checkbox"]').first()).toBeVisible();
  });

  test('Block settings form values are stored and the preview is updated', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();
    await canvas.openLibraryPanel();
    await canvas.addComponent(
      { name: 'Site branding' },
      { waitForVisible: true },
    );

    await canvas.openLayersPanel();
    await canvas.openComponent('Site branding');

    // All branding options should be enabled by default.
    const imgLocator =
      'xpath=//img[ancestor::*[starts-with(@id, "block-") and string-length(@id) = 42]]';
    const siteLogoCheckbox = page.getByRole('checkbox', { name: 'Site logo' });
    await expect(siteLogoCheckbox).toBeChecked();
    await expect(
      (await canvas.getActivePreviewFrame()).locator(imgLocator),
    ).toBeVisible();

    const siteNameLocator =
      'xpath=//a[not(img) and ancestor::*[starts-with(@id, "block-") and string-length(@id) = 42]]';
    const siteNameCheckbox = page.getByRole('checkbox', { name: 'Site name' });
    await expect(siteNameCheckbox).toBeChecked();
    await expect(
      (await canvas.getActivePreviewFrame()).locator(siteNameLocator),
    ).toHaveText('Drupal');

    // Remove and re-add the site logo.
    await siteLogoCheckbox.click();
    await expect(siteLogoCheckbox).not.toBeChecked();
    await expect(
      (await canvas.getActivePreviewFrame()).locator(imgLocator),
    ).not.toBeVisible();
    await siteLogoCheckbox.click();
    await expect(siteLogoCheckbox).toBeChecked();
    await expect(
      (await canvas.getActivePreviewFrame()).locator(imgLocator),
    ).toBeVisible();

    // Remove the site name.
    await siteNameCheckbox.click();
    await expect(siteNameCheckbox).not.toBeChecked();
    await expect(
      (await canvas.getActivePreviewFrame()).locator(siteNameLocator),
    ).not.toBeVisible();

    // Verify the component is saved and renders with the updated options.
    await canvas.publishAllChanges();
    await page.reload();
    await expect(
      (await canvas.getActivePreviewFrame()).locator(imgLocator),
    ).toBeVisible();
    await expect(
      (await canvas.getActivePreviewFrame()).locator(siteNameLocator),
    ).not.toBeVisible();
  });
});
