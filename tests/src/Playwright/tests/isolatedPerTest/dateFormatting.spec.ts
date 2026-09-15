import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

/**
 * Tests that the canvasFormatDate and canvasFormatDateTime Twig filters
 * produce locale-formatted output rather than the raw ISO strings that Canvas
 * passes to templates by default.
 *
 * Uses the canvas_test_date_formatting:date-formatted component, which renders its date
 * and date-time props through the filters so the Playwright layer can inspect
 * the result in the preview iframe.
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

    const autoSavePromise = page.waitForResponse(
      (response) =>
        response.url().includes('/canvas/api/v0/layout/canvas_page/') &&
        response.request().method() === 'PATCH',
    );
    await dateInput.fill('2026-01-15');
    await dateInput.press('Tab');
    await autoSavePromise;

    // The preview must show a formatted date — not the raw ISO string.
    await canvas.testInPreviewFrame('#date-formatted-date', async (el) => {
      const text = await el.textContent();
      expect(text?.trim()).not.toBe('2026-01-15');
      expect(text?.trim()).not.toBe('');
      // A formatted date contains a digit separator (/, -, or .).
      expect(text?.trim()).toMatch(/\d+[/\-.]\d+/);
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

    const autoSavePromise = page.waitForResponse(
      (response) =>
        response.url().includes('/canvas/api/v0/layout/canvas_page/') &&
        response.request().method() === 'PATCH',
    );
    await dateInput.fill('2026-01-15');
    await timeInput.fill('14:30:00');
    await timeInput.press('Tab');
    await autoSavePromise;

    // The preview must show a formatted datetime — not the raw ISO string.
    await canvas.testInPreviewFrame('#date-formatted-date-time', async (el) => {
      const text = await el.textContent();
      expect(text?.trim()).not.toBe('2026-01-15T14:30:00Z');
      expect(text?.trim()).not.toBe('');
      // A formatted date+time contains a digit separator.
      expect(text?.trim()).toMatch(/\d+[/\-.]\d+/);
    });
  });
});
