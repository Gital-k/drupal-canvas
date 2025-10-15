import { expect } from '@playwright/test';

import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';
import { getModuleDir } from './utilities/DrupalFilesystem';

test.describe.skip('Templates - General', () => {
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
      await page.locator('#content-type').click(); // role="option"
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
    await page.waitForTimeout(1000);
    await expect(page.locator(inputLocator)).not.toBeAttached();
    await expect(page.locator(linkedBoxLocator)).toBeVisible();
    // Confirm that the heading is still linked after making a change to an
    // unlined field
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

      // await drupal.drush('cr');
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

  test('Template - available suggestions', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    await addLinkablePropsComponent({
      page,
      drupal,
      canvasEditor,
    });
    const propLinkerLocators = page.locator('[data-prop-linker-for]');
    const propLinkerCount = await propLinkerLocators.count();
    expect(propLinkerCount).toBeGreaterThanOrEqual(20);

    // Recursive function to build menu structure
    async function buildMenuTree(parentMenu, parentDepth = 0) {
      const items = await page
        .locator(
          `[role="menuitem"][data-child-of="${parentMenu}"]`.trim(),
        )
        .elementHandles();
      `[role="menuitem"][data-child-of="${parentMenu}"]`
      console.log(`SOO [role="menuitem"][data-child-of="${parentMenu}"]`, items.length)
      const tree = [];


      for (const itemHandle of items) {
        const itemText = await itemHandle.textContent();
        const classes = await itemHandle.getAttribute('class');
        const isSubmenu = await itemHandle.getAttribute('data-has-submenu')
        console.log(`Itemtext: ${itemText}, classes ${classes}, isSubmenu: ${isSubmenu}`);
        const isDropdown =
          classes && classes.includes('rt-DropdownMenuSubTrigger');
        const node = { text: itemText, children: [] };
        if (isDropdown) {
          console.log('IS DROPDOWN!!!', itemText);
          const depthAttr = await itemHandle.getAttribute(
            'data-dropdown-depth',
          );
          const currentDropdownDepth = depthAttr ? parseInt(depthAttr, 10) : 0;
          await itemHandle.hover();
          // Wait for dropdown to appear
           await page.waitForTimeout(500); // adjust if needed
          // Find child menuitems with depth = currentDropdownDepth + 1
          const childLocator = page
            .locator(
              `[role="menuitem"][data-child-of="${itemText}"]`,
            );

          const childHandles = await childLocator.elementHandles();
          console.log(
            `${itemText} seek [role="menuitem"][data-dropdown-depth="${currentDropdownDepth + 1}"]`,
            childHandles.length,
          );

          if (childHandles.length > 0) {
            node.children = await buildMenuTree(itemText, currentDropdownDepth + 1);
          }
        }
        tree.push(node);
      }
      return tree;
    }

    for (let i = 0; i < propLinkerCount; i++) {
      const linker = propLinkerLocators.nth(i);
      const linkerValue = await linker.getAttribute('data-prop-linker-for');
      if (linkerValue === 'test_string') {
        await linker.click();
        const suggestionsLocator = page.locator(
          `[data-prop-suggestions-for="${linkerValue}"]`,
        );
        await expect(suggestionsLocator).toBeVisible();
        const menuItems = await suggestionsLocator
          .locator('[role="menuitem"]')
          .allTextContents();

        console.log(`Suggestions for ${linkerValue}:`, menuItems);
        if (expectedSuggestions[linkerValue]) {
          expect(menuItems).toEqual(expectedSuggestions[linkerValue]);
        }
        // Build and log menu tree
        const menuTree = await buildMenuTree(
          `root`,
        );
        console.log(
          `Menu tree for ${linkerValue}:`,
          JSON.stringify(menuTree, null, 2),
        );
        await linker.click();
        await expect(suggestionsLocator).not.toBeVisible();
      }
    }
  });
});

export const entityValuesNode4 = {
  'Promoted to front page': 'false',
  Published: 'true',
  'Sticky at top of lists': 'false',
  'The Boolean': 'true',
  'Default translation': 'true',
  'Default revision': 'true',
  'Image Upload': {
    'Alternative text': 'secret plans',
    Title: '',
    URI: 'public://2025-10/secret-plans_2.jpg',
    'Image Upload':
      '/sites/default/files/2025-10/secret-plans_2.jpg?alternateWidths=/sites/default/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/2025-10/secret-plans_2.jpg.webp%3Fitok%3DZussK9md',
  },
  // There's an alternative structure:
  // accessed by
  // String, format=uri-reference, x-allowed-schemes: [http, https] (required)
  // 'Image Upload': {
  //   'URI': {
  //     'Root-relative file URL': '/sites/default/files/2025-10/secret-plans_2.jpg',
  // .   'URI': 'public://2025-10/secret-plans_2.jpg',
  //    }
  // }
  // Yet another alternative structure accessed by
  // Object, $ref=json-schema-definitions://canvas.module/image
  // 'Image Upload': '<ul>
  //         <li id="test-object-drupal-image--src">src: <code>/sites/default/files/600x400.png</code>
  //         </li><li id="test-object-drupal-image--alt">alt: <code>Boring placeholder</code>
  //         </li><li id="test-object-drupal-image--width">width: <code>600</code>
  //         </li><li id="test-object-drupal-image--height">height: <code>400</code>
  //       </li></ul>'

  'Revision user': {
    User: {
      Name: 'admin',
      Email: 'admin@example.com',
      'Initial email': 'admin@example.com',
    },
  },
  'Authored by': {
    User: {
      Name: 'admin',
      Email: 'admin@example.com',
      'Initial email': 'admin@example.com',
    },
  },
  'One Tag': {
    'Taxonomy term': {
      Name: 'tag1',
      'Revision log message': '*SKIP* (this crashes)',
      Description: {
        'Processed text': '',
      },
    },
  },
  'Image Media': {
    Media: {
      Name: 'treehouse.jpg',
      'Revision log message': '*SKIP* (this crashes)',
      Thumbnail: {
        URI: '*SKIP* (this crashes)',
        // URI is also sometimes nested and has another 'URI'
        // sub-option. See "String, format=iri-reference"
        Thumbnail:
          '/sites/default/files/2025-10/treehouse_0.jpeg?alternateWidths=/sites/default/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/2025-10/treehouse_0.jpeg.webp%3Fitok%3DtXFJ9L1o',
      },
    },
  },
  Title: 'Debut Page',
  'Revision log message': 'A page that was made',
  'The Link': {
    'Link text': 'Dinosaur Comics',
    'The Link': 'http://www.qwantz.com/',
    'Resolved URL': 'http://www.qwantz.com/',
  },
  'The Email': 'one@one.com',
  'Authored on': '1760098151',
  Changed: '1760119110',
  // Shows up for
  'Number List': '1',
  'The Number': '33',
  'Revision create time': '1760119110',
  Body: {
    'Processed summary':
      '<p>This is a paragraph with <strong>bold</strong> text.</p><ul><li>List item 1</li><li>List item 2</li></ul>',
    'Processed text':
      '<p>This page emerged on the scene.<strong> This page was met with indifference</strong>.</p>',
  },
};

const expectedSuggestions = {
  test_bool_default_false: [
    'Promoted to front page',
    'Published',
    'Sticky at top of lists',
    'The Boolean',
    'Default translation',
    'Default revision',
    'Image Upload',
    'Revision user',
    'Authored by',
    'One Tag',
    'Image Media',
  ],
  test_bool_default_true: [
    'Promoted to front page',
    'Published',
    'Sticky at top of lists',
    'The Boolean',
    'Default translation',
    'Default revision',
    'Image Upload',
    'Revision user',
    'Authored by',
    'One Tag',
    'Image Media',
  ],
  test_string: [
    'Title',
    'Revision log message',
    'Image Upload',
    'The Link',
    'Image Media',
    'One Tag',
    'Authored by',
    'Revision user',
  ],
  test_string_multiline: ['Revision log message', 'One Tag', 'Image Media'],
  test_REQUIRED_string: ['Title', 'Image Media'],
  test_string_format_email: ['The Email', 'Revision user', 'Authored by'],
  test_string_format_idn_email: ['The Email', 'Revision user', 'Authored by'],
  test_REQUIRED_string_format_uri: ['Image Upload'],
  test_REQUIRED_string_format_uri_reference_web_links: ['Image Upload'],
  test_string_format_uri: ['Image Upload', 'Image Media'],
  test_string_format_uri_image: ['Image Upload', 'Image Media'],
  test_string_format_uri_image_using_ref: ['Image Upload', 'Image Media'],
  test_string_format_uri_reference: ['The Link', 'Image Upload', 'Image Media'],
  test_string_format_iri: ['Image Upload', 'Image Media'],
  test_string_format_iri_reference: ['The Link', 'Image Upload', 'Image Media'],
  test_integer: [
    'Authored on',
    'Changed',
    'Number List',
    'The Number',
    'Revision create time',
    'Image Upload',
    'Revision user',
    'Authored by',
    'One Tag',
    'Image Media',
  ],
  test_integer_range_minimum_maximum_timestamps: [
    'Authored by',
    'Revision user',
  ],
  test_number: [
    'Authored on',
    'Changed',
    'Number List',
    'The Number',
    'Revision create time',
    'Image Upload',
    'Revision user',
    'Authored by',
    'One Tag',
    'Image Media',
  ],
  test_object_drupal_image: ['Image Upload'],
  test_string_html_block: ['Body', 'One Tag'],
  test_string_html: ['Body', 'One Tag'],
};

// Maps property titles to their HTML id selectors as rendered in linkable-props.twig
export const propTitleToHtmlId = {
  'Bool (default false)': '#test-bool-default-false',
  'Bool (default true)': '#test-bool-default-true',
  'String — single line': '#test-string',
  'String — multi-line': '#test-string-multiline',
  'String (required)': '#test-required-string',
  'String - Enum': '#test-string-enum',
  'Integer - Enum': '#test-integer-enum',
  'String, format=email': '#test-string-format-email',
  'String, format=idn-email': '#test-string-format-idn-email',
  'String, format=uri (required)': '#test-required-string-format-uri',
  'String, format=uri-reference, x-allowed-schemes: [http, https] (required)':
    '#test-required-string-format-uri-reference-web-links',
  'String, format=uri': '#test-string-format-uri',
  'String, format=uri, relative or absolute images only — using contentMediaType=image/* directly':
    '#test-string-format-uri-image',
  'String, format=uri, relative or absolute images only — using $ref':
    '#test-string-format-uri-image-using-ref',
  'String, format=uri-reference': '#test-string-format-uri-reference',
  'String, format=iri': '#test-string-format-iri',
  'String, format=iri-reference': '#test-string-format-iri-reference',
  Integer: '#test-integer',
  'Integer, minimum=0': '#test-integer-range-minimum',
  'Integer, minimum=-2147483648, maximum=2147483648':
    '#test-integer-range-minimum-maximum-timestamps',
  'Object, $ref=json-schema-definitions://canvas.module/image':
    '#test-object-drupal-image',
  'Array of object, $ref=json-schema-definitions://canvas.module/image':
    '#test-object-drupal-image-array',
  'Object, $ref=json-schema-definitions://canvas.module/video':
    '#test-object-drupal-video',
  'String with HTML formatting (inline)': '#test-string-html-inline',
  'String with HTML formatting (block)': '#test-string-html-block',
  'String with HTML formatting (no formatting context, defaults to "block")':
    '#test-string-html',
  'String with HTML formatting (inline, required)':
    '#test-required-string-html-inline',
  'String with HTML formatting (block, required)':
    '#test-required-string-html-block',
  'String with HTML formatting (no formatting context, defaults to "block", required)':
    '#test-required-string-html',
  'Array, integers': '#test-array-integer',
};
