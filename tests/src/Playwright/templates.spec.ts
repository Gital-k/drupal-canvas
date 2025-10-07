import { expect } from '@playwright/test';
import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

test.describe('Templates', () => {
  test.beforeAll(
    'Setup test site with Drupal Canvas',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.drush('cr');

      await drupal.installModules([
        'canvas',
        'canvas_dev_mode',
      ]);
      await drupal.setupCanvasTestSite();
      // @todo remove the cache clear once https://www.drupal.org/project/drupal/issues/3534825
      // is fixed.
      await drupal.drush('cr');
      await page.close();
    },
  );
  test('Template - add templates to page', async ({ page, drupal, canvasEditor }) => {
    await drupal.loginAsAdmin();
    await canvasEditor.goToCanvasRoot();
    await page.click('[aria-label="Templates"]');
    await expect(page.getByTestId('big-add-template-button')).toBeVisible();
    await expect(page.locator('[data-canvas-folder-name="Article"]', )).toBeVisible();

    await expect(
      page.locator(
        '.primaryPanelContent',
      ),
    ).toMatchAriaSnapshot(`
      - button "Add new template":
        - img
      - button "Content types" [expanded]
      - region "Content types"
    `);
    await page.getByTestId('big-add-template-button').click();
    //role="dialog" //canvas-manage-library-add-template-content
    await expect(page.getByTestId('canvas-manage-library-add-template-content')).toBeVisible();
    // #content-type select
    await page.locator('#content-type').click();
    await page.getByRole('option', { hasText: 'Page data' }).waitFor();
    await page.getByRole('option', { hasText: 'Page data' }).click();    // role="option"

    await page.locator('#template-name').click();
    await page.getByRole('option', { hasText: 'Full content' }).waitFor();
    await page.getByRole('option', { hasText: 'Full content' }).click();
    const dialog = page.getByRole('dialog', { name: 'Add new template' });
    await dialog.getByRole('button', { hasText: 'Add new template' }).click();
    // Select an option by clicking it
    // await page.click('[data-testid="radix-select-item"][data-value="Basic page"]');
  })

})
