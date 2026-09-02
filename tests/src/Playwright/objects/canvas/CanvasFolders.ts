import { expect } from '@playwright/test';

import type { CanvasBase } from './CanvasBase.js';

type Constructor<T = {}> = new (...args: any[]) => T;

export function CanvasFoldersMixin<TBase extends Constructor<CanvasBase>>(
  Base: TBase,
) {
  return class extends Base {
    /***********
     * Folders *
     ***********/
    async addFolder(name: string) {
      // Open the New dropdown
      await expect(
        this.page.getByTestId('canvas-page-list-new-button'),
      ).toBeVisible();
      await this.page.getByTestId('canvas-page-list-new-button').click();

      // Wait for dropdown to be visible
      await expect(
        this.page.getByTestId('canvas-library-new-folder-button'),
      ).toBeVisible();

      // Click Add folder option
      await this.page.getByTestId('canvas-library-new-folder-button').click();

      // Wait for the folder input to appear
      const folderInput = this.page.getByTestId(
        'canvas-manage-library-new-folder-name',
      );
      await expect(folderInput).toBeVisible();
      await folderInput.clear();
      await folderInput.fill(name);
      await folderInput.press('Enter');
      // Wait for folder creation to complete (input should disappear)
      await expect(folderInput).toBeHidden();

      // Verify the folder was created.
      await expect(
        this.page.locator(`[data-canvas-folder-name="${name}"]`),
      ).toBeVisible({ timeout: 15_000 });
    }

    async deleteFolder(name: string) {
      try {
        await this.page.locator(`[data-canvas-folder-name="${name}"]`).hover();
        await this.page
          .locator(`[data-canvas-folder-name="${name}"]`)
          .getByRole('button', { name: 'Menu' })
          .click();

        // Click Delete folder option.
        await this.page
          .getByRole('menuitem', { name: 'Delete folder' })
          .click();

        // Wait for folder to be deleted.
        await expect(
          this.page.locator(`[data-canvas-folder-name="${name}"]`),
        ).not.toBeAttached({ timeout: 10000 });
      } catch (error) {
        throw new Error(
          'deleteFolder: Folder did not delete - is it empty?\n' +
            (error instanceof Error ? error.message : String(error)),
        );
      }
    }

    async expandFolder(name: string) {
      const folder = this.page.locator(`[data-canvas-folder-name="${name}"]`);
      await expect(folder).toBeVisible();

      // Ensure the row is on screen before trying to toggle collapse state.
      await folder.scrollIntoViewIfNeeded();

      const expandToggle = this.page.locator(
        `[aria-label="Expand ${name} folder"]`,
      );
      if ((await expandToggle.count()) > 0) {
        // eslint-disable-next-line playwright/no-force-option
        await expandToggle.first().click({ force: true }); // toggle may be obscured after scroll
      }
    }

    async moveComponentIntoFolder(componentName: string, folder: string) {
      const componentLocator = `[data-testid="canvas-primary-panel"] [data-canvas-name="${componentName}"]`;
      const dropzoneLocator = `[data-testid="canvas-primary-panel"] [data-canvas-folder-name="${folder}"]`;
      await this.drag(componentLocator, dropzoneLocator);
      const newComponentLocation = this.page
        .locator(
          `[data-testid="canvas-primary-panel"] [data-canvas-folder-name="${folder}"]`,
        )
        .locator('..')
        .locator(`[data-canvas-name="${componentName}"]`);

      await expect(newComponentLocation).toBeVisible();
      await expect(newComponentLocation).toContainText(componentName);
    }

    async moveComponentOutOfFolder(componentName: string) {
      const panel = `[data-testid="canvas-primary-panel"]`;
      const componentLocator = `${panel} [data-canvas-name="${componentName}"]`;
      const uncategorized = `${panel} [data-testid="canvas-uncategorized-drop-zone-js_component"]`;
      await this.drag(componentLocator, uncategorized);
      const newComponentLocation = this.page
        .locator(uncategorized)
        .locator(`[data-canvas-name="${componentName}"]`);

      await expect(newComponentLocation).toBeVisible();
      await expect(newComponentLocation).toContainText(componentName);
    }

    /****************
     * Page folders *
     ****************/

    /**
     * Creates a folder from the Pages panel's "New" menu.
     *
     * The library's folder button lives in its own toolbar, so the Pages panel
     * has its own menu item; the name input is shared.
     */
    async addPageFolder(name: string) {
      await this.page.getByTestId('canvas-page-list-new-button').click();
      const newFolderItem = this.page.getByTestId(
        'canvas-page-list-new-folder-button',
      );
      await expect(newFolderItem).toBeVisible();
      await newFolderItem.click();

      const folderInput = this.page.getByTestId(
        'canvas-manage-library-new-folder-name',
      );
      await expect(folderInput).toBeVisible();
      await folderInput.fill(name);

      // Wait on the create request itself: the input only closes once it
      // succeeds, so without this a rejected folder shows up as an opaque
      // "input never hid" timeout instead of the reason the server gave.
      const created = this.page.waitForResponse(
        (response) =>
          response.url().includes('/canvas/api/v0/config/folder') &&
          response.request().method() === 'POST',
      );
      await folderInput.press('Enter');
      const response = await created;
      expect(
        response.status(),
        `POST /canvas/api/v0/config/folder returned ${response.status()}: ${await response.text()}`,
      ).toBe(201);

      await expect(folderInput).toBeHidden();

      await expect(
        this.page.locator(`[data-canvas-folder-name="${name}"]`),
      ).toBeVisible({ timeout: 15_000 });
    }

    /**
     * Asserts which folder a page sits in, or that it sits in none.
     */
    async expectPageInFolder(title: string, folder: string | null) {
      const panel = this.page.getByTestId('canvas-page-list-panel');
      const row = `[data-canvas-page-title="${title}"]`;
      if (folder === null) {
        await expect(
          panel
            .getByTestId('canvas-uncategorized-drop-zone-canvas_page')
            .locator(row),
        ).toBeVisible();
        return;
      }
      await this.expandFolder(folder);
      await expect(
        panel
          .locator(`[data-canvas-folder-name="${folder}"]`)
          .locator('..')
          .locator(row),
      ).toBeVisible();
    }

    /**
     * Drags a page row onto a folder, filing it there.
     */
    async dragPageIntoFolder(title: string, folder: string) {
      const panel = '[data-testid="canvas-page-list-panel"]';
      await this.drag(
        `${panel} [data-canvas-page-title="${title}"]`,
        `${panel} [data-canvas-folder-name="${folder}"]`,
      );
      await this.expectPageInFolder(title, folder);
    }

    /**
     * Drags a page row onto the uncategorized zone, taking it out of its folder.
     */
    async dragPageOutOfFolder(title: string) {
      const panel = '[data-testid="canvas-page-list-panel"]';
      await this.drag(
        `${panel} [data-canvas-page-title="${title}"]`,
        `${panel} [data-testid="canvas-uncategorized-drop-zone-canvas_page"]`,
      );
      await this.expectPageInFolder(title, null);
    }

    /**
     * Files a page through its contextual menu instead of by dragging.
     *
     * @param title
     *   The page's title.
     * @param target
     *   The folder to file it into, or null to take it out of its folder.
     */
    async movePageToFolderViaMenu(title: string, target: string | null) {
      const row = this.page.locator(`[data-canvas-page-title="${title}"]`);
      await row.hover();
      await row.getByRole('button', { name: 'Open contextual menu' }).click();
      await this.page.getByTestId('canvas-page-move-to-folder').click();
      await this.page
        .getByRole('menuitem', {
          name: target ?? 'Remove from folder',
          exact: true,
        })
        .click();
      await this.expectPageInFolder(title, target);
    }

    /**
     * Returns the Pages panel's folder names, in the order they render.
     */
    async getPageFolderOrder(): Promise<string[]> {
      return await this.page
        .getByTestId('canvas-page-list-panel')
        .locator('[data-canvas-folder-name]')
        .evaluateAll((rows) =>
          rows.map(
            (row) => (row as HTMLElement).dataset.canvasFolderName ?? '',
          ),
        );
    }

    /**
     * Re-orders a folder through its contextual menu.
     */
    async movePageFolder(name: string, direction: 'Move up' | 'Move down') {
      const folder = this.page.locator(`[data-canvas-folder-name="${name}"]`);
      await folder.hover();
      await folder.getByRole('button', { name: 'Menu' }).click();
      await this.page
        .getByRole('menuitem', { name: direction, exact: true })
        .click();
    }

    async moveComponentToLibraryLocation(
      componentName: string,
      folderName: string,
    ) {
      const panel = `[data-testid="canvas-primary-panel"]`;
      const componentLocator = `${panel} [data-canvas-name="${componentName}"]`;
      const dropzoneLocator = `${panel} [data-canvas-folder-name="${folderName}"]`;
      await this.drag(componentLocator, dropzoneLocator);
      const newComponentLocation = this.page
        .locator(`${panel} [data-canvas-folder-name="${folderName}"]`)
        .locator('..')
        .locator(`[data-canvas-name="${componentName}"]`);
      await expect(newComponentLocation).toBeVisible();
      await expect(newComponentLocation).toContainText(componentName);
    }
  };
}
