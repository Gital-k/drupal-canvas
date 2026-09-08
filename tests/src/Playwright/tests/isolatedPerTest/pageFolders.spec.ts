import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

test.use({ modules: ['canvas_test_sdc'], enableTestExtensions: true });

/**
 * Tests organizing Canvas pages into folders from the Pages panel.
 *
 * @see \Drupal\canvas\Entity\FolderTargetInterface
 */
test.describe('Page folders', () => {
  test('Pages list stays flat until a page folder exists', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas({ title: 'Unsorted page' });
    await canvas.openPagesPanel();

    const panel = page.getByTestId('canvas-page-list-panel');
    // No folders yet: no folder rows, no uncategorized zone, just the page.
    await expect(panel.locator('[data-canvas-folder-name]')).toHaveCount(0);
    await expect(
      panel.getByTestId('canvas-uncategorized-drop-zone-canvas_page'),
    ).toHaveCount(0);
    await expect(
      panel.locator('[data-canvas-page-title="Unsorted page"]'),
    ).toBeVisible();

    // Creating a folder groups the list: the page is now uncategorized.
    await canvas.addPageFolder('Campaigns');
    await canvas.expectPageInFolder('Unsorted page', null);
  });

  test('Filing a page by dragging it and through its menu', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas({ title: 'Spring sale' });
    await canvas.openPagesPanel();

    await canvas.addPageFolder('Campaigns');
    await canvas.addPageFolder('Evergreen');

    // Drag the page into a folder, out again, then into the other folder.
    await canvas.dragPageIntoFolder('Spring sale', 'Campaigns');
    await canvas.dragPageOutOfFolder('Spring sale');
    await canvas.dragPageIntoFolder('Spring sale', 'Evergreen');

    // The menu is the keyboard-reachable equivalent of those drags.
    await canvas.movePageToFolderViaMenu('Spring sale', 'Campaigns');
    await canvas.movePageToFolderViaMenu('Spring sale', null);
    await canvas.movePageToFolderViaMenu('Spring sale', 'Campaigns');

    // Membership is stored on the page, so it survives a reload.
    await page.reload();
    await canvas.openPagesPanel();
    await canvas.expectPageInFolder('Spring sale', 'Campaigns');
  });

  test('Re-ordering page folders', async ({ page, drupal, canvas }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas({ title: 'Any page' });
    await canvas.openPagesPanel();

    // Each new folder is added above the previous one.
    await canvas.addPageFolder('First');
    await canvas.addPageFolder('Second');
    await expect(async () => {
      expect(await canvas.getPageFolderOrder()).toEqual(['Second', 'First']);
    }).toPass({ timeout: 10_000 });

    // Move up/down keeps re-ordering reachable without a pointer.
    await canvas.movePageFolder('First', 'Move up');
    await expect(async () => {
      expect(await canvas.getPageFolderOrder()).toEqual(['First', 'Second']);
    }).toPass({ timeout: 10_000 });

    // Dragging one folder onto another does the same.
    const panel = '[data-testid="canvas-page-list-panel"]';
    await canvas.drag(
      `${panel} [data-canvas-folder-name="Second"]`,
      `${panel} [data-canvas-folder-name="First"]`,
    );
    await expect(async () => {
      expect(await canvas.getPageFolderOrder()).toEqual(['Second', 'First']);
    }).toPass({ timeout: 10_000 });

    // Weights are stored config, so the order survives a reload.
    await page.reload();
    await canvas.openPagesPanel();
    await expect(async () => {
      expect(await canvas.getPageFolderOrder()).toEqual(['Second', 'First']);
    }).toPass({ timeout: 10_000 });
  });

  test('A folder holding pages cannot be deleted', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas({ title: 'Filed page' });
    await canvas.openPagesPanel();

    await canvas.addPageFolder('Holds a page');
    await canvas.addPageFolder('Holds nothing');
    await canvas.dragPageIntoFolder('Filed page', 'Holds a page');

    // Delete is offered but refused while the folder still holds a page.
    const occupied = page.locator('[data-canvas-folder-name="Holds a page"]');
    await occupied.hover();
    await occupied.getByRole('button', { name: 'Menu' }).click();
    const deleteItem = page.getByRole('menuitem', { name: 'Delete folder' });
    await expect(deleteItem).toBeVisible();
    await expect(deleteItem).toBeDisabled();
    await page.keyboard.press('Escape');

    // An empty one deletes, and its pages are unaffected.
    await canvas.deleteFolder('Holds nothing');
    await canvas.expectPageInFolder('Filed page', 'Holds a page');

    // Once the page leaves, the folder can go too.
    await canvas.dragPageOutOfFolder('Filed page');
    await canvas.deleteFolder('Holds a page');
    await expect(
      page
        .getByTestId('canvas-page-list-panel')
        .locator('[data-canvas-folder-name]'),
    ).toHaveCount(0);
    await expect(
      page.locator('[data-canvas-page-title="Filed page"]'),
    ).toBeVisible();
  });

  test('Without "administer folders" the Pages panel falls back to a flat list', async ({
    page,
    drupal,
    canvas,
  }) => {
    // Set the folders up as an administrator, who may also create the
    // restricted role and user below — the helpers that do so drive admin
    // forms, so they have to run before logging out.
    await drupal.loginAsAdmin();
    const canvasPage = await canvas.createCanvas({ title: 'Read-only page' });
    await canvas.openPagesPanel();
    await canvas.addPageFolder('Managed elsewhere');
    await canvas.dragPageIntoFolder('Read-only page', 'Managed elsewhere');

    // A user who may edit pages but not administer folders.
    await drupal.createRole({ name: 'canvas_page_editor_only' });
    await drupal.addPermissions({
      role: 'canvas_page_editor_only',
      permissions: ['edit canvas_page'],
    });
    const user = {
      email: 'page.editor@example.com',
      username: 'editor_no_folders',
      password: 'superstrongpassword1337',
      roles: ['canvas_page_editor_only'],
    };
    await drupal.createUser(user);
    await drupal.logout();
    await drupal.login(user);
    await canvas.openCanvas(canvasPage);
    await canvas.openPagesPanel();

    const panel = page.getByTestId('canvas-page-list-panel');

    // A Folder is only viewable with `administer folders`, so this user is
    // served none and the panel renders the same flat list it did before any
    // folder existed — the page itself is still listed and editable.
    await expect(
      panel.locator('[data-canvas-page-title="Read-only page"]'),
    ).toBeVisible();
    await expect(panel.locator('[data-canvas-folder-name]')).toHaveCount(0);
    await expect(
      panel.getByTestId('canvas-uncategorized-drop-zone-canvas_page'),
    ).toHaveCount(0);

    // And nothing offers to create a folder.
    await expect(panel.getByTestId('canvas-page-list-new-button')).toHaveCount(
      0,
    );
  });
});
