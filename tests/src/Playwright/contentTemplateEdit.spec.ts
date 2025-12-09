import { expect } from '@playwright/test';
import { test } from './fixtures/DrupalSite';
import {Drupal} from "./objects/Drupal";

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
  test('Create Article and verify Content Template contextual link', async ({ page, drupal }) => {
    await drupal.loginAsAdmin();
    await page.goto('/canvas');

    await page.getByRole('button', { name: 'Templates' }).click();
    await page.getByTestId('big-add-template-button').click();
    const modal = page.getByRole('dialog');
    await expect(modal).toBeVisible();
    await expect(modal.getByText('Creates a new template for')).toBeVisible();

    const contentTypeCombobox = modal.getByRole('combobox', { name: 'Content type' });
    await expect(contentTypeCombobox).toBeVisible();
    await expect(contentTypeCombobox).toBeEnabled();
    await contentTypeCombobox.click();
    // Dropdown options render outside the modal, use page context
    await expect(page.getByLabel('Article', { exact: true }).getByText('Article')).toBeVisible();
    await page.getByLabel('Article', { exact: true }).getByText('Article').click();
    await modal.getByRole('combobox', { name: 'Template' }).click();
    await expect(page.getByLabel('Full content', { exact: true }).getByText('Full content')).toBeVisible();
    await page.getByLabel('Full content', { exact: true }).getByText('Full content').click();
    await modal.getByRole('button', { name: 'Add new template' }).click();

    // Navigate to the content page and see if the contextual link is present.
    await page.goto('/admin/content');
    await page.getByRole('link', { name: '+Add content' }).click();
    await page.getByRole('link', { name: 'Article' }).click();
    await page.getByRole('textbox', { name: 'Title *' }).click();
    await page.getByRole('textbox', { name: 'Title *' }).fill('test1');
    await page.getByRole('button', { name: 'Save' }).click();
    await page.getByRole('button', { name: 'Edit', pressed: false }).click();

    await expect(page.getByRole('button', { name: 'Edit', pressed: true })).toBeVisible();

    const articleContextualButton = page.locator('article').getByRole('button', { name: 'Open configuration options' });
    await expect(articleContextualButton).toBeVisible();
    await articleContextualButton.click();

    // Wait for the contextual menu to appear and click the "Edit content template" link
    await expect(page.getByRole('link', { name: 'Edit content template' })).toBeVisible();
    await page.getByRole('link', { name: 'Edit content template' }).click();

    // Assert that it navigated to the correct template edit page.
    await expect(page).toHaveURL(/canvas\/template\/node\/article\/full\/\d+/);
  });
});
