import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

/**
 * Tests that the canvasFormatDate and canvasFormatDateTime Twig filters
 * produce locale-formatted output rather than the raw ISO strings that Canvas
 * passes to templates by default.
 *
 * Uses the canvas_test_date_formatting:date-formatted component, which renders
 * its date and date-time props through the filters so the test layer can
 * inspect the result in the preview iframe.
 */

test.use({
  modules: ['canvas_test_date_formatting'],
  enableTestExtensions: true,
});

test.describe('Date formatting Twig filters', () => {
  test('canvasFormatDate renders a locale-formatted date, not the raw ISO string', async ({
    drupal,
    canvas,
    page,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());
    await canvas.openLibraryPanel();

    await canvas.addComponent({
      id: 'sdc.canvas_test_date_formatting.date-formatted',
    });

    // Set a known date value via the date input in the contextual panel.
    const dateInput = page.locator(
      '[data-testid="canvas-contextual-panel"] .field--name-date input[type="date"]',
    );
    await expect(dateInput).toBeVisible();

    await dateInput.fill('2026-01-15');
    await dateInput.press('Tab');
    // eslint-disable-next-line playwright/no-networkidle
    await page.waitForLoadState('networkidle');

    // The preview must show the en-US short date for Jan 15 2026.
    await canvas.testInPreviewFrame('#date-formatted-date', async (el) => {
      const text = (await el.textContent())?.trim() ?? '';
      expect(text).toBe('1/15/26');
    });
  });

  test('canvasFormatDateTime renders a locale-formatted datetime, not the raw ISO string', async ({
    drupal,
    canvas,
    page,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());
    await canvas.openLibraryPanel();

    await canvas.addComponent({
      id: 'sdc.canvas_test_date_formatting.date-formatted',
    });

    // Set a known datetime value via the date and time inputs.
    const dateInput = page.locator(
      '[data-testid="canvas-contextual-panel"] .field--name-date-time input[type="date"]',
    );
    const timeInput = page.locator(
      '[data-testid="canvas-contextual-panel"] .field--name-date-time input[type="time"]',
    );
    await expect(dateInput).toBeVisible();
    await expect(timeInput).toBeVisible();

    await dateInput.fill('2026-01-15');
    await timeInput.fill('14:30:00');
    await timeInput.press('Tab');
    // eslint-disable-next-line playwright/no-networkidle
    await page.waitForLoadState('networkidle');

    // The preview must show the en-US short datetime for Jan 15 2026 at 14:30
    // UTC.
    await canvas.testInPreviewFrame('#date-formatted-date-time', async (el) => {
      const text =
        (await el.textContent())?.trim().replaceAll('\u202f', ' ') ?? '';
      expect(text).toBe('1/15/26, 2:30 PM');
    });
  });
});
