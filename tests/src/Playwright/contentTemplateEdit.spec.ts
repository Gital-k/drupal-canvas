import { getModuleDir } from '@drupal-canvas/test-utils';
import { expect } from '@playwright/test';

import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

test.describe('Content Template Edit', () => {
  test.beforeAll(
    'Setup test site with Drupal Canvas',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.setupCanvasTestSite();
      await drupal.installModules(['contextual', 'toolbar']);
      await drupal.drush('cr');
      await page.close();
    },
  );
  test('Create Article and verify Content Template contextual link is not available', async ({
    page,
    drupal,
  }) => {
    await drupal.loginAsAdmin();
    // Navigate to the content page and create a basic article.
    await page.goto('/admin/content');
    await page.getByRole('link', { name: '+Add content' }).click();
    await page.getByRole('link', { name: 'Article' }).click();
    await page.getByRole('textbox', { name: 'Title *' }).click();
    await page.getByRole('textbox', { name: 'Title *' }).fill('test1');
    await page.getByRole('button', { name: 'Save' }).click();
    await page.getByRole('button', { name: 'Edit', pressed: false }).click();

    await expect(
      page.getByRole('button', { name: 'Edit', pressed: true }),
    ).toBeVisible();
    const articleContextualButton = page
      .locator('article')
      .getByRole('button', { name: 'Open configuration options' });
    await expect(articleContextualButton).toBeVisible();

    await articleContextualButton.click();
    // Wait for the contextual menu to appear and verify that edit content template
    // link is not visible as the template is not created.
    await expect(
      page.getByRole('link', { name: 'Edit content template' }),
    ).not.toBeVisible();
  });
  test('Create Article and verify Content Template contextual link', async ({
    page,
    drupal,
  }) => {
    await drupal.loginAsAdmin();
    await page.goto('/canvas');

    // Apply the article template recipe to set up the content template
    const moduleDir = await getModuleDir();
    await drupal.applyRecipe(
      `${moduleDir}/canvas/tests/fixtures/recipes/article_template`,
    );
    await drupal.drush('cr');

    // Navigate to the content page and see if the contextual link is present.
    await page.goto('/admin/content');
    await page.getByRole('link', { name: '+Add content' }).click();
    await page.getByRole('link', { name: 'Article' }).click();
    await page.getByRole('textbox', { name: 'Title *' }).click();
    await page.getByRole('textbox', { name: 'Title *' }).fill('test2');
    await page.getByRole('button', { name: 'Save' }).click();
    await page.getByRole('button', { name: 'Edit', pressed: false }).click();

    await expect(
      page.getByRole('button', { name: 'Edit', pressed: true }),
    ).toBeVisible();

    const articleContextualButton = page
      .locator('article')
      .getByRole('button', { name: 'Open configuration options' });
    await expect(articleContextualButton).toBeVisible();
    await articleContextualButton.click();

    // Wait for the contextual menu to appear and click the "Edit content template" link
    await expect(
      page.getByRole('link', { name: 'Edit content template' }),
    ).toBeVisible();
    await page.getByRole('link', { name: 'Edit content template' }).click();

    // Assert that it navigated to the correct template edit page.
    await expect(page).toHaveURL(/canvas\/template\/node\/article\/full\/\d+/);
  });
});
