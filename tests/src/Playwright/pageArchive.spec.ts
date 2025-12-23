import { expect } from '@playwright/test';

import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

test.describe('Canvas Page Archive/Publish', () => {
  test.beforeAll(
    'Setup test site with Drupal Canvas',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.installModules(['canvas']);
      await page.close();
    },
  );

  test('Page archive and publish workflow', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    await drupal.loginAsAdmin();
    await drupal.createCanvasPage('Archive Test Page', '/archive-test');
    await page.goto('/archive-test');
    await canvasEditor.goToEditor();

    // Verify a published page can be archived.
    await page.getByTestId('canvas-navigation-button').click();
    await expect(page.locator('#canvas-navigation-search')).toBeVisible();

    let dropdownButton = page.getByLabel('Page options for Archive Test Page');
    await dropdownButton.click({ force: true });

    let contextMenu = page.getByRole('menu', {
      name: 'Page options for Archive Test Page',
    });
    await expect(contextMenu).toBeVisible();

    await expect(contextMenu.getByText('Archive page')).toBeVisible();
    await contextMenu.getByText('Archive page').click();

    await page.waitForTimeout(1000);

    await page.getByTestId('canvas-navigation-button').click();

    let pageListItem = page
      .getByTestId('canvas-navigation-results')
      .filter({ hasText: 'Archive Test Page' });
    await expect(pageListItem).toBeVisible();
    await expect(pageListItem.getByText('Archived')).toBeVisible();

    // Verify an archived page can be published.
    await page.getByTestId('canvas-navigation-button').click();
    await expect(page.locator('#canvas-navigation-search')).toBeVisible();

    dropdownButton = page.getByLabel('Page options for Archive Test Page');
    await dropdownButton.click({ force: true });

    contextMenu = page.getByRole('menu', {
      name: 'Page options for Archive Test Page',
    });
    await expect(contextMenu).toBeVisible();

    await expect(contextMenu.getByText('Publish page')).toBeVisible();
    await contextMenu.getByText('Publish page').click({ force: true });

    await page.waitForTimeout(1000);

    await page.getByTestId('canvas-navigation-button').click();
    await page.waitForTimeout(500);
    await page.getByTestId('canvas-navigation-button').click();
    await expect(page.locator('#canvas-navigation-search')).toBeVisible();

    pageListItem = page
      .getByTestId('canvas-navigation-results')
      .filter({ hasText: 'Archive Test Page' });
    await expect(pageListItem).toBeVisible();
    await expect(pageListItem.getByText('Archived')).not.toBeVisible();

    await page.getByTestId('canvas-navigation-button').click();

    // Verify draft pages cannot be archived or published.
    await drupal.createCanvasPage('Draft Test Page', '/draft-test', false);

    await page.goto('/draft-test');
    await canvasEditor.goToEditor();

    await page.getByTestId('canvas-navigation-button').click();
    await expect(page.locator('#canvas-navigation-search')).toBeVisible();

    const draftPageItem = page
      .getByTestId('canvas-navigation-results')
      .filter({ hasText: 'Draft Test Page' })
      .first();

    await expect(draftPageItem).toBeVisible();

    dropdownButton = page.getByLabel('Page options for Draft Test Page');
    await dropdownButton.click({ force: true });

    contextMenu = page.getByRole('menu', {
      name: 'Page options for Draft Test Page',
    });
    await expect(contextMenu).toBeVisible();

    await expect(contextMenu.getByText('Archive page')).not.toBeAttached();
    await expect(contextMenu.getByText('Publish page')).not.toBeAttached();

    // Verify homepage cannot be archived.
    await drupal.createCanvasPage('Homepage Test', '/homepage-test');

    await page.goto('/homepage-test');
    await canvasEditor.goToEditor();

    await page.getByTestId('canvas-navigation-button').click();
    await expect(page.locator('#canvas-navigation-search')).toBeVisible();

    dropdownButton = page.getByLabel('Page options for Homepage Test');
    await dropdownButton.click({ force: true });

    contextMenu = page.getByRole('menu', {
      name: 'Page options for Homepage Test',
    });
    await expect(contextMenu).toBeVisible();

    await expect(contextMenu.getByText('Set as homepage')).toBeVisible();
    await contextMenu.getByText('Set as homepage').click();

    await expect(
      page.getByRole('button', { name: 'Review 1 change' }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Review 1 change' }).click();

    await expect(
      page.getByTestId('canvas-publish-review-select-all'),
    ).toBeVisible();
    await page.getByTestId('canvas-publish-review-select-all').check();

    await page.getByRole('button', { name: 'Publish 1 selected' }).click();

    await page.waitForTimeout(1000);

    await page.getByTestId('canvas-navigation-button').click();
    await expect(page.locator('#canvas-navigation-search')).toBeVisible();

    dropdownButton = page.getByLabel('Page options for Homepage Test');
    await dropdownButton.click({ force: true });

    contextMenu = page.getByRole('menu', {
      name: 'Page options for Homepage Test',
    });
    await expect(contextMenu).toBeVisible();
    await expect(contextMenu.getByText('Duplicate page')).toBeAttached();
    await expect(contextMenu.getByText('Delete page')).not.toBeAttached();
    await expect(contextMenu.getByText('Publish page')).not.toBeAttached();
    await expect(contextMenu.getByText('Archive page')).not.toBeAttached();
  });
});
