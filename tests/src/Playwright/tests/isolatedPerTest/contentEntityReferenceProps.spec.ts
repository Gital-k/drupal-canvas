import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

import type { Locator } from '@playwright/test';

test.use({
  modules: ['canvas_test_code_components_content_entity_ref'],
  // The preview panel's "Copy snippet" button and click-to-copy keys write to
  // the clipboard, which the shared steps below read back.
  permissions: ['clipboard-read', 'clipboard-write'],
});

// The payload nests picked fields by their developer-facing keys (uid becomes
// owner), so the referenced author's name lives at author.owner.name.
// @see \Drupal\canvas\Plugin\Canvas\ComponentSource\JsComponent::buildReferencePayload()
const COMPONENT_CODE = [
  'const Component = ({ author }) => (',
  '  <div>Author: {author?.owner?.name}</div>',
  ');',
  'export default Component;',
].join('\n');

async function openDialog(propForm: Locator): Promise<Locator> {
  await propForm
    .getByRole('button', { name: /^(Add type|Add field|Edit)$/ })
    .click();
  const dialog = propForm
    .page()
    .getByRole('dialog', { name: 'Content Relationship' });
  await expect(dialog).toBeVisible();
  return dialog;
}

async function selectFromDropdown(
  dialog: Locator,
  opener: string,
  option: string,
) {
  await dialog.getByText(opener, { exact: true }).click();
  await dialog
    .page()
    .locator('body > div > div.rt-SelectContent')
    .getByRole('option', { name: option, exact: true })
    .click();
}

async function chooseTarget(
  propForm: Locator,
  entityType: string,
  bundle?: string,
) {
  const dialog = await openDialog(propForm);
  const continueButton = dialog.getByRole('button', {
    name: 'Continue',
    exact: true,
  });
  await selectFromDropdown(dialog, 'Select entity type', entityType);
  await expect(
    dialog.getByText('Select entity type', { exact: true }),
  ).toBeHidden();
  if (bundle) {
    // A single-bundle entity type auto-selects its only bundle; a multi-bundle
    // type leaves the "Select bundle" placeholder for the user to choose. Poll
    // until one of those settled states is reached, then only pick when needed.
    const bundlePicker = dialog.getByText('Select bundle', { exact: true });
    await expect
      .poll(
        async () =>
          (await bundlePicker.isVisible()) ||
          (await continueButton.isEnabled()),
      )
      .toBe(true);
    if (await bundlePicker.isVisible()) {
      await selectFromDropdown(dialog, 'Select bundle', bundle);
    }
  }
  await expect(continueButton).toBeEnabled();
  await continueButton.click();
  await expect(
    dialog.getByRole('button', { name: 'Save', exact: true }),
  ).toBeVisible();
  const saveButton = dialog.getByRole('button', { name: 'Save', exact: true });
  await expect(saveButton).toBeDisabled();
  await fieldCheckbox(dialog, 'Title').click();
  await expect(saveButton).toBeEnabled();
  await saveButton.click();
  await expect(dialog).toBeHidden();
}

function fieldCheckbox(dialog: Locator, fieldLabel: string): Locator {
  return dialog.getByRole('checkbox', { name: fieldLabel, exact: true });
}

test.describe('Content entity reference props', () => {
  // The dialog only lists entity types the user can view, so grant the editor
  // the permissions needed to see User and Content, plus node creation so the
  // test can create a News Item to preview against.
  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();
    await drupal.addPermissions({
      role: 'editor',
      permissions: [
        'access content',
        'access user profiles',
        'create news_item content',
      ],
    });
    await drupal.logout();
  });

  test('Adds and edits a content entity reference prop in the code editor', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas();
    await canvas.openCanvas(canvasPage);
    await canvas.createCodeComponent('CerNode', COMPONENT_CODE);

    const propForm = await canvas.addCodeComponentProp(
      'author',
      'Content entity reference',
    );

    await test.step('Cancel discards an unsaved type selection', async () => {
      const dialog = await openDialog(propForm);
      const continueButton = dialog.getByRole('button', {
        name: 'Continue',
        exact: true,
      });
      await expect(continueButton).toBeVisible();
      await expect(continueButton).toBeDisabled();
      await selectFromDropdown(dialog, 'Select entity type', 'User');
      await expect(continueButton).toBeEnabled();
      await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
      await expect(dialog).toBeHidden();
      await expect(
        propForm.getByRole('button', { name: 'Add type', exact: true }),
      ).toBeVisible();
    });

    await test.step('Pick Content / News Item as the target', async () => {
      await chooseTarget(propForm, 'Content', 'News Item');
    });

    await test.step('Reset and cancel discards in-dialog changes', async () => {
      const dialog = await openDialog(propForm);
      await expect(
        dialog.getByRole('button', { name: 'Save', exact: true }),
      ).toBeVisible();

      const title = fieldCheckbox(dialog, 'Title');
      await expect(title).toBeVisible();
      await expect(
        dialog.getByRole('button', { name: 'Expand Title', exact: true }),
      ).toHaveCount(0);
      await title.click();
      await expect(title).toHaveAttribute('data-state', 'unchecked');

      await dialog
        .getByRole('button', { name: 'Reset type and bundle' })
        .click();
      await expect(
        dialog.getByText('Select entity type', { exact: true }),
      ).toBeVisible();
      await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
      await expect(dialog).toBeHidden();
      await expect(propForm).toContainText('News Item');
    });

    await test.step('Select a field property and a nested field property, then save', async () => {
      const dialog = await openDialog(propForm);
      await expect(fieldCheckbox(dialog, 'Title')).toHaveAttribute(
        'data-state',
        'checked',
      );
      await fieldCheckbox(dialog, 'Title').click();
      await expect(fieldCheckbox(dialog, 'Title')).toHaveAttribute(
        'data-state',
        'unchecked',
      );

      const body = fieldCheckbox(dialog, 'Body');
      await expect(body).toBeVisible();
      await dialog
        .getByRole('button', { name: 'Expand Body', exact: true })
        .click();
      const bodyProcessedText = fieldCheckbox(dialog, 'Body → Processed text');
      await expect(bodyProcessedText).toBeVisible();
      await bodyProcessedText.click();
      await expect(bodyProcessedText).toHaveAttribute('data-state', 'checked');
      await expect(
        fieldCheckbox(dialog, 'Body → Processed summary'),
      ).toHaveAttribute('data-state', 'unchecked');
      await expect(body).toHaveAttribute('data-state', 'indeterminate');

      await dialog
        .getByRole('button', { name: 'Expand Authored by', exact: true })
        .click();
      await dialog
        .getByRole('button', { name: 'Expand User', exact: true })
        .click();
      const nestedPicture = fieldCheckbox(dialog, 'Picture');
      await expect(nestedPicture).toBeVisible();
      await dialog
        .getByRole('button', { name: 'Expand Picture', exact: true })
        .click();
      const nestedPictureAlt = fieldCheckbox(
        dialog,
        'Authored by → Picture → Alternative text',
      );
      await expect(nestedPictureAlt).toBeVisible();
      await nestedPictureAlt.click();
      await expect(nestedPictureAlt).toHaveAttribute('data-state', 'checked');
      await expect(
        fieldCheckbox(dialog, 'Authored by → Picture → Width'),
      ).toHaveAttribute('data-state', 'unchecked');
      await expect(nestedPicture).toHaveAttribute(
        'data-state',
        'indeterminate',
      );

      await dialog.getByRole('button', { name: 'Save', exact: true }).click();
      await expect(dialog).toBeHidden();
    });

    await test.step('Reopening restores the saved selections', async () => {
      const reopened = await openDialog(propForm);
      await expect(fieldCheckbox(reopened, 'Body')).toHaveAttribute(
        'data-state',
        'indeterminate',
      );
      await reopened
        .getByRole('button', { name: 'Expand Body', exact: true })
        .click();
      await expect(
        fieldCheckbox(reopened, 'Body → Processed text'),
      ).toHaveAttribute('data-state', 'checked');
      await expect(
        fieldCheckbox(reopened, 'Body → Processed summary'),
      ).toHaveAttribute('data-state', 'unchecked');

      await reopened
        .getByRole('button', { name: 'Expand Authored by', exact: true })
        .click();
      await reopened
        .getByRole('button', { name: 'Expand User', exact: true })
        .click();
      await expect(fieldCheckbox(reopened, 'Picture')).toHaveAttribute(
        'data-state',
        'indeterminate',
      );
      await reopened
        .getByRole('button', { name: 'Expand Picture', exact: true })
        .click();
      await expect(
        fieldCheckbox(reopened, 'Authored by → Picture → Alternative text'),
      ).toHaveAttribute('data-state', 'checked');
      await expect(
        fieldCheckbox(reopened, 'Authored by → Picture → Width'),
      ).toHaveAttribute('data-state', 'unchecked');
    });

    await test.step('Also pick Authored by → Name, then save', async () => {
      // The previous step left the dialog open with Authored by → User
      // expanded, so Name is already visible. Picking it lets the component
      // code render author?.owner?.name from the resolved preview payload.
      const dialog = propForm
        .page()
        .getByRole('dialog', { name: 'Content Relationship' });
      const name = fieldCheckbox(dialog, 'Name');
      await expect(name).toBeVisible();
      await name.click();
      await expect(name).toHaveAttribute('data-state', 'checked');
      await dialog.getByRole('button', { name: 'Save', exact: true }).click();
      await expect(dialog).toBeHidden();
    });

    const shapePreview = propForm.getByTestId('cer-shape-preview');

    await test.step('Shape skeleton reflects the payload keys', async () => {
      await expect(
        propForm.getByRole('combobox', { name: 'Preview entity' }),
      ).toBeVisible();
      await expect(shapePreview).toBeVisible();
      // The Type row names the target bundle.
      await expect(
        shapePreview.getByText('Type', { exact: true }),
      ).toBeVisible();
      await expect(
        shapePreview.getByText('news_item', { exact: true }),
      ).toBeVisible();
      // Keys are the developer-facing payload keys, not raw field names: uid
      // renders as owner, whose picked leaf fields nest below its __type row.
      await expect(shapePreview.locator('code')).toHaveText([
        'body',
        'owner',
        '__type',
        'user_picture',
        'name',
      ]);
      // Each row carries a type badge (the bundle badge comes first).
      await expect(shapePreview.locator('.rt-Badge')).toHaveText([
        'news_item',
        'string',
        'object',
        'string',
        'string',
        'string',
      ]);
      // Rows nested under owner are indented one level.
      await expect(
        shapePreview
          .getByText('__type', { exact: true })
          .first()
          .locator('xpath=ancestor::div[contains(@class, "rt-Flex")][1]'),
      ).toHaveCSS('padding-left', '16px');
      await expect(
        propForm.getByText(
          /^Approximate: select an entity above for real values/,
        ),
      ).toBeVisible();
    });

    await test.step('Copy snippet copies a destructuring snippet', async () => {
      await propForm
        .getByRole('button', { name: 'Copy snippet', exact: true })
        .click();
      // The button label flips to Copied briefly as feedback.
      await expect(
        propForm.getByRole('button', { name: 'Copied', exact: true }),
      ).toBeVisible();
      const clipboard = await page.evaluate(() =>
        navigator.clipboard.readText(),
      );
      expect(clipboard).toBe(
        'const { body, owner: { user_picture, name } } = author;',
      );
    });

    await test.step('Selecting a preview entity shows real values', async () => {
      // Create a News Item in a second tab so the code editor keeps its state.
      const nodePage = await page.context().newPage();
      await nodePage.goto('/node/add/news_item');
      // The fixture module ships no form display for news_item, so Body is
      // not on the node form: create with Title only. The resolved values
      // asserted below come from the owner (the logged-in creator) instead.
      await nodePage.getByLabel('Title').fill('CER Preview News');
      await nodePage.getByRole('button', { name: 'Save' }).click();
      await expect(
        nodePage.getByRole('contentinfo', { name: 'Status message' }),
      ).toContainText('News Item CER Preview News has been created.');
      await nodePage.close();

      const picker = propForm.getByRole('combobox', {
        name: 'Preview entity',
      });
      await picker.click();
      await picker.fill('CER Preview');
      await propForm
        .getByRole('option', { name: 'CER Preview News', exact: true })
        .click();

      // The skeleton is replaced by the entity's real resolved payload. The
      // tree expands fully, so both entity levels render their __type key:
      // the news item itself and the nested owner object.
      await expect(shapePreview.locator('span[title="__type"]')).toHaveCount(
        2,
      );
      await expect(shapePreview).toContainText('news_item');
      await expect(shapePreview.locator('span[title="owner"]')).toBeVisible();
      // The nested owner object resolves to the creating user's real values.
      await expect(shapePreview).toContainText('editor');

      // Keys are click-to-copy: the hover title flips to Copied briefly.
      const bodyKey = shapePreview.locator('span[title]', {
        hasText: 'body:',
      });
      await expect(bodyKey).toHaveAttribute('title', 'body');
      await bodyKey.click();
      await expect(bodyKey).toHaveAttribute('title', 'Copied');

      // The resolved payload also feeds the code editor preview iframe as the
      // prop value: the component renders the author's name from it.
      await expect(
        canvas
          .getCodePreviewFrame()
          .getByText('Author: editor', { exact: true }),
      ).toBeVisible();
    });

    await test.step('Clearing the preview entity returns to the skeleton', async () => {
      // react-select's clear (x) control is its first indicator container.
      await propForm.locator('[class$="-indicatorContainer"]').first().click();
      await expect(
        shapePreview.getByText('Type', { exact: true }),
      ).toBeVisible();
      await expect(shapePreview.locator('code')).toHaveText([
        'body',
        'owner',
        '__type',
        'user_picture',
        'name',
      ]);
      await expect(
        propForm.getByText(
          /^Approximate: select an entity above for real values/,
        ),
      ).toBeVisible();
      // Without a referenced entity the prop resolves to null in the iframe.
      await expect(
        canvas.getCodePreviewFrame().getByText('Author:', { exact: true }),
      ).toBeVisible();
    });
  });
});
