import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

test.describe('Code editor', () => {
  test('persists prop machine name after reload', async ({
    page,
    canvas,
    drupal,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());

    const machineName = 'e2e_custom_prop_key';
    const code = `const C = ({ body = 'ok' }) => <p>{body}</p>;
export default C;
`;

    await canvas.createCodeComponent('E2eMachineName', code);
    await canvas.addCodeComponentProp(
      'Body text',
      'Text',
      [],
      false,
      machineName,
    );

    await page.reload();
    await expect(
      page.locator('[data-testid="canvas-code-editor-main-panel"]'),
    ).toBeVisible();

    await page
      .locator(
        '[data-testid="canvas-code-editor-component-data-panel"] button:has-text("Props")',
      )
      .click();
    const propForm = page
      .locator(
        '[data-testid="canvas-code-editor-component-data-panel"] [data-testid^="prop-"]',
      )
      .last();

    await expect(propForm.locator('[id^="prop-machine-name-"]')).toHaveValue(
      machineName,
    );
  });

  test('shows an inline error and blocks save for an invalid prop machine name', async ({
    page,
    canvas,
    drupal,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.openCanvas(await canvas.createCanvas());

    const code = `const C = ({ body = 'ok' }) => <p>{body}</p>;
export default C;
`;

    await canvas.createCodeComponent('E2eInvalidMachineName', code);
    // Add a prop without a custom machine name first, so the page is in a
    // clean, saveable state.
    await canvas.addCodeComponentProp('Body text', 'Text', [], false);

    const propForm = page
      .locator(
        '[data-testid="canvas-code-editor-component-data-panel"] [data-testid^="prop-"]',
      )
      .last();
    const machineNameInput = propForm.locator('[id^="prop-machine-name-"]');

    // Fill an invalid machine name (starts with a digit) and blur the field.
    await machineNameInput.fill('1invalid');
    await machineNameInput.blur();

    // Inline error appears.
    await expect(
      propForm.getByText(
        /Use letters, numbers and underscores only\. Must start with a letter or underscore\./,
      ),
    ).toBeVisible();

    // Top-level callout appears.
    await expect(
      page.getByText(/one or more props have an invalid machine name/i),
    ).toBeVisible();

    // No save request fires while the value is invalid. Wait long enough to
    // cover the 1s auto-save debounce.
    const saveRequests: string[] = [];
    page.on('request', (req) => {
      const url = req.url();
      if (
        ['PATCH', 'POST', 'PUT'].includes(req.method()) &&
        (url.includes('/canvas/api/v0/config/js_component') ||
          url.includes('/canvas/api/v0/config/auto-save/js_component'))
      ) {
        saveRequests.push(`${req.method()} ${url}`);
      }
    });
    await page.waitForTimeout(1500);
    expect(saveRequests).toEqual([]);
  });
});
