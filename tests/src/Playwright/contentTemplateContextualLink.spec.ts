/**
 * @file
 * Playwright test for Content Template contextual link functionality.
 *
 * This test uses Playwright instead of a functional test because contextual
 * links are not part of the DOM when JavaScript is disabled. Functional tests
 * run without JavaScript, making it impossible to test contextual link
 * behavior in that environment.
 *
 * @see \Drupal\Tests\contextual\FunctionalJavascript\ContextualLinksTest
 */

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

  test('Verify Content Template contextual link is not available without template', async ({
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

  test('Verify Content Template contextual link visibility based on permissions', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    const moduleDir = await getModuleDir();
    await drupal.applyRecipe(
      `${moduleDir}/canvas/tests/fixtures/recipes/article_content_template`,
    );

    // Create a role without 'administer content templates' permission.
    await drupal.createRole({ name: 'content_editor' });
    await drupal.addPermissions({
      role: 'content_editor',
      permissions: [
        'access content',
        'access contextual links',
        'access toolbar',
        'edit any article content',
      ],
    });
    await drupal.createUser({
      username: 'test_user',
      password: 'test_pass',
      email: 'test@example.com',
      roles: ['content_editor'],
    });
    await drupal.login({
      username: 'test_user',
      password: 'test_pass',
    });

    await page.goto('/i-am-an-empty-node');
    await page.getByRole('button', { name: 'Edit', pressed: false }).click();

    await expect(
      page.getByRole('button', { name: 'Edit', pressed: true }),
    ).toBeVisible();

    let articleContextualButton = page
      .locator('article')
      .getByRole('button', { name: 'Open configuration options' })
      .first();
    await expect(articleContextualButton).toBeVisible();
    await articleContextualButton.click();

    // Verify that the "Edit content template" link is not visible for users
    // without 'administer content templates' permission.
    await expect(
      page.getByRole('link', { name: 'Edit content template' }),
    ).not.toBeVisible();

    await drupal.logout();
    // Now add the 'administer content templates' permission to the same role.
    await drupal.addPermissions({
      role: 'content_editor',
      permissions: ['administer content templates'],
    });
    await drupal.login({
      username: 'test_user',
      password: 'test_pass',
    });

    await page.goto('/i-am-an-empty-node');

    await expect(
      page.getByRole('button', { name: 'Edit', pressed: true }),
    ).toBeVisible();

    articleContextualButton = page
      .locator('article')
      .getByRole('button', { name: 'Open configuration options' })
      .first();
    await expect(articleContextualButton).toBeVisible();
    await articleContextualButton.click();

    // Verify that the "Edit content template" link is now visible after
    // adding 'administer content templates' permission.
    await expect(
      page.getByRole('link', { name: 'Edit content template' }),
    ).toBeVisible();
    await page.getByRole('link', { name: 'Edit content template' }).click();

    // Assert that it navigated to the correct template edit page
    await expect(page).toHaveURL(/canvas\/template\/node\/article\/full\/\d+/);

    // Verify the preview node title appears on the ContentTemplate page,
    // confirming the page loaded successfully.
    await expect(page.getByText('I am an empty node')).toBeVisible();

    // Now enable the ContentTemplate via drush to verify contextual links work
    // with both disabled and enabled templates.
    await drupal.drush(
      'config:set canvas.content_template.node.article.full status true',
    );

    // Navigate back to the node to verify contextual link still works
    // after the template is published.
    await page.goto('/i-am-an-empty-node');

    await expect(
      page.getByRole('button', { name: 'Edit', pressed: true }),
    ).toBeVisible();

    articleContextualButton = page
      .locator('article')
      .getByRole('button', { name: 'Open configuration options' })
      .first();
    await expect(articleContextualButton).toBeVisible();
    await articleContextualButton.click();

    // Verify contextual link still works with published template.
    await expect(
      page.getByRole('link', { name: 'Edit content template' }),
    ).toBeVisible();
    await page.getByRole('link', { name: 'Edit content template' }).click();

    // Assert that it navigated to the correct template edit page
    await expect(page).toHaveURL(/canvas\/template\/node\/article\/full\/\d+/);

    // Verify the page loaded successfully.
    await expect(page.getByText('I am an empty node')).toBeVisible();
  });
});
