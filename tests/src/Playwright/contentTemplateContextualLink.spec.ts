import { getModuleDir } from '@drupal-canvas/test-utils';
import { expect } from '@playwright/test';

import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';
test.describe('Content Template Contextual Link', () => {
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
  test('Create Article and verify Content Template contextual link is not available', async ({
    page,
    drupal,
  }) => {
    await drupal.loginAsAdmin();
    await page.goto('/i-am-an-empty-node');
    await page.getByRole('button', { name: 'Edit', pressed: false }).click();

    await expect(
      page.getByRole('button', { name: 'Edit', pressed: true }),
    ).toBeVisible();
    const articleContextualButton = page
      .locator('article')
      .getByRole('button', { name: 'Open configuration options' })
      .first();
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
      `${moduleDir}/canvas/tests/fixtures/recipes/article_content_template`,
    );

    await page.goto('/i-am-an-empty-node');
    await page.getByRole('button', { name: 'Edit', pressed: false }).click();

    await expect(
      page.getByRole('button', { name: 'Edit', pressed: true }),
    ).toBeVisible();

    const articleContextualButton = page
      .locator('article')
      .getByRole('button', { name: 'Open configuration options' })
      .first();
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

  test('Verify users without administer content templates permission cannot see Edit content template link', async ({
    page,
    drupal,
  }) => {
    await drupal.loginAsAdmin();
    const moduleDir = await getModuleDir();
    await drupal.applyRecipe(
      `${moduleDir}/canvas/tests/fixtures/recipes/article_content_template`,
    );

    // Create a role without 'administer content templates' permission
    await drupal.createRole({ name: 'limited_editor' });
    await drupal.addPermissions({
      role: 'limited_editor',
      permissions: [
        'access content',
        'access contextual links',
        'access toolbar',
        'edit any article content',
      ],
    });
    await drupal.createUser({
      username: 'limited_user',
      password: 'limited_pass',
      email: 'limited@example.com',
      roles: ['limited_editor'],
    });
    await drupal.logout();
    await drupal.login({
      username: 'limited_user',
      password: 'limited_pass',
    });

    await page.goto('/i-am-an-empty-node');
    await page.getByRole('button', { name: 'Edit', pressed: false }).click();

    await expect(
      page.getByRole('button', { name: 'Edit', pressed: true }),
    ).toBeVisible();

    const articleContextualButton = page
      .locator('article')
      .getByRole('button', { name: 'Open configuration options' })
      .first();
    await expect(articleContextualButton).toBeVisible();
    await articleContextualButton.click();

    // Verify that the "Edit content template" link is not visible for users
    // without 'administer content templates' permission
    await expect(
      page.getByRole('link', { name: 'Edit content template' }),
    ).not.toBeVisible();
  });
});
