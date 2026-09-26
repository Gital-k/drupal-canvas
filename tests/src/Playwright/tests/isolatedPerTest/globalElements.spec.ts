import { readFile } from 'fs/promises';
import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

/**
 * Tests global elements.
 */

test.describe('Global elements', () => {
  test('Page title', async ({ canvas, drupal }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());
    const code = await readFile(
      'tests/fixtures/code_components/page-elements/PageTitle.jsx',
      'utf-8',
    );
    await canvas.createCodeComponent('PageTitle', code);
    const preview = canvas.getCodePreviewFrame();
    // @see \Drupal\canvas\Controller\CanvasController::__invoke
    await expect(
      preview.getByRole('heading', {
        name: 'This is a page title for testing purposes',
      }),
    ).toBeVisible();
  });

  test('Site branding', async ({ canvas, drupal }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());
    const code = await readFile(
      'tests/fixtures/code_components/page-elements/SiteBranding.jsx',
      'utf-8',
    );
    await canvas.createCodeComponent('SiteBranding', code);
    const preview = canvas.getCodePreviewFrame();
    // Site name defaults to 'Drupal'.
    // @see \Drupal\Core\Command\InstallCommand::configure
    await expect(preview.getByRole('link', { name: 'Drupal' })).toBeVisible();
  });

  test('Breadcrumbs', async ({ canvas, drupal }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());
    const code = await readFile(
      `tests/fixtures/code_components/page-elements/Breadcrumbs.jsx`,
      'utf-8',
    );
    await canvas.createCodeComponent('Breadcrumbs', code);
    const preview = canvas.getCodePreviewFrame();
    // @see \Drupal\canvas\Controller\CanvasController::__invoke
    await expect(preview.getByRole('link', { name: 'Home' })).toBeVisible();
    await expect(
      preview.getByRole('link', { name: 'My account' }),
    ).toBeVisible();
    await expect(preview.getByRole('listitem')).toHaveCount(2);
    await expect(
      preview.getByRole('heading', { name: 'Breadcrumb' }),
    ).toBeVisible();
  });

  test('Preview updates when switching components without a full page reload', async ({
    canvas,
    drupal,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());
    const preview = canvas.getCodePreviewFrame();

    const pageTitleCode = await readFile(
      'tests/fixtures/code_components/page-elements/PageTitle.jsx',
      'utf-8',
    );
    await canvas.createCodeComponent('PageTitle', pageTitleCode);
    await expect(
      preview.getByRole('heading', {
        name: 'This is a page title for testing purposes',
      }),
    ).toBeVisible();

    // Creating a second component navigates the code editor route
    // client-side, reusing the same preview iframe instead of a fresh page
    // load. The sandboxed iframe's `postMessage()` handshake with
    // ui/lib/code-editor-preview.js must survive that.
    const siteBrandingCode = await readFile(
      'tests/fixtures/code_components/page-elements/SiteBranding.jsx',
      'utf-8',
    );
    await canvas.createCodeComponent('SiteBranding', siteBrandingCode);
    // Site name defaults to 'Drupal'.
    // @see \Drupal\Core\Command\InstallCommand::configure
    await expect(preview.getByRole('link', { name: 'Drupal' })).toBeVisible();
  });
});
