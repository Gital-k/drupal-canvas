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
});
