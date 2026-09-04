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

  // The assertions live in the page object's expectPageInFolder().
  // eslint-disable-next-line playwright/expect-expect
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

  test('Without "administer folders" folders are visible but not manageable', async ({
    page,
    drupal,
    canvas,
  }) => {
    // Set the folders up as an administrator, who may also create the
    // restricted role and user below — the helpers that do so drive admin
    // forms, so they have to run before logging out.
    await drupal.loginAsAdmin();
    const canvasPage = await canvas.createCanvas({ title: 'Read-only page' });
    await canvas.createCanvas({ title: 'Loose page' });
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

    // Whoever may edit pages is shown how they are organized: serving them no
    // folder would render pages that are filed as if they were not.
    await canvas.expectPageInFolder('Read-only page', 'Managed elsewhere');
    await canvas.expectPageInFolder('Loose page', null);

    // The folder is not theirs to rename, delete or re-order, so it carries no
    // contextual menu at all …
    await expect(
      panel
        .locator('[data-canvas-folder-name="Managed elsewhere"]')
        .getByRole('button', { name: 'Open contextual menu' }),
    ).toHaveCount(0);
    // … and nothing offers to create one.
    await expect(panel.getByTestId('canvas-page-list-new-button')).toHaveCount(
      0,
    );
  });

  test('A folder permission scopes editing to the pages in that folder', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.loginAsAdmin();
    const campaignPage = await canvas.createCanvas({ title: 'Campaign page' });
    await canvas.createCanvas({ title: 'Archive page' });
    await canvas.openPagesPanel();
    await canvas.addPageFolder('Campaigns');
    await canvas.addPageFolder('Archive');
    await canvas.dragPageIntoFolder('Campaign page', 'Campaigns');
    await canvas.dragPageIntoFolder('Archive page', 'Archive');

    // One edit and one delete permission are generated per folder, and they
    // carry the folder's ID: read the folders and the pages back rather than
    // guessing UUIDs.
    // @see \Drupal\canvas\FolderPermissions
    const folderResponse = await page.request.get(
      '/canvas/api/v0/config/folder',
    );
    expect(folderResponse.status()).toBe(200);
    const folders: Record<string, { name: string }> =
      await folderResponse.json();
    const folderId = (name: string) =>
      Object.keys(folders).find((id) => folders[id].name === name);
    expect(folderId('Campaigns')).toBeTruthy();
    expect(folderId('Archive')).toBeTruthy();
    const contentResponse = await page.request.get(
      '/canvas/api/v0/content/canvas_page',
    );
    expect(contentResponse.status()).toBe(200);
    const listed: {
      data: { id: number; title: string; autoSaveLabel?: string | null }[];
    } = await contentResponse.json();
    // A page created here is a draft, so its title lives in the auto-save
    // label until it is published — which is what the Pages panel shows too.
    const archivePageId = listed.data.find(
      (row) => (row.autoSaveLabel ?? row.title) === 'Archive page',
    )?.id;
    expect(archivePageId).toBeTruthy();

    // An editor of one folder and a remover of the other: no site-wide page
    // permission of any kind.
    await drupal.createRole({ name: 'canvas_campaign_editor' });
    await drupal.addPermissions({
      role: 'canvas_campaign_editor',
      permissions: [
        `edit canvas_page in folder ${folderId('Campaigns')}`,
        `delete canvas_page in folder ${folderId('Archive')}`,
      ],
    });
    const user = {
      email: 'campaign.editor@example.com',
      username: 'campaign_editor',
      password: 'superstrongpassword1337',
      roles: ['canvas_campaign_editor'],
    };
    await drupal.createUser(user);
    await drupal.logout();
    await drupal.login(user);

    // The pages of the folder they edit are theirs to open: the Canvas editor
    // loads one of them, with no site-wide permission behind it.
    await canvas.openCanvas(campaignPage);
    await canvas.openPagesPanel();
    await canvas.expectPageInFolder('Campaign page', 'Campaigns');
    // They may see both folders, because they hold a permission for each.
    await canvas.expectPageInFolder('Archive page', 'Archive');

    const panel = page.getByTestId('canvas-page-list-panel');
    // Deleting the pages of a folder is not editing them, so that page is
    // listed and locked: opening it would load an editor that cannot fetch
    // its layout.
    await expect(
      panel.locator(
        '[data-canvas-page-title="Archive page"] [data-canvas-page-locked="true"]',
      ),
    ).toBeVisible();
    await expect(
      panel.locator(
        '[data-canvas-page-title="Campaign page"] [data-canvas-page-locked]',
      ),
    ).toHaveCount(0);
    // And nothing offers to create a page or a folder.
    await expect(panel.getByTestId('canvas-page-list-new-button')).toHaveCount(
      0,
    );

    // Reaching that page's editor by URL says why instead of failing in a loop.
    await page.goto(`/canvas/editor/canvas_page/${archivePageId}`);
    await expect(page.getByTestId('canvas-no-edit-access')).toBeVisible();
  });
});
