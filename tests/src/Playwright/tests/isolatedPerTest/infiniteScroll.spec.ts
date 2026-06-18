import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

/**
 * Creates a fake page stub for mocking the content list API.
 */
function fakePageStub(id: number) {
  return {
    title: `Page ${id}`,
    path: `/page-${id}`,
    internalPath: `/canvas_page/${id}`,
    id,
    status: true,
    autoSaveLabel: null,
    autoSavePath: `/page/${id}`,
    links: {},
  };
}

test.describe('Infinite scroll', () => {
  test('Pages panel loads more items when scrolling to the bottom', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    // Create a single real page so the editor UI can load.
    await canvas.createCanvas({ title: 'Real Page' });

    const firstBatch = Array.from({ length: 50 }, (_, i) =>
      fakePageStub(i + 1),
    );
    const secondBatch = Array.from({ length: 10 }, (_, i) =>
      fakePageStub(i + 51),
    );
    const totalCount = firstBatch.length + secondBatch.length;

    // Intercept the content list API to simulate paginated responses.
    await page.route('**/canvas/api/v0/content/canvas_page*', (route) => {
      const url = new URL(route.request().url());
      const offset = parseInt(url.searchParams.get('page[offset]') ?? '0', 10);

      const items = offset > 0 ? secondBatch : firstBatch;
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: items,
          meta: { count: totalCount },
          links: {},
        }),
      });
    });

    // Open the pages panel — this triggers the first API call.
    await canvas.openPagesPanel();

    // Verify first batch is rendered.
    const pageList = page.locator('[data-testid="canvas-page-list"]');
    await expect(pageList.locator('[data-canvas-page-id="1"]')).toBeAttached();
    await expect(pageList.locator('[data-canvas-page-id="50"]')).toBeAttached();

    // Second batch should NOT be loaded yet.
    await expect(
      pageList.locator('[data-canvas-page-id="51"]'),
    ).not.toBeAttached();

    // Scroll the page list to the bottom to trigger the InfiniteScrollObserver.
    await pageList
      .locator('[data-canvas-page-id="50"]')
      .scrollIntoViewIfNeeded();

    // Verify second batch appears after scrolling.
    await expect(pageList.locator('[data-canvas-page-id="51"]')).toBeAttached({
      timeout: 5000,
    });
    await expect(pageList.locator('[data-canvas-page-id="60"]')).toBeAttached();
  });

  test('Pages panel handles network failure gracefully during infinite scroll', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas({ title: 'Real Page' });

    const firstBatch = Array.from({ length: 50 }, (_, i) =>
      fakePageStub(i + 1),
    );
    const secondBatch = Array.from({ length: 10 }, (_, i) =>
      fakePageStub(i + 51),
    );
    const totalCount = firstBatch.length + secondBatch.length;

    let requestCount = 0;
    await page.route('**/canvas/api/v0/content/canvas_page*', (route) => {
      const url = new URL(route.request().url());
      const offset = parseInt(url.searchParams.get('page[offset]') ?? '0', 10);

      requestCount++;

      // First request (offset=0) succeeds
      if (offset === 0) {
        route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: firstBatch,
            meta: { count: totalCount },
            links: {},
          }),
        });
      }
      // Second request (offset=50) fails with network error
      else if (requestCount === 2) {
        route.abort('failed');
      }
      // Third request (retry) succeeds
      else {
        route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: secondBatch,
            meta: { count: totalCount },
            links: {},
          }),
        });
      }
    });

    await canvas.openPagesPanel();

    const pageList = page.locator('[data-testid="canvas-page-list"]');
    await expect(pageList.locator('[data-canvas-page-id="1"]')).toBeAttached();
    await expect(pageList.locator('[data-canvas-page-id="50"]')).toBeAttached();

    // Scroll to trigger loading more
    await pageList
      .locator('[data-canvas-page-id="50"]')
      .scrollIntoViewIfNeeded();

    // Verify error card appears instead of crashing the UI
    const errorCard = page.locator('[data-testid="canvas-error-card"]');
    await expect(errorCard).toBeAttached({ timeout: 5000 });
    await expect(errorCard).toContainText('Failed to load more pages');
    await expect(errorCard).toContainText('Network error');

    // Verify the first batch is still visible (UI didn't crash)
    await expect(pageList.locator('[data-canvas-page-id="1"]')).toBeAttached();
    await expect(pageList.locator('[data-canvas-page-id="50"]')).toBeAttached();

    // Click retry button
    const retryButton = errorCard.locator('[data-testid="canvas-error-reset"]');
    await expect(retryButton).toBeAttached();
    await retryButton.click();

    // Verify second batch loads successfully after retry
    await expect(pageList.locator('[data-canvas-page-id="51"]')).toBeAttached({
      timeout: 5000,
    });
    await expect(pageList.locator('[data-canvas-page-id="60"]')).toBeAttached();

    // Verify error card is gone
    await expect(errorCard).not.toBeAttached();
  });

  test('Top navigation loads more items when scrolling to the bottom', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    // Create a single real page so the editor UI can load.
    await canvas.createCanvas({ title: 'Real Page' });

    const firstBatch = Array.from({ length: 50 }, (_, i) =>
      fakePageStub(i + 1),
    );
    const secondBatch = Array.from({ length: 10 }, (_, i) =>
      fakePageStub(i + 51),
    );
    const totalCount = firstBatch.length + secondBatch.length;

    // Intercept the content list API to simulate paginated responses.
    await page.route('**/canvas/api/v0/content/canvas_page*', (route) => {
      const url = new URL(route.request().url());
      const offset = parseInt(url.searchParams.get('page[offset]') ?? '0', 10);

      const items = offset > 0 ? secondBatch : firstBatch;
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: items,
          meta: { count: totalCount },
          links: {},
        }),
      });
    });

    // Open the top navigation popover.
    await canvas.openContentNavigation();

    // Verify first batch is rendered.
    const navigation = page.locator(
      '[data-testid="canvas-navigation-content"]',
    );
    await expect(
      navigation.locator('[data-canvas-page-id="1"]'),
    ).toBeAttached();
    await expect(
      navigation.locator('[data-canvas-page-id="50"]'),
    ).toBeAttached();

    // Second batch should NOT be loaded yet.
    await expect(
      navigation.locator('[data-canvas-page-id="51"]'),
    ).not.toBeAttached();

    // Scroll the navigation to the bottom to trigger the InfiniteScrollObserver.
    await navigation
      .locator('[data-canvas-page-id="50"]')
      .scrollIntoViewIfNeeded();

    // Verify second batch appears after scrolling.
    await expect(navigation.locator('[data-canvas-page-id="51"]')).toBeAttached(
      { timeout: 5000 },
    );
    await expect(
      navigation.locator('[data-canvas-page-id="60"]'),
    ).toBeAttached();
  });

  test('Top navigation handles network failure gracefully during infinite scroll', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas({ title: 'Real Page' });

    const firstBatch = Array.from({ length: 50 }, (_, i) =>
      fakePageStub(i + 1),
    );
    const secondBatch = Array.from({ length: 10 }, (_, i) =>
      fakePageStub(i + 51),
    );
    const totalCount = firstBatch.length + secondBatch.length;

    let requestCount = 0;
    await page.route('**/canvas/api/v0/content/canvas_page*', (route) => {
      const url = new URL(route.request().url());
      const offset = parseInt(url.searchParams.get('page[offset]') ?? '0', 10);

      requestCount++;

      if (offset === 0) {
        route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: firstBatch,
            meta: { count: totalCount },
            links: {},
          }),
        });
      } else if (requestCount === 2) {
        route.abort('failed');
      } else {
        route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: secondBatch,
            meta: { count: totalCount },
            links: {},
          }),
        });
      }
    });

    await canvas.openContentNavigation();

    const navigation = page.locator(
      '[data-testid="canvas-navigation-content"]',
    );
    await expect(
      navigation.locator('[data-canvas-page-id="1"]'),
    ).toBeAttached();
    await expect(
      navigation.locator('[data-canvas-page-id="50"]'),
    ).toBeAttached();

    // Scroll to trigger loading more
    await navigation
      .locator('[data-canvas-page-id="50"]')
      .scrollIntoViewIfNeeded();

    // Verify error card appears
    const errorCard = page.locator('[data-testid="canvas-error-card"]');
    await expect(errorCard).toBeAttached({ timeout: 5000 });
    await expect(errorCard).toContainText('Failed to load more pages');

    // Verify first batch is still visible
    await expect(
      navigation.locator('[data-canvas-page-id="1"]'),
    ).toBeAttached();
    await expect(
      navigation.locator('[data-canvas-page-id="50"]'),
    ).toBeAttached();

    // Click retry
    const retryButton = errorCard.locator('[data-testid="canvas-error-reset"]');
    await retryButton.click();

    // Verify second batch loads after retry
    await expect(navigation.locator('[data-canvas-page-id="51"]')).toBeAttached(
      { timeout: 5000 },
    );
    await expect(
      navigation.locator('[data-canvas-page-id="60"]'),
    ).toBeAttached();
    await expect(errorCard).not.toBeAttached();
  });
});
