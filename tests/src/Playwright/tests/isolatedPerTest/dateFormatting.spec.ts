import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

/**
 * Tests that the canvasFormatDate and canvasFormatDateTime Twig filters
 * produce locale-formatted output rather than the raw ISO strings that Canvas
 * passes to templates.
 */

test.use({
  modules: ['canvas_test_date_formatting'],
  enableTestExtensions: true,
});

test.describe('Date formatting Twig filters', () => {
  test('date and date-time props are rendered through locale formatting filters', async ({
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

    // Set a known date value.
    const dateInput = page.locator(
      '[data-testid="canvas-contextual-panel"] .field--name-date input[type="date"]',
    );
    await expect(dateInput).toBeVisible();
    await dateInput.fill('2026-01-15');
    await dateInput.press('Tab');

    // Set a known datetime value.
    const dateTimeInput = page.locator(
      '[data-testid="canvas-contextual-panel"] .field--name-date-time input[type="date"]',
    );
    const timeInput = page.locator(
      '[data-testid="canvas-contextual-panel"] .field--name-date-time input[type="time"]',
    );
    await expect(dateTimeInput).toBeVisible();
    await expect(timeInput).toBeVisible();
    await dateTimeInput.fill('2026-01-15');
    await timeInput.fill('14:30:00');
    await timeInput.press('Tab');

    // eslint-disable-next-line playwright/no-networkidle
    await page.waitForLoadState('networkidle');

    // Verify raw ISO date value is rendered correctly.
    await canvas.testInPreviewFrame('#date-raw', async (el) => {
      const text = (await el.textContent())?.trim() ?? '';
      expect(text).toBe('2026-01-15');
    });

    // The date filter must produce the en-US short date for Jan 15 2026.
    await canvas.testInPreviewFrame('#date-formatted-date', async (el) => {
      const text = (await el.textContent())?.trim() ?? '';
      expect(text).toBe('1/15/26');
    });

    // Verify raw ISO datetime value is rendered correctly: 14:30 in the user's
    // timezone (the test site's default: Australia/Sydney, UTC+11 in January)
    // is stored and passed to the component in UTC.
    await canvas.testInPreviewFrame('#date-time-raw', async (el) => {
      const text = (await el.textContent())?.trim() ?? '';
      expect(text).toBe('2026-01-15T03:30:00Z');
    });

    // The datetime filter must produce the en-US short datetime for Jan 15
    // 2026 at 03:30 UTC. U+202F before AM/PM is normalized to a regular space.
    // @todo Expect 2:30 PM, as entered, once canvasFormatDateTime formats in the user's timezone.
    await canvas.testInPreviewFrame('#date-formatted-date-time', async (el) => {
      const text =
        (await el.textContent())?.trim().replaceAll('\u202f', ' ') ?? '';
      expect(text).toBe('1/15/26, 3:30 AM');
    });
  });
});
