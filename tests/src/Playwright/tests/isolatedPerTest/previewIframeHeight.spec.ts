import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { Page } from '@playwright/test';

const scalingIframe =
  '[data-testid="canvas-editor-frame-scaling"] iframe[data-canvas-swap-active="true"]';

/**
 * Layout height (unscaled) of the active preview iframe in the editor. The
 * iframe sits inside a CSS-transform-scaled container, so we read offsetHeight
 * rather than getBoundingClientRect() to ignore the zoom transform.
 */
async function getEditorPreviewIframeHeight(page: Page) {
  return page.locator(scalingIframe).evaluate((el) => {
    return (el as HTMLIFrameElement).offsetHeight;
  });
}

test.describe('Preview iframe height tracks content', () => {
  test.beforeEach(async ({ drupal, page: _p }) => {
    await drupal.enableTestExtensions();
    await drupal.loginAsAdmin();
    await drupal.installModules(['canvas_test_sdc', 'canvas_test_vh_preview']);
    await drupal.logout();
  });

  test('grows when content height increases after load (CSS animation)', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());
    await canvas.openLibraryPanel();
    await canvas.addComponent(
      { id: 'sdc.canvas_test_vh_preview.grow_over_time' },
      { hasInputs: false },
    );
    const previewFrame = await canvas.getActivePreviewFrame();
    await expect(
      previewFrame.locator('[data-canvas-test-grow-over-time]'),
    ).toBeAttached();

    const before = await getEditorPreviewIframeHeight(page);
    expect(before).toBeGreaterThan(0);

    await expect
      .poll(() => getEditorPreviewIframeHeight(page), {
        timeout: 10_000,
      })
      .toBeGreaterThan(before + 300);
  });

  test('VH full component inserted after other content is clamped and stable', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());
    await canvas.openLibraryPanel();
    await canvas.addComponent({ name: 'Hero' });
    await page.keyboard.press('Escape');
    await canvas.addComponent(
      { id: 'sdc.canvas_test_vh_preview.vh_full' },
      { hasInputs: false },
    );
    const previewFrame = await canvas.getActivePreviewFrame();
    const vhFull = previewFrame.locator('#vh-full');
    const measureVhFull = () =>
      vhFull.evaluate((el) => el.getBoundingClientRect().height);

    // Wait for the vh_full element to reach a clamped, non-zero height.
    await expect.poll(measureVhFull, { timeout: 10_000 }).toBeGreaterThan(100);
    const initial = await measureVhFull();

    // Ensure the element does not grow on its own over time (no feedback loop).
    await new Promise((resolve) => setTimeout(resolve, 2000));
    const mid = await measureVhFull();
    expect(Math.abs(mid - initial)).toBeLessThan(30);

    // Edit a sibling component and verify the VH element remains stable.
    await canvas.openLayersPanel();
    await canvas.openComponent('Hero');
    await page.getByLabel('Heading', { exact: true }).click();
    await page.getByLabel('Heading', { exact: true }).fill('NO GROW');
    await page.keyboard.press('Escape');
    await expect
      .poll(
        () =>
          previewFrame
            .locator('.my-hero__heading')
            .getByText('NO GROW', { exact: true })
            .count(),
        { timeout: 10_000 },
      )
      .toBe(1);

    const after = await measureVhFull();
    expect(Math.abs(after - initial)).toBeLessThan(30);
  });

  test('grows after load when Grows over time is inserted on top of existing content', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());
    await canvas.openLibraryPanel();
    await canvas.addComponent({ name: 'Heading' }, { hasInputs: false });
    const before = await getEditorPreviewIframeHeight(page);

    await canvas.addComponent(
      { id: 'sdc.canvas_test_vh_preview.grow_over_time' },
      { hasInputs: false },
    );
    const previewFrame = await canvas.getActivePreviewFrame();
    await expect(
      previewFrame.locator('[data-canvas-test-grow-over-time]'),
    ).toHaveCount(1);

    await expect
      .poll(() => getEditorPreviewIframeHeight(page), { timeout: 10_000 })
      .toBeGreaterThan(before + 300);
  });
});
