import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

// cspell:ignore cset

test.describe('Block form', () => {
  test('Block settings form with details element', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();
    await canvas.openLibraryPanel();
    // Don't wait for the preview as this user doesn't have permissions to see anything
    // in that menu.
    await canvas.addComponent(
      { id: 'block.system_menu_block.footer' },
      { waitForVisible: false },
    );

    const inputsForm = page.locator(
      '[data-testid="canvas-contextual-panel"] [data-drupal-selector="component-instance-form"]',
    );
    await expect(inputsForm).toContainText('Menu levels');
    await expect(inputsForm.locator('select')).toHaveCount(2);
    await expect(inputsForm.locator('input[type="checkbox"]')).toBeVisible();
  });

  test('Block settings form values are stored and the preview is updated', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    await canvas.createCanvas();
    await canvas.openLibraryPanel();
    // Don't wait for the preview as there won't be anything to see initially.
    await canvas.addComponent(
      { name: 'Site branding' },
      { waitForVisible: false },
    );

    await canvas.openLayersPanel();
    await canvas.openComponent('Site branding');

    // Add and remove the site logo.
    const imgLocator =
      'xpath=//img[ancestor::*[starts-with(@id, "block-") and string-length(@id) = 42]]';
    const siteLogoCheckbox = page.getByRole('checkbox', { name: 'Site logo' });
    await expect(siteLogoCheckbox).not.toBeChecked();
    await expect(
      (await canvas.getActivePreviewFrame()).locator(imgLocator),
    ).toBeHidden();
    await siteLogoCheckbox.click();
    await expect(siteLogoCheckbox).toBeChecked();
    await expect(
      (await canvas.getActivePreviewFrame()).locator(imgLocator),
    ).toBeVisible();
    await siteLogoCheckbox.click();
    await expect(siteLogoCheckbox).not.toBeChecked();
    await expect(
      (await canvas.getActivePreviewFrame()).locator(imgLocator),
    ).toBeHidden();

    // Add the site name.
    const siteNameLocator =
      'xpath=//a[ancestor::*[starts-with(@id, "block-") and string-length(@id) = 42]]';
    const siteNameCheckbox = page.getByRole('checkbox', { name: 'Site name' });
    await expect(siteNameCheckbox).not.toBeChecked();
    await expect(
      (await canvas.getActivePreviewFrame()).locator(siteNameLocator),
    ).toBeHidden();
    await siteNameCheckbox.click();
    await expect(siteNameCheckbox).toBeChecked();
    await expect(
      (await canvas.getActivePreviewFrame()).locator(siteNameLocator),
    ).toHaveText('Drupal');

    // Verify the component is saved and renders with the new options.
    await canvas.publishAllChanges();
    await page.reload();
    await expect(
      (await canvas.getActivePreviewFrame()).locator(imgLocator),
    ).toBeHidden();
    await expect(
      (await canvas.getActivePreviewFrame()).locator(siteNameLocator),
    ).toHaveText('Drupal');
  });

  // The context-aware test blocks live in the `canvas_test_block` module under
  // `tests/modules`, so they require `extension_discovery_scan_tests` (set by
  // `enableTestExtensions`) to be discoverable before they can be installed.
  test.describe('Context-aware blocks', () => {
    test.use({
      modules: ['canvas_test_block'],
      enableTestExtensions: true,
    });

    test('Context-aware block: required context is displayed, an optional context mapping persists, and the preview reflects it', async ({
      page,
      drupal,
      canvas,
    }) => {
      await drupal.login({ username: 'editor', password: 'editor' });
      // A distinctive host title so we can prove the applied host-entity context
      // is what the placed block renders.
      const hostTitle = 'Context host page';
      await canvas.createCanvas({ title: hostTitle });
      await canvas.openLibraryPanel();

      // Host-entity library filtering: the optional-context block is offered for
      // any host, while the block requiring an `entity:node` context is filtered
      // out because a Canvas Page host is not a node. (API-level filtering is
      // covered by kernel tests; this is a cheap end-to-end sanity check.)
      const library = page.getByTestId('canvas-primary-panel');
      await expect(
        library.locator(
          '[data-canvas-component-id="block.canvas_test_block_optional_contexts"]',
        ),
      ).toHaveCount(1);
      await expect(
        library.locator(
          '[data-canvas-component-id="block.canvas_test_block_requires_node_context"]',
        ),
      ).toHaveCount(0);

      const inputsForm = page.locator(
        '[data-testid="canvas-contextual-panel"] [data-drupal-selector="component-instance-form"]',
      );

      // 1. Place the block whose required "name" context is auto-assigned to the
      // current user. Its instance form shows the read-only required-context
      // display resolving to the "Current user" context.
      await canvas.addComponent({
        id: 'block.canvas_test_block_requires_contexts',
      });
      await expect(inputsForm).toContainText('Current user');
      // The applied required context is reflected in the preview: the current
      // user (the logged-in "editor") is rendered by the block.
      await expect(
        (await canvas.getActivePreviewFrame()).getByText(
          'Test Block with required context value: editor',
        ),
      ).toBeVisible();

      // 2. Place the block with an optional "name" context. With no mapping yet,
      // it renders its no-value path.
      await canvas.openLibraryPanel();
      await canvas.addComponent({
        id: 'block.canvas_test_block_optional_contexts',
      });
      await expect(
        (await canvas.getActivePreviewFrame()).getByText(
          'Test Block with no context value.',
        ),
      ).toBeVisible();

      // The optional context is exposed as a `context_mapping[name]` select.
      // Target it by its form element name, not its DOM position.
      const contextSelect = inputsForm.locator(
        'select[name$="[context_mapping][name]"]',
      );
      await expect(contextSelect).toBeVisible();
      await expect(contextSelect).toHaveValue('');

      // 3. Map the optional context to the host entity being edited. The host
      // entity context id is stable (\Drupal\canvas\ComponentSource\ContextGatherer::HOST_ENTITY_CONTEXT_ID).
      await contextSelect.selectOption({ value: 'canvas.host_entity' });
      await expect(contextSelect).toHaveValue('canvas.host_entity');

      // 4. The preview reflects the applied context: the host entity's label.
      await expect(
        (await canvas.getActivePreviewFrame()).getByText(
          `Test Block with optional context value: ${hostTitle}`,
        ),
      ).toBeVisible();

      // The mapping persists: publish, reload, and the applied context still
      // renders in the preview. This mirrors the persistence check in the
      // sibling "settings ... are stored" test above — asserting the saved
      // mapping through the rendered output, without a fragile form reopen.
      await canvas.publishAllChanges();
      await page.reload();
      await expect(
        (await canvas.getActivePreviewFrame()).getByText(
          `Test Block with optional context value: ${hostTitle}`,
        ),
      ).toBeVisible();
    });
  });
});
