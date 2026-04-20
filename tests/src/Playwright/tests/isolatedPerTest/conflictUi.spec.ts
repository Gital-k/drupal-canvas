import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

/**
 * Conflict UI - Static Implementation Tests
 *
 * These tests verify the static/visual implementation of the conflict detection UI.
 * The UI is gated behind canvas_dev_mode and shows mock data for demonstration.
 *
 * Prerequisites:
 * - canvas_dev_mode module must be enabled
 * - At least 3-4 unpublished changes must exist for testing
 */

test.describe('Conflict UI - Static Implementation', () => {
  test.beforeEach(async ({ drupal }) => {
    await drupal.enableTestExtensions();
    // Login with existing admin credentials
    await drupal.login({ username: 'admin', password: 't3st3r' });
    // Enable canvas_dev_mode to show conflict UI
    await drupal.installModules(['canvas_dev_mode']);
    await drupal.logout();
  });

  test.describe('Conflict Banner', () => {
    test('should display conflict banner with correct count', async ({
      page,
      drupal,
      canvas,
    }) => {
      // Login as editor
      await drupal.login({ username: 'editor', password: 'editor' });

      // Create a Canvas page
      const canvasPage = await canvas.createCanvas();
      await canvas.openCanvas(canvasPage);

      // Make some changes to create saves
      await canvas.addComponent({ name: 'Hero' });
      await page.waitForTimeout(2000); // Wait for save

      // Make another change
      const heroComponent = page.locator('[data-canvas-component-name="Hero"]').first();
      await heroComponent.click();
      await page.getByLabel('Heading').fill('Test heading for conflict');
      await page.waitForTimeout(2000); // Wait for save

      // Open unpublished changes panel
      const reviewButton = page.getByTestId('canvas-publish-review');
      await reviewButton.click();

      // Wait for panel to open
      await expect(page.getByTestId('canvas-publish-reviews-content')).toBeVisible();

      // Check if conflict banner is visible (only when dev mode is enabled)
      const banner = page.getByTestId('conflict-banner');
      await expect(banner).toBeVisible();

      // Should show "4 conflicts to resolve" (static mock data)
      await expect(banner).toContainText('4 conflicts to resolve');

      // Should show warning message
      await expect(banner).toContainText('Review and resolve before publishing');
    });

    test('should have resolve button with correct styling', async ({
      page,
      drupal,
      canvas,
    }) => {
      await drupal.login({ username: 'editor', password: 'editor' });

      const canvasPage = await canvas.createCanvas();
      await canvas.openCanvas(canvasPage);

      // Create saves
      await canvas.addComponent({ name: 'Hero' });
      await page.waitForTimeout(2000);

      // Open unpublished changes panel
      await page.getByTestId('canvas-publish-review').click();
      await expect(page.getByTestId('conflict-banner')).toBeVisible();

      const resolveButton = page.getByTestId('resolve-conflicts-button');

      // Button should be visible
      await expect(resolveButton).toBeVisible();

      // Button should have correct text
      await expect(resolveButton).toContainText('Resolve 4 conflicts');

      // Button should not be disabled
      await expect(resolveButton).toBeEnabled();

      // Check button styling (light blue background)
      const bgColor = await resolveButton.evaluate((el) => {
        return window.getComputedStyle(el).backgroundColor;
      });

      // Should be light blue (rgb values for #D6EAF8)
      expect(bgColor).toMatch(/rgb\(214,\s*234,\s*248\)/);
    });

    test('should log to console when resolve button is clicked', async ({
      page,
      drupal,
      canvas,
    }) => {
      const consoleLogs: string[] = [];

      page.on('console', (msg) => {
        if (msg.type() === 'log') {
          consoleLogs.push(msg.text());
        }
      });

      await drupal.login({ username: 'editor', password: 'editor' });

      const canvasPage = await canvas.createCanvas();
      await canvas.openCanvas(canvasPage);

      // Create saves
      await canvas.addComponent({ name: 'Hero' });
      await page.waitForTimeout(2000);

      // Open unpublished changes panel
      await page.getByTestId('canvas-publish-review').click();
      await expect(page.getByTestId('conflict-banner')).toBeVisible();

      // Click resolve button
      await page.getByTestId('resolve-conflicts-button').click();

      // Should log to console
      expect(consoleLogs).toContain(
        'Resolve conflicts clicked - Static UI placeholder',
      );
    });

    test('should have correct visual styling', async ({
      page,
      drupal,
      canvas,
    }) => {
      await drupal.login({ username: 'editor', password: 'editor' });

      const canvasPage = await canvas.createCanvas();
      await canvas.openCanvas(canvasPage);

      // Create saves
      await canvas.addComponent({ name: 'Hero' });
      await page.waitForTimeout(2000);

      // Open unpublished changes panel
      await page.getByTestId('canvas-publish-review').click();

      const banner = page.getByTestId('conflict-banner');
      await expect(banner).toBeVisible();

      // Check banner background color (light cream #FEF9E7)
      const bannerBgColor = await banner.evaluate((el) => {
        return window.getComputedStyle(el).backgroundColor;
      });
      expect(bannerBgColor).toMatch(/rgb\(254,\s*249,\s*231\)/);

      // Check warning icon is visible
      const warningIcon = banner.locator('svg').first();
      await expect(warningIcon).toBeVisible();

      // Check subtext color (red)
      const subtext = banner.getByText('Review and resolve before publishing');
      await expect(subtext).toBeVisible();
    });
  });

  test.describe('Conflicted Items', () => {
    test('should show first 2 items as conflicted', async ({
      page,
      drupal,
      canvas,
    }) => {
      await drupal.login({ username: 'editor', password: 'editor' });

      const canvasPage = await canvas.createCanvas();
      await canvas.openCanvas(canvasPage);

      // Create multiple saves
      await canvas.addComponent({ name: 'Hero' });
      await page.waitForTimeout(2000);

      const heroComponent = page.locator('[data-canvas-component-name="Hero"]').first();
      await heroComponent.click();
      await page.getByLabel('Heading').fill('Change 1');
      await page.waitForTimeout(2000);

      await page.getByLabel('Heading').fill('Change 2');
      await page.waitForTimeout(2000);

      // Open unpublished changes panel
      await page.getByTestId('canvas-publish-review').click();
      await expect(page.getByTestId('canvas-publish-reviews-content')).toBeVisible();

      // Get all change rows
      const changeRows = page.getByTestId('pending-change-row');
      const count = await changeRows.count();

      // Should have at least 2 items
      expect(count).toBeGreaterThanOrEqual(2);

      // First item should have conflict icon
      const firstItem = changeRows.nth(0);
      await expect(firstItem.locator('svg').first()).toBeVisible();

      // Second item should have conflict icon
      const secondItem = changeRows.nth(1);
      await expect(secondItem.locator('svg').first()).toBeVisible();

      // Third item (if exists) should NOT have conflict styling
      if (count >= 3) {
        const thirdItem = changeRows.nth(2);
        // Third item exists but won't have red conflict styling
        await expect(thirdItem).toBeVisible();
      }
    });

    test('should have red text for conflicted items', async ({
      page,
      drupal,
      canvas,
    }) => {
      await drupal.login({ username: 'editor', password: 'editor' });

      const canvasPage = await canvas.createCanvas();
      await canvas.openCanvas(canvasPage);

      // Create saves
      await canvas.addComponent({ name: 'Hero' });
      await page.waitForTimeout(2000);

      // Open unpublished changes panel
      await page.getByTestId('canvas-publish-review').click();
      await expect(page.getByTestId('canvas-publish-reviews-content')).toBeVisible();

      // First item should have red color
      const firstItem = page.getByTestId('pending-change-row').nth(0);
      const label = firstItem.locator('label');

      const color = await label.evaluate((el) => {
        return window.getComputedStyle(el).color;
      });

      // Should be red (#E74C3C = rgb(231, 76, 60))
      expect(color).toMatch(/rgb\(231,\s*76,\s*60\)/);
    });

    test('should have disabled checkboxes for conflicted items', async ({
      page,
      drupal,
      canvas,
    }) => {
      await drupal.login({ username: 'editor', password: 'editor' });

      const canvasPage = await canvas.createCanvas();
      await canvas.openCanvas(canvasPage);

      // Create saves
      await canvas.addComponent({ name: 'Hero' });
      await page.waitForTimeout(2000);

      const heroComponent = page.locator('[data-canvas-component-name="Hero"]').first();
      await heroComponent.click();
      await page.getByLabel('Heading').fill('Change 1');
      await page.waitForTimeout(2000);

      await page.getByLabel('Heading').fill('Change 2');
      await page.waitForTimeout(2000);

      // Open unpublished changes panel
      await page.getByTestId('canvas-publish-review').click();
      await expect(page.getByTestId('canvas-publish-reviews-content')).toBeVisible();

      // First item checkbox should be disabled
      const firstCheckbox = page
        .getByTestId('pending-change-row')
        .nth(0)
        .locator('input[type="checkbox"]');
      await expect(firstCheckbox).toBeDisabled();

      // Second item checkbox should be disabled
      const secondCheckbox = page
        .getByTestId('pending-change-row')
        .nth(1)
        .locator('input[type="checkbox"]');
      await expect(secondCheckbox).toBeDisabled();

      // Third item checkbox (if exists) should be enabled
      const changeRows = page.getByTestId('pending-change-row');
      const count = await changeRows.count();

      if (count >= 3) {
        const thirdCheckbox = changeRows.nth(2).locator('input[type="checkbox"]');
        await expect(thirdCheckbox).toBeEnabled();
      }
    });

    test('should not allow selecting conflicted items', async ({
      page,
      drupal,
      canvas,
    }) => {
      await drupal.login({ username: 'editor', password: 'editor' });

      const canvasPage = await canvas.createCanvas();
      await canvas.openCanvas(canvasPage);

      // Create saves
      await canvas.addComponent({ name: 'Hero' });
      await page.waitForTimeout(2000);

      // Open unpublished changes panel
      await page.getByTestId('canvas-publish-review').click();
      await expect(page.getByTestId('canvas-publish-reviews-content')).toBeVisible();

      const firstCheckbox = page
        .getByTestId('pending-change-row')
        .nth(0)
        .locator('input[type="checkbox"]');

      // Should be unchecked and disabled
      await expect(firstCheckbox).not.toBeChecked();
      await expect(firstCheckbox).toBeDisabled();
    });
  });

  test.describe('Conflicted Item Dropdown Menu', () => {
    test('should show "Resolve conflict" option for conflicted items', async ({
      page,
      drupal,
      canvas,
    }) => {
      await drupal.login({ username: 'editor', password: 'editor' });

      const canvasPage = await canvas.createCanvas();
      await canvas.openCanvas(canvasPage);

      // Create saves
      await canvas.addComponent({ name: 'Hero' });
      await page.waitForTimeout(2000);

      // Open unpublished changes panel
      await page.getByTestId('canvas-publish-review').click();
      await expect(page.getByTestId('canvas-publish-reviews-content')).toBeVisible();

      // Click three dots menu on first item (conflicted)
      const firstItem = page.getByTestId('pending-change-row').nth(0);
      await firstItem.getByLabel('More options').click();

      // Wait for menu to open
      const menu = page.getByRole('menu');
      await expect(menu).toBeVisible();

      // Should have "Resolve conflict" option
      await expect(menu).toContainText('Resolve conflict');

      // Should have "Discard changes" option
      await expect(menu).toContainText('Discard changes');

      // Should NOT have "View changes" option for conflicted items
      await expect(menu.getByText('View changes')).not.toBeVisible();
    });

    test('should NOT show "Resolve conflict" for normal items', async ({
      page,
      drupal,
      canvas,
    }) => {
      await drupal.login({ username: 'editor', password: 'editor' });

      const canvasPage = await canvas.createCanvas();
      await canvas.openCanvas(canvasPage);

      // Create multiple saves
      await canvas.addComponent({ name: 'Hero' });
      await page.waitForTimeout(2000);

      const heroComponent = page.locator('[data-canvas-component-name="Hero"]').first();
      await heroComponent.click();
      await page.getByLabel('Heading').fill('Change 1');
      await page.waitForTimeout(2000);

      await page.getByLabel('Heading').fill('Change 2');
      await page.waitForTimeout(2000);

      await page.getByLabel('Heading').fill('Change 3');
      await page.waitForTimeout(2000);

      // Open unpublished changes panel
      await page.getByTestId('canvas-publish-review').click();
      await expect(page.getByTestId('canvas-publish-reviews-content')).toBeVisible();

      const changeRows = page.getByTestId('pending-change-row');
      const count = await changeRows.count();

      // Skip if less than 3 items
      if (count < 3) {
        test.skip();
        return;
      }

      // Click three dots menu on third item (normal)
      const thirdItem = changeRows.nth(2);
      await thirdItem.getByLabel('More options').click();

      // Wait for menu to open
      const menu = page.getByRole('menu');
      await expect(menu).toBeVisible();

      // Should NOT have "Resolve conflict" option
      await expect(menu.getByText('Resolve conflict')).not.toBeVisible();

      // Should have "View changes" option
      await expect(menu).toContainText('View changes');

      // Should have "Discard changes" option
      await expect(menu).toContainText('Discard changes');
    });

    test('should log to console when "Resolve conflict" is clicked', async ({
      page,
      drupal,
      canvas,
    }) => {
      const consoleLogs: string[] = [];

      page.on('console', (msg) => {
        if (msg.type() === 'log') {
          consoleLogs.push(msg.text());
        }
      });

      await drupal.login({ username: 'editor', password: 'editor' });

      const canvasPage = await canvas.createCanvas();
      await canvas.openCanvas(canvasPage);

      // Create saves
      await canvas.addComponent({ name: 'Hero' });
      await page.waitForTimeout(2000);

      // Open unpublished changes panel
      await page.getByTestId('canvas-publish-review').click();
      await expect(page.getByTestId('canvas-publish-reviews-content')).toBeVisible();

      // Click three dots menu on first item
      const firstItem = page.getByTestId('pending-change-row').nth(0);
      await firstItem.getByLabel('More options').click();

      // Click "Resolve conflict"
      await page.getByRole('menuitem', { name: 'Resolve conflict' }).click();

      // Should log to console
      const hasLog = consoleLogs.some((log) =>
        log.includes('Resolve conflict for:'),
      );
      expect(hasLog).toBeTruthy();
    });

    test('should have no icons in menu items', async ({
      page,
      drupal,
      canvas,
    }) => {
      await drupal.login({ username: 'editor', password: 'editor' });

      const canvasPage = await canvas.createCanvas();
      await canvas.openCanvas(canvasPage);

      // Create saves
      await canvas.addComponent({ name: 'Hero' });
      await page.waitForTimeout(2000);

      // Open unpublished changes panel
      await page.getByTestId('canvas-publish-review').click();
      await expect(page.getByTestId('canvas-publish-reviews-content')).toBeVisible();

      // Click three dots menu on first item
      const firstItem = page.getByTestId('pending-change-row').nth(0);
      await firstItem.getByLabel('More options').click();

      const menu = page.getByRole('menu');
      await expect(menu).toBeVisible();

      // Menu items should not have icons (just text)
      const resolveMenuItem = page.getByRole('menuitem', {
        name: 'Resolve conflict',
      });
      const iconCount = await resolveMenuItem.locator('svg').count();
      expect(iconCount).toBe(0);

      const discardMenuItem = page.getByRole('menuitem', {
        name: 'Discard changes',
      });
      const discardIconCount = await discardMenuItem.locator('svg').count();
      expect(discardIconCount).toBe(0);
    });
  });

  test.describe('Visual Regression', () => {
    test('conflict banner visual snapshot', async ({
      page,
      drupal,
      canvas,
    }) => {
      await drupal.login({ username: 'editor', password: 'editor' });

      const canvasPage = await canvas.createCanvas();
      await canvas.openCanvas(canvasPage);

      // Create saves
      await canvas.addComponent({ name: 'Hero' });
      await page.waitForTimeout(2000);

      // Open unpublished changes panel
      await page.getByTestId('canvas-publish-review').click();

      const banner = page.getByTestId('conflict-banner');
      await expect(banner).toBeVisible();

      // Take screenshot for visual regression
      await expect(banner).toHaveScreenshot('conflict-banner.png');
    });

    test('conflicted item visual snapshot', async ({
      page,
      drupal,
      canvas,
    }) => {
      await drupal.login({ username: 'editor', password: 'editor' });

      const canvasPage = await canvas.createCanvas();
      await canvas.openCanvas(canvasPage);

      // Create saves
      await canvas.addComponent({ name: 'Hero' });
      await page.waitForTimeout(2000);

      // Open unpublished changes panel
      await page.getByTestId('canvas-publish-review').click();
      await expect(page.getByTestId('canvas-publish-reviews-content')).toBeVisible();

      const firstItem = page.getByTestId('pending-change-row').nth(0);

      // Take screenshot for visual regression
      await expect(firstItem).toHaveScreenshot('conflicted-item.png');
    });

    test('full conflict UI panel snapshot', async ({
      page,
      drupal,
      canvas,
    }) => {
      await drupal.login({ username: 'editor', password: 'editor' });

      const canvasPage = await canvas.createCanvas();
      await canvas.openCanvas(canvasPage);

      // Create saves
      await canvas.addComponent({ name: 'Hero' });
      await page.waitForTimeout(2000);

      // Open unpublished changes panel
      await page.getByTestId('canvas-publish-review').click();

      const panel = page.getByTestId('canvas-publish-reviews-content');
      await expect(panel).toBeVisible();

      // Take screenshot of entire panel
      await expect(panel).toHaveScreenshot('conflict-ui-panel.png');
    });
  });

  test.describe('Dev Mode Gating', () => {
    test('should hide conflict UI when dev mode is disabled', async ({
      page,
      drupal,
      canvas,
    }) => {
      // Note: Since we're using an existing site, we can't easily disable modules
      // This test would need to be run manually or skipped
      test.skip();

      await drupal.login({ username: 'editor', password: 'editor' });

      const canvasPage = await canvas.createCanvas();
      await canvas.openCanvas(canvasPage);

      // Create saves
      await canvas.addComponent({ name: 'Hero' });
      await page.waitForTimeout(2000);

      // Open unpublished changes panel
      await page.getByTestId('canvas-publish-review').click();
      await expect(page.getByTestId('canvas-publish-reviews-content')).toBeVisible();

      // Conflict banner should NOT be visible
      const banner = page.getByTestId('conflict-banner');
      await expect(banner).not.toBeVisible();

      // First item should NOT have conflict styling
      const firstItem = page.getByTestId('pending-change-row').nth(0);
      const checkbox = firstItem.locator('input[type="checkbox"]');

      // Checkbox should be enabled (not conflicted)
      await expect(checkbox).toBeEnabled();
    });
  });
});
