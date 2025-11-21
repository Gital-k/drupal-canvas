import { expect } from '@playwright/test';

import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';

test.describe('I like prop types and I cannot lie', () => {
  test.beforeAll(
    'Setup test site with Drupal Canvas',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.installModules([
        'canvas',
        'canvas_test_sdc',
        'sdc_test_all_props',
      ]);
      await page.close();
    },
  );

  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();
  });

  test('String, format=iri-reference', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    await drupal.createCanvasPage('iri', '/iri');
    await page.goto('/iri');
    await page.setViewportSize({ width: 1920, height: 1440 });
    await canvasEditor.goToEditor();
    await canvasEditor.openLibraryPanel();
    await canvasEditor.addComponent({ id: 'sdc.sdc_test_all_props.all-props' });
    const initialValue = '/example-iri';
    await expect(await page.getByTestId('iri-reference')).toContainText(
      initialValue,
    );
    const input = await page.getByLabel('String, format=iri-reference');
    await expect(input).toHaveValue(initialValue);
    await input.clear({ force: true });
    await input.blur();
    await expect(input).toHaveValue('');
    let valid = await input.evaluate((el: HTMLFormElement) =>
      el.checkValidity(),
    );
    await expect(valid).toBe(true);
    await expect(await page.getByTestId('iri-reference')).not.toContainText(
      initialValue,
    );
    await expect(
      await page
        .getByTestId('iri-reference')
        .textContent()
        .then((text) => text.trim()),
    ).toEqual('');
    await input.fill('not');
    await expect(input).toHaveValue('not');
    valid = await input.evaluate((el: HTMLFormElement) => el.checkValidity());
    await expect(valid).toBe(false);
    await input.clear({ force: true });
    await input.fill('/whatever');
    await input.blur();
    await expect(await page.getByTestId('iri-reference')).toContainText(
      '/whatever',
    );
  });
});
