import { expect } from '@playwright/test';

import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';
import { getModuleDir } from './utilities/DrupalFilesystem';

// cspell:ignore Bwidth Fitok treehouse
test.describe('Templates - General', () => {
  test.beforeAll(
    'Setup test site with Drupal Canvas',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.setupCanvasTestSite();
      await page.close();
    },
  );
  test('Template - add templates to page', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    await drupal.loginAsAdmin();
    await canvasEditor.goToCanvasRoot();
    await page.click('[aria-label="Templates"]');
    await expect(page.getByTestId('big-add-template-button')).toBeVisible();
    await expect(
      page.locator('[data-canvas-folder-name="Article"]'),
    ).toBeVisible();

    await expect(page.locator('.primaryPanelContent')).toMatchAriaSnapshot(`
      - button "Add new template":
        - img
      - button "Content types" [expanded]
      - region "Content types"
    `);

    const addTemplate = async (bundle) => {
      await page.getByTestId('big-add-template-button').click();
      await expect(
        page.getByTestId('canvas-manage-library-add-template-content'),
      ).toBeVisible();
      await page.locator('#content-type').click();
      await expect(
        page.locator(`[role="option"]:has-text("${bundle}")`),
      ).toBeVisible();
      await page.locator(`[role="option"]:has-text("${bundle}")`).click();
      await page.locator('#template-name').click();
      await expect(
        page.locator('[role="option"]:has-text("Full content")'),
      ).toBeVisible();
      await page.locator('[role="option"]:has-text("Full content")').click();
      await page
        .locator('[role="dialog"] button:has-text("Add new template")')
        .click();
    };

    await addTemplate('Basic page');
    await page.locator('[data-template-list-item="page Full content"]').click();
    expect(page.url()).toContain('canvas/template/node/page/full');
    await expect(
      page.locator('span:has-text("No preview content is available")'),
    ).toBeVisible();
    await expect(
      page.locator(
        'span:has-text("To build a template, you must have at least one Basic page")',
      ),
    ).toBeVisible();

    await addTemplate('Article');
    await page
      .locator('[data-template-list-item="article Full content"]')
      .click();
    expect(page.url()).toContain('canvas/template/node/article/full/1');
    await canvasEditor.openLibraryPanel();
    await canvasEditor.addComponent({ id: 'sdc.canvas_test_sdc.my-hero' });

    const nodeTitle = 'Canvas With a block in the layout';
    const defaultHeading = 'There goes my hero';
    const inputLocator = `[data-testid="canvas-contextual-panel"] [data-drupal-selector="component-instance-form"] .field--name-heading input`;
    const linkedBoxLocator = '[data-linked-field-box="heading"]';
    await expect(page.locator(inputLocator)).toBeVisible();
    await expect(page.locator(inputLocator)).toHaveValue(defaultHeading);
    await expect(page.locator(linkedBoxLocator)).not.toBeAttached();

    await expect(
      (await canvasEditor.getActivePreviewFrame()).locator(
        '[data-component-id="canvas_test_sdc:my-hero"] h1',
      ),
    ).toContainText(defaultHeading);

    await expect(
      (await canvasEditor.getActivePreviewFrame()).locator(
        '[data-component-id="canvas_test_sdc:my-hero"] h1',
      ),
    ).not.toContainText(nodeTitle);

    await expect(page.getByTestId('select-content-preview-item')).toContainText(
      nodeTitle,
    );
    await page.locator('[data-prop-linker-for="heading"]').click();
    await page.locator('[data-link-suggestion-option="Title"]').click();

    await expect(page.locator(inputLocator)).not.toBeAttached();
    await expect(page.locator(linkedBoxLocator)).toBeVisible();

    await expect(
      (await canvasEditor.getActivePreviewFrame()).locator(
        '[data-component-id="canvas_test_sdc:my-hero"] h1',
      ),
    ).toContainText(nodeTitle);

    await expect(
      (await canvasEditor.getActivePreviewFrame()).locator(
        '[data-component-id="canvas_test_sdc:my-hero"] h1',
      ),
    ).not.toContainText(defaultHeading);

    await canvasEditor.editComponentProp('subheading', 'submarine');

    await expect(
      (await canvasEditor.getActivePreviewFrame()).locator(
        '.my-hero__subheading',
      ),
    ).toContainText('submarine');

    // Add a fixed timeout to allow for any problems with the prop linker to
    // occur - they don't necessarily happen immediately.
    await new Promise((resolve) => setTimeout(resolve, 1000));
    await expect(page.locator(inputLocator)).not.toBeAttached();
    await expect(page.locator(linkedBoxLocator)).toBeVisible();
    // Confirm that the heading is still linked after making a change to an
    // unlinked field
    await expect(
      (await canvasEditor.getActivePreviewFrame()).locator(
        '[data-component-id="canvas_test_sdc:my-hero"] h1',
      ),
    ).toContainText(nodeTitle);

    await expect(
      (await canvasEditor.getActivePreviewFrame()).locator(
        '[data-component-id="canvas_test_sdc:my-hero"] h1',
      ),
    ).not.toContainText(defaultHeading);

    const newArticleTitle = 'Canvas Needs This For The Time Being';
    await page.getByTestId('select-content-preview-item').click();
    await page
      .locator(`[data-preview-option-item="${newArticleTitle}"]`)
      .click();
    await expect(
      (await canvasEditor.getActivePreviewFrame()).locator(
        '[data-component-id="canvas_test_sdc:my-hero"] h1',
      ),
    ).toContainText(newArticleTitle);
    expect(page.url()).toContain('canvas/template/node/article/full/2');
    await page.locator('[aria-label="Hero"]').click();
    await expect(page.locator(linkedBoxLocator)).toBeVisible();
    await expect(page.locator(inputLocator)).not.toBeAttached();
    await canvasEditor.publishAllChanges();
    await page.goto('/node/1');
    await expect(
      page.locator(`.my-hero__heading:has-text("${nodeTitle}")`),
    ).toBeVisible();
    await expect(
      page.locator(`.my-hero__subheading:has-text("submarine")`),
    ).toBeVisible();
    await page.goto('/node/2');
    await expect(
      page.locator(`.my-hero__heading:has-text("${newArticleTitle}")`),
    ).toBeVisible();
    await expect(
      page.locator(`.my-hero__subheading:has-text("submarine")`),
    ).toBeVisible();
  });
});

test.describe('Linking Basic Page fields ', () => {
  test.describe.configure({ projects: ['chromium'] });
  test.beforeAll(
    'Setup test site with Drupal Canvas, templates enabled, and page with many field types',
    async ({ browser, drupalSite }) => {
      const page = await browser.newPage();
      const drupal: Drupal = new Drupal({ page, drupalSite });
      await drupal.setupCanvasTestSite();
      const moduleDir = await getModuleDir();
      await drupal.applyRecipe(
        `${moduleDir}/canvas/tests/fixtures/recipes/template_basic_setup`,
      );
      await page.close();
      await drupal.drush('cr');
    },
  );

  const addLinkablePropsComponent = async ({ page, drupal, canvasEditor }) => {
    await drupal.loginAsAdmin();
    await page.goto('/canvas/template/node/page/full/4');
    await expect(
      page.getByTestId('canvas-empty-region-drop-zone-content'),
    ).toBeVisible();
    await canvasEditor.openLibraryPanel();
    await canvasEditor.addComponent({
      id: 'sdc.canvas_test_linkable_props.linkable-props',
    });
  };

  const testPropLinking = async ({ page, drupal, canvasEditor, inputName }) => {
    await addLinkablePropsComponent({
      page,
      drupal,
      canvasEditor,
    });
    await page.addStyleTag({
      content: `
      .canvas-linked-prop-label-wrapper {
        flex-wrap: wrap!important;
      }
      .canvas-linked-prop-label-wrapper label {
        width: 100%;
      }
      [role="menuitem"] {
        font-size: 12px;
        padding: 0;
      }
      [data-testid="canvas-contextual-panel"] {
      width: 600px;}
    `,
    });
    const linker = await page.locator(`[data-prop-linker-for="${inputName}"]`);
    await expect(linker).toBeAttached();
    const suggestionsTree = suggestionsByProp[inputName];
    for (const suggestionOption of suggestionsTree) {
      await linker.click();
      const suggestionPath = suggestionOption.split(' > ');
      let itemSelected = false;
      let parent = 'root';
      let stepCount = 1;
      await test.step(`Choose ${suggestionOption} for ${inputName}`, async () => {
        for (const pathItem of suggestionPath) {
          const parentName = pathItem === parent ? 'myself' : parent;
          const step = await page.locator(
            `[data-link-suggestion-option="${pathItem}"][data-child-of="${parentName}"]`,
          );
          await step.waitFor();
          if (stepCount === suggestionPath.length) {
            await step.click({ force: true });
            await page.waitForResponse(
              (response) =>
                response
                  .url()
                  .includes(
                    '/api/v0/form/component-instance/content_template',
                  ) && response.status() === 200,
            );
            await expect(
              page.locator(`[data-linked-field-box="${inputName}"]`),
            ).toBeVisible();
            await expect(
              page.locator(`[data-linked-field-box="${inputName}"]`),
            ).toHaveText(suggestionPath[0]);
            itemSelected = true;
          } else {
            parent = pathItem;
            await step.hover({ force: true });
            // Radix responds to hover via a pointermove event.
            await step.dispatchEvent('pointermove');
            await page.keyboard.press('ArrowRight');
          }
          stepCount += 1;
        }
      });
      await expect(itemSelected).toBe(true);
      await new Promise((resolve) => setTimeout(resolve, 500));
      await test.step(`Verify preview for ${suggestionOption} for ${inputName}`, async () => {
        const previewSelector = propNameToSelector[inputName];
        const expectedValue = entityValuesNode4[suggestionPath.join(' > ')];
        if (expectedValue !== '*SKIP* (this crashes)') {
          await expect(
            (await canvasEditor.getActivePreviewFrame()).locator(
              previewSelector,
            ),
          ).toContainText(expectedValue);
        } else {
          await test.step(`Skipping preview check for ${suggestionPath.join(' > ')} as it crashes`, async () => {});
        }
      });
    }
  };

  test('bool default false', async ({ page, drupal, canvasEditor }) => {
    const inputName = 'test_bool_default_false';
    await testPropLinking({ page, drupal, canvasEditor, inputName });
  });

  test('bool default true', async ({ page, drupal, canvasEditor }) => {
    const inputName = 'test_bool_default_true';
    await testPropLinking({ page, drupal, canvasEditor, inputName });
  });

  test('string', async ({ page, drupal, canvasEditor }) => {
    const inputName = 'test_string';
    await testPropLinking({ page, drupal, canvasEditor, inputName });
  });

  test('required string', async ({ page, drupal, canvasEditor }) => {
    const inputName = 'test_REQUIRED_string';
    await testPropLinking({ page, drupal, canvasEditor, inputName });
  });

  // test('string format email', async ({ page, drupal, canvasEditor }) => {
  //   const inputName = 'test_string_format_email';
  //   await testPropLinking({ page, drupal, canvasEditor, inputName });
  // });
  //
  // test('string format idn email', async ({ page, drupal, canvasEditor }) => {
  //   const inputName = 'test_string_format_idn_email';
  //   await testPropLinking({ page, drupal, canvasEditor, inputName });
  // });
  //
  // test('required string format uri', async ({ page, drupal, canvasEditor }) => {
  //   const inputName = 'test_REQUIRED_string_format_uri';
  //   await testPropLinking({ page, drupal, canvasEditor, inputName });
  // });
  //
  // test('required string format uri reference web links', async ({
  //   page,
  //   drupal,
  //   canvasEditor,
  // }) => {
  //   const inputName = 'test_REQUIRED_string_format_uri_reference_web_links';
  //   await testPropLinking({ page, drupal, canvasEditor, inputName });
  // });
  //
  // test('string format uri', async ({ page, drupal, canvasEditor }) => {
  //   const inputName = 'test_string_format_uri';
  //   await testPropLinking({ page, drupal, canvasEditor, inputName });
  // });
  //
  // test('string format uri image', async ({ page, drupal, canvasEditor }) => {
  //   const inputName = 'test_string_format_uri_image';
  //   await testPropLinking({ page, drupal, canvasEditor, inputName });
  // });
  //
  // test('string format uri image using ref', async ({
  //   page,
  //   drupal,
  //   canvasEditor,
  // }) => {
  //   const inputName = 'test_string_format_uri_image_using_ref';
  //   await testPropLinking({ page, drupal, canvasEditor, inputName });
  // });
  //
  // test('string format uri reference', async ({
  //   page,
  //   drupal,
  //   canvasEditor,
  // }) => {
  //   const inputName = 'test_string_format_uri_reference';
  //   await testPropLinking({ page, drupal, canvasEditor, inputName });
  // });
  //
  // test('string format iri', async ({ page, drupal, canvasEditor }) => {
  //   const inputName = 'test_string_format_iri';
  //   await testPropLinking({ page, drupal, canvasEditor, inputName });
  // });
  //
  // test('string format iri reference', async ({
  //   page,
  //   drupal,
  //   canvasEditor,
  // }) => {
  //   const inputName = 'test_string_format_iri_reference';
  //   await testPropLinking({ page, drupal, canvasEditor, inputName });
  // });
  //
  // test('integer', async ({ page, drupal, canvasEditor }) => {
  //   const inputName = 'test_integer';
  //   await testPropLinking({ page, drupal, canvasEditor, inputName });
  // });
  //
  // test('integer min/max timestamps', async ({ page, drupal, canvasEditor }) => {
  //   const inputName = 'test_integer_range_minimum_maximum_timestamps';
  //   await testPropLinking({ page, drupal, canvasEditor, inputName });
  // });
  //
  // test('object drupal image', async ({ page, drupal, canvasEditor }) => {
  //   const inputName = 'test_object_drupal_image';
  //   await testPropLinking({ page, drupal, canvasEditor, inputName });
  // });
  //
  // test('string html block', async ({ page, drupal, canvasEditor }) => {
  //   const inputName = 'test_string_html_block';
  //   await testPropLinking({ page, drupal, canvasEditor, inputName });
  // });
  //
  // test('string html', async ({ page, drupal, canvasEditor }) => {
  //   const inputName = 'test_string_html';
  //   await testPropLinking({ page, drupal, canvasEditor, inputName });
  // });
});

export const entityValuesNode4 = {
  'Promoted to front page': 'false',
  Published: 'true',
  'Sticky at top of lists': 'false',
  'The Boolean': 'true',
  'Default translation': 'true',
  'Default revision': 'true',
  Status: 'true',
  'Authored by > User': 'true',
  'Image Upload > Status': 'true',
  'Image Upload > Alternative text': 'secret plans',
  'Image Upload > Title': '',
  'Image Upload > URI': 'public://2025-10/secret-plans_2.jpg',
  'Image Upload > Image Upload':
    '/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/2025-10/secret-plans_2.jpg.webp',
  'Image Upload > URI > Root-relative file URL':
    '/files/2025-10/secret-plans_2.jpg',
  'Image Upload > URI > URI': 'public://2025-10/secret-plans_2.jpg',
  'Image Media > Media > Thumbnail > URI > Root-relative file URL':
    '/files/2025-10/secret-plans_2.jpg',
  'Image Upload > Width': '1000',
  'Image Upload > Height': '1333',
  'Image Upload > File size': '318082',
  'Image Upload > Changed': /\d{9,}/,
  'Image Upload > Created': '1760098357',
  'Image Upload':
    /.*?\/files\/2025-10\/secret-plans_2\.jpg\?alternateWidths=.*?\/files\/styles\/canvas_parametrized_width--%7Bwidth%7D\/public\/2025-10\/secret-plans_2\.jpg\.webp%3Fitok%3D[^ \n]+/,
  'Revision user > User > Name': 'admin',
  'Revision user > User > Email': 'admin@example.com',
  'Revision user > User > Initial email': 'admin@example.com',
  'Revision user > User > Default translation': 'true',
  'Revision user > User > User status': 'true',
  'Revision user > User > Changed': /\d{9,}/,
  'Revision user > User > Last access': /\d{9,}/,
  'Revision user > User > Created': /\d{9,}/,
  'Revision user > User > Last login': /\d{9,}/,
  'Authored by > User > Name': 'admin',
  'Authored by > User > Email': 'admin@example.com',
  'Authored by > User > Initial email': 'admin@example.com',
  'Authored by > User > User status': 'true',
  'Authored by > User > Default translation': 'true',
  'Authored by > User > Changed': /\d{9,}/,
  'Authored by > User > Last access': /\d{9,}/,
  'Authored by > User > Created': /\d{9,}/,
  'Authored by > User > Last login': /\d{9,}/,
  'One Tag > Taxonomy term': 'tag1',
  'One Tag > Revision log message': 'tag1',
  'One Tag > Taxonomy term > Description > Processed text': '',
  'One Tag > Taxonomy term > Revision log message': '*SKIP* (this crashes)',
  'One Tag > Taxonomy term > Name': 'Cool tag',
  'One Tag > Taxonomy term > Published': 'true',
  'One Tag > Taxonomy term > Default revision': 'true',
  'One Tag > Taxonomy term > Default translation': 'true',
  'One Tag > Taxonomy term > Weight': '0',
  'One Tag > Taxonomy term > Changed': /\d{9,}/,
  'One Tag > Taxonomy term > Revision create time': /\d{9,}/,
  'Image Media > Media > Changed': /\d{9,}/,
  'Image Media > Media > Revision create time': /\d{9,}/,
  'Image Media > Media > Authored on': '1760098240',
  'Image Media > Media > Name': 'treehouse.jpeg',
  'Image Media > Media > Published': 'true',
  'Image Media > Media > Default translation': 'true',
  'Image Media > Media > Default revision': 'true',
  'Image Media > Media > Revision log message': 'A page that was made',
  'Image Media > Media > Thumbnail > URI':
    '/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/2025-10/treehouse_0.jpeg.webp%3Fitok',
  'Image Media > Media > Thumbnail > Thumbnail':
    '/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/2025-10/treehouse_0.jpeg.webp%3Fitok',
  Title: 'Debut Page',
  'Revision log message': 'A page that was made',
  'The Link > Link text': 'Dinosaur Comics',
  'The Link > The Link': 'https://qwantz.com',
  'The Link > Resolved URL': 'https://qwantz.com',
  'The Email': 'one@one.com',
  'Authored on': '1760098151',
  Changed: /\d{9,}/,
  'Number List': '1',
  'The Number': '33',
  'Revision create time': /\d{9,}/,
  'Body > Processed summary': '',
  'Body > Processed text':
    'This page emerged on the scene. This page was met with indifference.',
};

const suggestionsByProp = {
  test_bool_default_false: [
    'Default revision',
    'Default translation',
    'Image Media > Media > Published',
    'Image Media > Media > Default translation',
    'Image Media > Media > Default revision',
    'One Tag > Taxonomy term > Published',
    'One Tag > Taxonomy term > Default revision',
    'One Tag > Taxonomy term > Default translation',
    'Revision user > User > Default translation',
    'Revision user > User > User status',
    'Authored by > User > User status',
    'Authored by > User > Default translation',
    'Image Upload > Status',
    'Promoted to front page',
    'Published',
    'Sticky at top of lists',
    'The Boolean',
  ],
  test_bool_default_true: [
    'One Tag > Taxonomy term > Published',
    'One Tag > Taxonomy term > Default revision',
    'One Tag > Taxonomy term > Default translation',
    'Promoted to front page',
    'Published',
    'Sticky at top of lists',
    'The Boolean',
    'Default translation',
    'Default revision',
    'Image Upload > Status',
    'Revision user > User > Default translation',
    'Revision user > User > User status',
    'Authored by > User > User status',
    'Authored by > User > Default translation',
    'Image Media > Media > Published',
    'Image Media > Media > Default translation',
    'Image Media > Media > Default revision',
  ],
  test_string: [
    'One Tag > Taxonomy term > Name',
    // 'One Tag > Taxonomy term > Revision log message',
    'Image Media > Media > Name',
    // 'Image Media > Media > Revision log message',
    'Title',
    'Revision log message',
    'Image Upload > Alternative text',
    'Image Upload > Title',
    'The Link > Link text',
    'Authored by > User > Name',
    'Revision user > User > Name',
  ],
  test_string_multiline: [
    'Revision log message',
    'One Tag > Taxonomy term',
    'Image Media > Media > Revision log message',
  ],
  test_REQUIRED_string: ['Title', 'Image Media > Media > Name'],
  test_string_format_email: [
    'The Email',
    'Revision user > User > Initial email',
    'Revision user > User > Email',
    'Authored by > User > Email',
    'Authored by > User > Initial email',
  ],
  test_string_format_idn_email: [
    'The Email',
    'Revision user > User > Initial email',
    'Revision user > User > Email',
    'Authored by > User > Email',
    'Authored by > User > Initial email',
  ],
  test_REQUIRED_string_format_uri: ['Image Upload > URI'],
  test_REQUIRED_string_format_uri_reference_web_links: [
    'Image Upload > Image Upload',
    'Image Upload > URI > Root-relative file URL',
  ],
  test_string_format_uri: [
    'Image Upload > URI',
    // 'Image Media > Media > Thumbnail > URI',
  ],
  test_string_format_uri_image: [
    'Image Upload > URI > Root-relative file URL',
    'Image Upload > Image Upload',
    'Image Media > Media > Thumbnail > URI > Root-relative file URL',
    'Image Media > Media > Thumbnail > Thumbnail',
  ],
  test_string_format_uri_image_using_ref: [
    'Image Upload > URI > Root-relative file URL',
    'Image Upload > Image Upload',
    'Image Media > Media > Thumbnail > Thumbnail',
    // crashes the ui in a difficult to recover way.
    // 'Image Media > Media > Thumbnail > URI > Root-relative file URL',
  ],
  test_string_format_uri_reference: [
    // crashes the ui in a difficult to recover way.
    // 'The Link > Resolved URL',
    'The Link > The Link',
    'Image Upload > Image Upload',
    'Image Upload > URI > Root-relative file URL',
    'Image Upload > URI > URI',
    // crashes the ui in a difficult to recover way.
    // 'Image Media > Media > Thumbnail > URI > Root-relative file URL',
  ],
  test_string_format_iri: [
    'Image Upload > URI',
    // crashes the ui in a difficult to recover way.
    // 'Image Media > Media > Thumbnail > URI',
  ],
  test_string_format_iri_reference: [
    'The Link > Resolved URL',
    'The Link > The Link',
    'Image Upload > Image Upload',
    'Image Upload > URI > Root-relative file URL',
    'Image Upload > URI > URI',
    'Image Media > Media > Thumbnail > Thumbnail',
    // 'Image Media > Media > Thumbnail > URI > Root-relative file URL',
    // 'Image Media > Media > Thumbnail > URI > URI',
  ],
  test_integer: [
    'Authored on',
    'Changed',
    'Number List',
    'The Number',
    'Revision create time',
    'Image Upload > Width',
    'Image Upload > Height',
    'Image Upload > File size',
    'Image Upload > Changed',
    'Image Upload > Created',
    'Revision user > User > Last access',
    'Revision user > User > Changed',
    'Revision user > User > Last login',
    'Revision user > User > Created',
    'Authored by > User > Last access',
    'Authored by > User > Changed',
    'Authored by > User > Last login',
    'Authored by > User > Created',
    'One Tag > Taxonomy term > Weight',
    'One Tag > Taxonomy term > Changed',
    'One Tag > Taxonomy term > Revision create time',
    'Image Media > Media > Changed',
    'Image Media > Media > Revision create time',
    'Image Media > Media > Authored on',
  ],
  test_integer_range_minimum_maximum_timestamps: [
    'Authored by > User > Last access',
    'Authored by > User > Last login',
    'Revision user > User > Last access',
    'Revision user > User > Last login',
  ],
  test_object_drupal_image: ['Image Upload'],
  test_string_html_block: [
    'Body > Processed summary',
    'Body > Processed text',
    'One Tag > Taxonomy term > Description > Processed text',
  ],
  test_string_html: [
    'Body > Processed summary',
    'Body > Processed text',
    'One Tag > Taxonomy term > Description > Processed text',
  ],
};
export const propNameToSelector = {
  test_bool_default_false: '#test-bool-default-false',
  test_bool_default_true: '#test-bool-default-true',
  test_string: '#test-string code',
  test_string_multiline: '#test-string-multiline',
  test_REQUIRED_string: '#test-required-string',
  test_string_enum: '#test-string-enum',
  test_integer_enum: '#test-integer-enum',
  test_string_format_email: '#test-string-format-email',
  test_string_format_idn_email: '#test-string-format-idn-email',
  test_REQUIRED_string_format_uri: '#test-required-string-format-uri',
  test_REQUIRED_string_format_uri_reference_web_links:
    '#test-required-string-format-uri-reference-web-links',
  test_string_format_uri: '#test-string-format-uri',
  test_string_format_uri_image: '#test-string-format-uri-image',
  test_string_format_uri_image_using_ref:
    '#test-string-format-uri-image-using-ref',
  test_string_format_uri_reference: '#test-string-format-uri-reference',
  test_string_format_iri: '#test-string-format-iri',
  test_string_format_iri_reference: '#test-string-format-iri-reference',
  test_integer: '#test-integer',
  test_integer_range_minimum: '#test-integer-range-minimum',
  test_integer_range_minimum_maximum_timestamps:
    '#test-integer-range-minimum-maximum-timestamps',
  test_object_drupal_image: '#test-object-drupal-image',
  test_object_drupal_image_array: '#test-object-drupal-image-array',
  test_object_drupal_video: '#test-object-drupal-video',
  test_string_html_inline: '#test-string-html-inline',
  test_string_html_block: '#test-string-html-block',
  test_string_html: '#test-string-html',
  test_REQUIRED_string_html_inline: '#test-required-string-html-inline',
  test_REQUIRED_string_html_block: '#test-required-string-html-block',
  test_REQUIRED_string_html: '#test-required-string-html',
  test_array_integer: '#test-array-integer',
};
