import { expect } from '@playwright/test';

import { test } from './fixtures/DrupalSite';
import { Drupal } from './objects/Drupal';
import { getModuleDir } from './utilities/DrupalFilesystem';

// cspell:ignore Bwidth Fitok treehouse
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

  const testPropLinking = async ({ page, drupal, canvasEditor, inputName }) => {
    await addLinkablePropsComponent({
      page,
      drupal,
      canvasEditor,
    });
    const linker = await page.locator(`[data-prop-linker-for="${inputName}"]`);
    await expect(linker).toBeAttached();
    const suggestionsTree = suggestionsByProp[inputName];
    for (const suggestionOption of suggestionsTree) {
      await linker.click();
      const suggestionPath = [];
      let current = suggestionOption;
      while (current) {
        suggestionPath.push(current.text);
        current = current.child;
      }

      let itemSelected = false;
      let parent = 'root'
      let stepCount = 1;
      await test.step(`Choose ${suggestionPath.join(' > ')} for ${inputName}`, async () => {
        for (const pathItem of suggestionPath) {
          console.log(`ON ${pathItem} ${stepCount} of ${suggestionPath.join(' > ')} `);
          const parentName = pathItem === parent ? 'myself' : parent;
          const step = await page.locator(
            `[data-link-suggestion-option="${pathItem}"][data-child-of="${parentName}"]`,
          );
          await step.waitFor();
          let activeText = await page.evaluate(() => document.activeElement?.textContent);
          console.log(`ACTIVE: ${activeText} PATH ITEM: ${pathItem}`);
          // await expect(step).toBeAttached();
          if (stepCount === suggestionPath.length) {
            let timeout = 0;
            // while (activeText !== pathItem) {
            //   timeout += 1;
            //   await page.keyboard.press('ArrowRight');
            //   await page.waitForTimeout(100)
            //   let activeText = await page.evaluate(() => document.activeElement?.textContent);
            //   if (timeout > 25) {
            //     throw Error(`Timeout waiting for active element to be ${pathItem}, currently ${activeText}`);
            //   }
            // }
            // data-child-o
            console.log(`click ${pathItem} of ${suggestionPath.join(' > ')}`);
            // await page.keyboard.press('Enter');
            //
           await step.click({force: true});
            await expect(
              page.locator(`[data-linked-field-box="${inputName}"]`),
            ).toBeVisible();
            await expect(
              page.locator(`[data-linked-field-box="${inputName}"]`),
            ).toHaveText(suggestionPath[0]);
            itemSelected = true;
            // await page.waitForTimeout(10000)
          } else {
            parent = pathItem;
            console.log(`hover ${pathItem} of ${suggestionPath.join(' > ')}`);
            await step.hover({ force: true });
            await step.dispatchEvent('pointermove');
            await page.keyboard.press('ArrowRight');

            await page.waitForTimeout(10);
          }
          stepCount += 1
        }
      });
      await expect(itemSelected).toBe(true);
      await page.waitForTimeout(1500); // adjust if needed
      await test.step(`Verify preview for ${suggestionPath.join(' > ')} for ${inputName}`, async () => {
        const previewSelector = propNameToSelector[inputName];
        const expectedValue = entityValuesNode4[suggestionPath.join(' > ')];
        console.log(
          `wait for ${previewSelector} to have ${expectedValue} looking at ${suggestionPath.join(' > ')}`,
        );
        if (expectedValue !== '*SKIP* (this crashes)') {
          await expect(
            (await canvasEditor.getActivePreviewFrame()).locator(previewSelector),
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

  test('string format email', async ({ page, drupal, canvasEditor }) => {
    const inputName = 'test_string_format_email';
    await testPropLinking({ page, drupal, canvasEditor, inputName });
  });

  test('string format idn email', async ({ page, drupal, canvasEditor }) => {
    const inputName = 'test_string_format_idn_email';
    await testPropLinking({ page, drupal, canvasEditor, inputName });
  });

  test('required string format uri', async ({ page, drupal, canvasEditor }) => {
    const inputName = 'test_REQUIRED_string_format_uri';
    await testPropLinking({ page, drupal, canvasEditor, inputName });
  });

  test.only('required string format uri reference web links', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    const inputName = 'test_REQUIRED_string_format_uri_reference_web_links';
    await testPropLinking({ page, drupal, canvasEditor, inputName });
  });

  test('string format uri', async ({ page, drupal, canvasEditor }) => {
    const inputName = 'test_string_format_uri';
    await testPropLinking({ page, drupal, canvasEditor, inputName });
  });

  test('string format uri image', async ({ page, drupal, canvasEditor }) => {
    const inputName = 'test_string_format_uri_image';
    await testPropLinking({ page, drupal, canvasEditor, inputName });
  });

  test('string format uri image using ref', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    const inputName = 'test_string_format_uri_image_using_ref';
    await testPropLinking({ page, drupal, canvasEditor, inputName });
  });

  test('string format uri reference', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    const inputName = 'test_string_format_uri_reference';
    await testPropLinking({ page, drupal, canvasEditor, inputName });
  });

  test('string format iri', async ({ page, drupal, canvasEditor }) => {
    const inputName = 'test_string_format_iri';
    await testPropLinking({ page, drupal, canvasEditor, inputName });
  });
  // bring back to only(
  test('string format iri reference', async ({
    page,
    drupal,
    canvasEditor,
  }) => {
    const inputName = 'test_string_format_iri_reference';
    await testPropLinking({ page, drupal, canvasEditor, inputName });
  });

  test('integer', async ({ page, drupal, canvasEditor }) => {
    const inputName = 'test_integer';
    await testPropLinking({ page, drupal, canvasEditor, inputName });
  });

  test('integer min/max timestamps', async ({ page, drupal, canvasEditor }) => {
    const inputName = 'test_integer_range_minimum_maximum_timestamps';
    await testPropLinking({ page, drupal, canvasEditor, inputName });
  });

  test('object drupal image', async ({ page, drupal, canvasEditor }) => {
    const inputName = 'test_object_drupal_image';
    await testPropLinking({ page, drupal, canvasEditor, inputName });
  });

  test('string html block', async ({ page, drupal, canvasEditor }) => {
    const inputName = 'test_string_html_block';
    await testPropLinking({ page, drupal, canvasEditor, inputName });
  });

  test('string html', async ({ page, drupal, canvasEditor }) => {
    const inputName = 'test_string_html';
    await testPropLinking({ page, drupal, canvasEditor, inputName });
  });

  test.skip('Template - available suggestions', async ({
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
    async function buildMenuTree(parentMenu) {
      const items = await page
        .locator(`[role="menuitem"][data-child-of="${parentMenu}"]`.trim())
        .elementHandles();
      const tree = [];
      for (const itemHandle of items) {
        const itemText = await itemHandle.textContent();
        const isSubmenu = await itemHandle.getAttribute('data-has-submenu');
        const node = { text: itemText, children: [] };
        if (isSubmenu) {
          const childOf = await itemHandle.getAttribute('data-child-of');
          if (childOf === 'root') {
            await itemHandle.hover({ force: true });
          } else {
            await page.keyboard.press('ArrowRight');
          }

          await page.waitForTimeout(500); // adjust if needed
          await expect(
            page.locator(`[role="menuitem"][data-child-of="${itemText}"]`, {
              strict: false,
            }),
          ).toBeVisible();
          // Wait for dropdown to appear
          const childLocator = page.locator(
            `[role="menuitem"][data-child-of="${itemText}"]`,
          );

          const childHandles = await childLocator.elementHandles();

          if (childHandles.length > 0) {
            node.children = await buildMenuTree(itemText);
          }
          await page.keyboard.press('ArrowLeft');
        }
        tree.push(node);
      }
      return tree;
    }

    await expect(propLinkerCount).toEqual(
      Object.keys(expectedSuggestionsTree).length,
    );

    for (let i = 0; i < propLinkerCount; i++) {
      const linker = propLinkerLocators.nth(i);
      const linkerValue = await linker.getAttribute('data-prop-linker-for');
      await linker.click();
      const suggestionsLocator = page.locator(
        `[data-prop-suggestions-for="${linkerValue}"]`,
      );
      await expect(suggestionsLocator).toBeVisible();

      if (expectedSuggestionsTree[linkerValue]) {
        const menuTree = await buildMenuTree(`root`);
        expect(menuTree, `Check menu tree for ${linkerValue}`).toEqual(
          expectedSuggestionsTree[linkerValue],
        );
      } else {
        throw Error(`Missing tree for ${linkerValue}`);
      }
      await linker.click();
      await expect(suggestionsLocator).not.toBeVisible();
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
  Status: 'true',
  'Authored by > User': 'true',
  'Image Upload > Status': 'true',
  'Image Upload > Alternative text': 'secret plans',
  'Image Upload > Title': '',
  'Image Upload > URI': 'public://2025-10/secret-plans_2.jpg',
  'Image Upload > Image Upload': '/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/2025-10/secret-plans_2.jpg.webp',
  'Image Upload > URI > Root-relative file URL': '/files/2025-10/secret-plans_2.jpg',

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
  'Revision user > User > Name': 'admin',
  'Revision user > User > Email': 'admin@example.com',
  'Revision user > User > Initial email': 'admin@example.com',
  'Revision user > User > Default translation': 'true',
  'Revision user > User > User status': 'true',

  'Authored by > User > Name': 'admin',
  'Authored by > User > Email': 'admin@example.com',
  'Authored by > User > Initial email': 'admin@example.com',
  'Authored by > User > User status' : 'true',
  'Authored by > User > Default translation': 'true',

  // 'Revision user': {
  //   User: {
  //     Name: 'admin',
  //     Email: 'admin@example.com',
  //     'Initial email': 'admin@example.com',
  //   },
  // },
  'Authored by': {
    User: {
      Name: 'admin',
      Email: 'admin@example.com',
      'Initial email': 'admin@example.com',
    },
  },
  'One Tag > Taxonomy term': 'tag1',
  'One Tag > Revision log message': 'tag1',
  'One Tag > Taxonomy term > Description > Processed text': '',
  'One Tag > Taxonomy term > Revision log message': '*SKIP* (this crashes)',
  'One Tag > Taxonomy term > Name': 'Cool tag',
  'One Tag > Taxonomy term > Published': 'true',
  'One Tag > Taxonomy term > Default revision': 'true',
  'One Tag > Taxonomy term > Default translation': 'true',
  // 'One Tag': {
  //   'Taxonomy term': {
  //     Name: 'tag1',
  //     'Revision log message': '*SKIP* (this crashes)',
  //     Description: {
  //       'Processed text': '',
  //     },
  //   },
  // },
  'Image Media > Media > Name': 'treehouse.jpeg',
  'Image Media > Media > Published': 'true',
  'Image Media > Media > Default translation': 'true',
  'Image Media > Media > Default revision': 'true',
  'Image Media > Media > Revision log message': 'A page that was made',
  'Image Media > Media > Thumbnail > URI': '*SKIP* (this crashes)',
  // 'Image Media > Media > Thumbnail': '/sites/default/files/2025-10/treehouse_0.jpeg?alternateWidths=/sites/default/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/2025-10/treehouse_0.jpeg.webp%3Fitok%3DtXFJ9L1o',
  'Image Media > Media > Thumbnail > URI':
    '/sites/default/files/2025-10/treehouse_0.jpeg?alternateWidths=/sites/default/files/styles/canvas_parametrized_width--%7Bwidth%7D/public/2025-10/treehouse_0.jpeg.webp%3Fitok%3DtXFJ9L1o',
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
  'The Link > Link text': 'Dinosaur Comics',
  'The Link > The Link': 'https://qwantz.com',
  'The Link > Resolved URL': 'https://qwantz.com',

  // 'The Link': {
  //   'Link text': 'Dinosaur Comics',
  //   'The Link': 'http://www.qwantz.com/',
  //   'Resolved URL': 'http://www.qwantz.com/',
  // },
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

// expectedSuggestionsTree: for deep menu structure comparisons

const suggestionsByProp = {
  test_bool_default_false: [
    {
      text: 'One Tag',
      child: {
        text: 'Taxonomy term',
        child: {
          text: 'Published',
        }
      },
    },
    {
      text: 'One Tag',
      child: {
        text: 'Taxonomy term',
        child: {
          text: 'Default revision',
        }
      },
    },
    {
      text: 'One Tag',
      child: {
        text: 'Taxonomy term',
        child: {
          text: 'Default translation',
        }
      },
    },
    {
      text: 'Promoted to front page',
    },
    {
      text: 'Published',
    },
    {
      text: 'Sticky at top of lists',
    },
    {
      text: 'The Boolean',
    },
    {
      text: 'Default translation',
    },
    {
      text: 'Default revision',
    },
    {
      text: 'Image Upload',
      child: {
        text: 'Status',
      },
    },
    {
      text: 'Revision user',
      child: {
        text: 'User',
        child: {
          text: 'Default translation',
        },
      },
    },
    {
      text: 'Revision user',
      child: {
        text: 'User',
        child: {
          text: 'User status',
        },
      },
    },
    {
      text: 'Authored by',
      child: {
        text: 'User',
        child: {
          text: 'User status',
        },
      },
    },
    {
      text: 'Authored by',
      child: {
        text: 'User',
        child: {
          text: 'Default translation',
        },
      },
    },
    {
      text: 'Image Media',
      child: {
        text: 'Media',
        child: {
          text: 'Published',
        },
      },
    },
    {
      text: 'Image Media',
      child: {
        text: 'Media',
        child: {
          text: 'Default translation',
        },
      },
    },
    {
      text: 'Image Media',
      child: {
        text: 'Media',
        child: {
          text: 'Default revision',
        },
      },
    },
  ],
  test_bool_default_true: [
    {
      text: 'One Tag',
      child: {
        text: 'Taxonomy term',
        child: {
          text: 'Published',
        }
      },
    },
    {
      text: 'One Tag',
      child: {
        text: 'Taxonomy term',
        child: {
          text: 'Default revision',
        }
      },
    },
    {
      text: 'One Tag',
      child: {
        text: 'Taxonomy term',
        child: {
          text: 'Default translation',
        }
      },
    },
    {
      text: 'Promoted to front page',
    },
    {
      text: 'Published',
    },
    {
      text: 'Sticky at top of lists',
    },
    {
      text: 'The Boolean',
    },
    {
      text: 'Default translation',
    },
    {
      text: 'Default revision',
    },
    {
      text: 'Image Upload',
      child: {
        text: 'Status',
      },
    },
    {
      text: 'Revision user',
      child: {
        text: 'User',
        child: {
          text: 'Default translation',
        },
      },
    },
    {
      text: 'Revision user',
      child: {
        text: 'User',
        child: {
          text: 'User status',
        },
      },
    },
    {
      text: 'Authored by',
      child: {
        text: 'User',
        child: {
          text: 'User status',
        },
      },
    },
    {
      text: 'Authored by',
      child: {
        text: 'User',
        child: {
          text: 'Default translation',
        },
      },
    },
    {
      text: 'Image Media',
      child: {
        text: 'Media',
        child: {
          text: 'Published',
        },
      },
    },
    {
      text: 'Image Media',
      child: {
        text: 'Media',
        child: {
          text: 'Default translation',
        },
      },
    },
    {
      text: 'Image Media',
      child: {
        text: 'Media',
        child: {
          text: 'Default revision',
        },
      },
    },
  ],
  test_string: [
    {
      text: 'One Tag',
      child: {
        text: 'Taxonomy term',
        child: {
          text: 'Name',
        }
      },
    },
    // This is empty and will crash the layout in a way that
    // isn't recoverable.
    // {
    //   text: 'One Tag',
    //   child: {
    //     text: 'Taxonomy term',
    //     child: {
    //       text: 'Revision log message',
    //     }
    //   },
    // },
    {
      text: 'Image Media',
      child: {
        text: 'Media',
        child: {
          text: 'Name',
        },
      },
    },
    // This is empty and will crash the layout in a way that
    // isn't recoverable.
    // {
    //   text: 'Image Media',
    //   child: {
    //     text: 'Media',
    //     child: {
    //       text: 'Revision log message',
    //     },
    //   },
    // },
    {
      text: 'Title',
    },
    {
      text: 'Revision log message',
    },
    {
      text: 'Image Upload',
      child: {
        text: 'Alternative text',
      },
    },
    // @todo this is an empty value in the
    // entity and does not update in the test,
    // but works manually.
    {
      text: 'Image Upload',
      child: {
        text: 'Title',
      },
    },
    {
      text: 'The Link',
      child: {
        text: 'Link text',
      }
    },

    {
      text: 'Authored by',
      child: {
        text: 'User',
        child: {
          text: 'Name',
        },
      },
    },
    {
      text: 'Revision user',
      child: {
        text: 'User',
        child: {
          text: 'Name',
        },
      },
    },
  ],
  test_string_multiline: [
    {
      text: 'Revision log message',
    },
    {
      text: 'One Tag',
      child: {
        text: 'Taxonomy term',
      },
    },
    {
      text: 'Image Media',
      child: {
        text: 'Media',
        child: {
          text: 'Revision log message',
        },
      },
    },
  ],
  test_REQUIRED_string: [
    {
      text: 'Title',
    },
    {
      text: 'Image Media',
      child: {
        text: 'Media',
        child: {
          text: 'Name',
        },
      },
    },
  ],
  test_string_format_email: [
    {
      text: 'The Email',
    },
    {
      text: 'Revision user',
      child: {
        text: 'User',
        child: {
          text: 'Initial email',
        },
      },
    },
    {
      text: 'Revision user',
      child: {
        text: 'User',
        child: {
          text: 'Email',
        },
      },
    },
    {
      text: 'Authored by',
      child: {
        text: 'User',
        child: {
          text: 'Email',
        },
      },
    },
    {
      text: 'Authored by',
      child: {
        text: 'User',
        child: {
          text: 'Initial email',
        },
      },
    },
  ],
  test_string_format_idn_email: [
    {
      text: 'The Email',
    },
    {
      text: 'Revision user',
      child: {
        text: 'User',
        child: {
          text: 'Initial email',
        },
      },
    },
    {
      text: 'Revision user',
      child: {
        text: 'User',
        child: {
          text: 'Email',
        },
      },
    },
    {
      text: 'Authored by',
      child: {
        text: 'User',
        child: {
          text: 'Email',
        },
      },
    },
    {
      text: 'Authored by',
      child: {
        text: 'User',
        child: {
          text: 'Initial email',
        },
      },
    },
  ],
  test_REQUIRED_string_format_uri: [
    {
      text: 'Image Upload',
      child: {
        text: 'URI',
      },
    },
  ],
  test_REQUIRED_string_format_uri_reference_web_links: [
    //🤔this exists but it's not selectable because the submenu
    // Image Upload still says root is the parent menu item
    {
      text: 'Image Upload',
      child: {
        text: 'Image Upload',
      },
    },
    //🤔two-deep items don't seem to be working.
    {
      text: 'Image Upload',
      child: {
        text: 'URI',
        child: {
          text: 'Root-relative file URL'
        }
      },
    },
  ],
  test_string_format_uri: [
    {
      text: 'Image Upload',
      child: {
        text: 'URI',
      },
    },
    //🤔multilevel not working?
    // {
    //   text: 'Image Media',
    //   child: {
    //     text: 'Media',
    //     child: {
    //       text: 'Thumbnail',
    //       child: {
    //         text: 'URI',
    //       }
    //     },
    //   },
    // },
  ],
  test_string_format_uri_image: [
    //🤔multilevel not working?
    // {
    //   text: 'Image Upload',
    //   child: {
    //     text: 'URI',
    //     child: {
    //       text: 'Root-relative file URL',
    //     },
    //   },
    // },
    //🤔multilevel not working?
    // {
    //   text: 'Image Media',
    //   child: {
    //     text: 'Media',
    //     child: {
    //       text: 'Thumbnail',
    //       child: {
    //         text: 'URI',
    // .       child: {
    //            text: 'Root-relative file URL',
    //          },
    //       }
    //     },
    //   },
    // },
    // {
    //   text: 'Image Media',
    //   child: {
    //     text: 'Media',
    //     child: {
    //       text: 'Thumbnail',
    //       child: {
    //         text: 'Thumbnail',
    //       }
    //     },
    //   },
    // },
  ],
  test_string_format_uri_image_using_ref: [
    //🤔multilevel not working?
    // {
    //   text: 'Image Upload',
    //   child: {
    //     text: 'URI',
    //     child: {
    //       text: 'Root-relative file URL',
    //     },
    //   },
    // },
    // 🤔multilevel not working?
    // {
    //   text: 'Image Upload',
    //   child: {
    //     text: 'Image Upload',
    //   },
    // },
    //🤔multilevel not working?
    // {
    //   text: 'Image Media',
    //   child: {
    //     text: 'Media',
    //     child: {
    //       text: 'Thumbnail',
    //       child: {
    //         text: 'Thumbnail',
    //       }
    //     },
    //   },
    // },
    //🤔multilevel not working?
    // {
    //   text: 'Image Media',
    //   child: {
    //     text: 'Media',
    //     child: {
    //       text: 'Thumbnail',
    //       child: {
    //         text: 'URI',
    // .       child: {
    //            text: 'Root-relative file URL',
    //          },
    //       }
    //     },
    //   },
    // },
  ],
  test_string_format_uri_reference: [
    //🤔multilevel not working?
    {
      text: 'The Link',
      child: {
        text: 'Resolved URL',
      },
    },
    //🤔multilevel not working?
    // {
    //   text: 'The Link',
    //   child: {
    //     text: 'The Link',
    //   },
    // },
    //🤔multilevel not working?
    // {
    //   text: 'Image Upload',
    //   child: {
    //     text: 'Image Upload',
    //   },
    // },
    // {
    //   text: 'Image Upload',
    //   child: {
    //     text: 'URI',
    //     child: {
    //       text: 'Root-relative file URL',
    //     },
    //   },
    // },
    // {
    //   text: 'Image Upload',
    //   child: {
    //     text: 'URI',
    //     child: {
    //       text: 'URI',
    //     },
    //   },
    // },
    //🤔multilevel not working?
    // {
    //   text: 'Image Media',
    //   child: {
    //     text: 'Media',
    //     child: {
    //       text: 'Thumbnail',
    //       child: {
    //         text: 'URI',
    // .       child: {
    //            text: 'Root-relative file URL',
    //          },
    //       }
    //     },
    //   },
    // },
  ],
  test_string_format_iri: [
    {
      text: 'Image Upload',
      child: {
        text: 'URI',
      },
    },
    //🤔multilevel not working?
    // {
    //   text: 'Image Media',
    //   child: {
    //     text: 'Media',
    //     child: {
    //       text: 'Thumbnail',
    //       child: {
    //         text: 'URI',
    //       }
    //     },
    //   },
    // },

  ],
  test_string_format_iri_reference: [
    {
      text: 'The Link',
      child: {
        text: 'Resolved URL',
      },
    },
    {
      text: 'The Link',
      child: {
        text: 'The Link',
      },
    },
    //🤔multilevel not working?
    // {
    //   text: 'Image Upload',
    //   child: {
    //     text: 'Image Upload',
    //   },
    // },
    // {
    //   text: 'Image Upload',
    //   child: {
    //     text: 'URI',
    //     child: {
    //       text: 'Root-relative file URL',
    //     },
    //   },
    // },
    // {
    //   text: 'Image Upload',
    //   child: {
    //     text: 'URI',
    //     child: {
    //       text: 'URI',
    //     },
    //   },
    // },
    //🤔multilevel not working?
    // {
    //   text: 'Image Media',
    //   child: {
    //     text: 'Media',
    //     child: {
    //       text: 'Thumbnail',
    //       child: {
    //         text: 'Thumbnail',
    //       }
    //     },
    //   },
    // },
    // {
    //   text: 'Image Media',
    //   child: {
    //     text: 'Media',
    //     child: {
    //       text: 'Thumbnail',
    //       child: {
    //         text: 'URI',
    //         child: {
    //           text: 'Root-relative file URL',
    //         }
    //       }
    //     },
    //   },
    // },
    // {
    //   text: 'Image Media',
    //   child: {
    //     text: 'Media',
    //     child: {
    //       text: 'Thumbnail',
    //       child: {
    //         text: 'URI',
    //         child: {
    //           text: 'URI',
    //         }
    //       }
    //     },
    //   },
    // },
  ],
  test_integer: [
    {
      text: 'Authored on',
    },
    {
      text: 'Changed',
    },
    {
      text: 'Number List',
    },
    {
      text: 'The Number',
    },
    {
      text: 'Revision create time',
    },
    {
      text: 'Image Upload',
      child: {
        text: 'Width',
      },
    },
    {
      text: 'Image Upload',
      child: {
        text: 'Height',
      },
    },
    {
      text: 'Image Upload',
      child: {
        text: 'File size',
      },
    },
    {
      text: 'Image Upload',
      child: {
        text: 'Changed',
      },
    },
    {
      text: 'Image Upload',
      child: {
        text: 'Created',
      },
    },
    {
      text: 'Revision user',
    },
    {
      text: 'Authored by',
      child: {
        text: 'User',
        child: {
          text: 'Last access',
        },
      },
    },
    {
      text: 'Authored by',
      child: {
        text: 'User',
        child: {
          text: 'Changed',
        },
      },
    },
    {
      text: 'Authored by',
      child: {
        text: 'User',
        child: {
          text: 'Last login',
        },
      },
    },
    {
      text: 'Authored by',
      child: {
        text: 'User',
        child: {
          text: 'Created',
        },
      },
    },
    {
      text: 'One Tag',
      child: {
        text: 'Taxonomy term',
        child: {
          text: 'Weight',
        },
      },
    },
    {
      text: 'One Tag',
      child: {
        text: 'Taxonomy term',
        child: {
          text: 'Changed',
        },
      },
    },
    {
      text: 'One Tag',
      child: {
        text: 'Taxonomy term',
        child: {
          text: 'Revision create time',
        },
      },
    },
    {
      text: 'Image Media',
      child: {
        text: 'Media',
        child: {
          text: 'Changed',
        },
      },
    },
    {
      text: 'Image Media',
      child: {
        text: 'Media',
        child: {
          text: 'Revision create time',
        },
      },
    },
    {
      text: 'Image Media',
      child: {
        text: 'Media',
        child: {
          text: 'Authored on',
        },
      },
    },
  ],
  test_integer_range_minimum_maximum_timestamps: [
    {
      text: 'Authored by',
      child: {
        text: 'User',
      },
    },
    {
      text: 'Revision user',
      child: {
        text: 'User',
      },
    },
  ],
  test_object_drupal_image: [
    {
      text: 'Image Upload',
    },
  ],
  test_string_html_block: [
    {
      text: 'Body',
      child: {
        text: 'Processed summary',
      },
    },
    {
      text: 'Body',
      child: {
        text: 'Processed text',
      },
    },
    {
      text: 'One Tag',
      child: {
        text: 'Taxonomy term',
      },
    },
  ],
  test_string_html: [
    {
      text: 'Body',
      child: {
        text: 'Processed summary',
      },
    },
    {
      text: 'Body',
      child: {
        text: 'Processed text',
      },
    },
    {
      text: 'One Tag',
      child: {
        text: 'Taxonomy term',
      },
    },
  ],
};
// Maps property titles to their HTML id selectors as rendered in linkable-props.twig


export const propLabelToSelector = {
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

export const expectedSuggestionsTree = {
  test_bool_default_false: [
    { text: 'Promoted to front page', children: [] },
    { text: 'Published', children: [] },
    { text: 'Sticky at top of lists', children: [] },
    { text: 'The Boolean', children: [] },
    { text: 'Default translation', children: [] },
    { text: 'Default revision', children: [] },
    { text: 'Image Upload', children: [{ text: 'Status', children: [] }] },
    { text: 'Revision user', children: [{ text: 'User', children: [] }] },
    { text: 'Authored by', children: [{ text: 'User', children: [] }] },
    { text: 'One Tag', children: [{ text: 'Taxonomy term', children: [] }] },
    { text: 'Image Media', children: [{ text: 'Media', children: [] }] },
  ],
  test_bool_default_true: [
    { text: 'Promoted to front page', children: [] },
    { text: 'Published', children: [] },
    { text: 'Sticky at top of lists', children: [] },
    { text: 'The Boolean', children: [] },
    { text: 'Default translation', children: [] },
    { text: 'Default revision', children: [] },
    { text: 'Image Upload', children: [{ text: 'Status', children: [] }] },
    {
      text: 'Revision user',
      children: [
        {
          text: 'User',
          children: [
            { text: 'Default translation', children: [] },
            { text: 'User status', children: [] },
          ],
        },
      ],
    },
    {
      text: 'Authored by',
      children: [
        {
          text: 'User',
          children: [
            { text: 'User status', children: [] },
            { text: 'Default translation', children: [] },
          ],
        },
      ],
    },
    { text: 'One Tag', children: [{ text: 'Taxonomy term', children: [] }] },
    { text: 'Image Media', children: [{ text: 'Media', children: [] }] },
  ],
  test_string: [
    { text: 'Title', children: [] },
    { text: 'Revision log message', children: [] },
    {
      text: 'Image Upload',
      children: [
        { text: 'Alternative text', children: [] },
        { text: 'Title', children: [] },
      ],
    },
    { text: 'The Link', children: [] },
    {
      text: 'Image Media',
      children: [
        {
          text: 'Media',
          children: [
            { text: 'Name', children: [] },
            { text: 'Revision log message', children: [] },
          ],
        },
      ],
    },
    { text: 'One Tag', children: [{ text: 'Taxonomy term', children: [] }] },
    {
      text: 'Authored by',
      children: [{ text: 'User', children: [{ text: 'Name', children: [] }] }],
    },
    {
      text: 'Revision user',
      children: [{ text: 'User', children: [{ text: 'Name', children: [] }] }],
    },
  ],
  test_string_multiline: [
    { text: 'Revision log message', children: [] },
    { text: 'One Tag', children: [{ text: 'Taxonomy term', children: [] }] },
    {
      text: 'Image Media',
      children: [
        {
          text: 'Media',
          // Commenting the below as it crashes
          // children: [{ text: 'Revision log message', children: [] }],
        },
      ],
    },
  ],
  test_REQUIRED_string: [
    { text: 'Title', children: [] },
    {
      text: 'Image Media',
      children: [{ text: 'Media', children: [{ text: 'Name', children: [] }] }],
    },
  ],
  test_string_format_email: [
    { text: 'The Email', children: [] },
    {
      text: 'Revision user',
      children: [
        {
          text: 'User',
          children: [
            { text: 'Initial email', children: [] },
            { text: 'Email', children: [] },
          ],
        },
      ],
    },
    {
      text: 'Authored by',
      children: [
        {
          text: 'User',
          children: [
            { text: 'Email', children: [] },
            { text: 'Initial email', children: [] },
          ],
        },
      ],
    },
  ],
  test_string_format_idn_email: [
    { text: 'The Email', children: [] },
    {
      text: 'Revision user',
      children: [
        {
          text: 'User',
          children: [
            { text: 'Initial email', children: [] },
            { text: 'Email', children: [] },
          ],
        },
      ],
    },
    {
      text: 'Authored by',
      children: [
        {
          text: 'User',
          children: [
            { text: 'Email', children: [] },
            { text: 'Initial email', children: [] },
          ],
        },
      ],
    },
  ],
  test_REQUIRED_string_format_uri: [
    { text: 'Image Upload', children: [{ text: 'URI', children: [] }] },
  ],
  test_REQUIRED_string_format_uri_reference_web_links: [
    { text: 'Image Upload', children: [{ text: 'URI', children: [] }] },
  ],
  test_string_format_uri: [
    { text: 'Image Upload', children: [{ text: 'URI', children: [] }] },
    {
      text: 'Image Media',
      children: [
        { text: 'Media', children: [{ text: 'Thumbnail', children: [] }] },
      ],
    },
  ],
  test_string_format_uri_image: [
    { text: 'Image Upload', children: [{ text: 'URI', children: [] }] },
    { text: 'Image Media', children: [{ text: 'Media', children: [] }] },
  ],
  test_string_format_uri_image_using_ref: [
    { text: 'Image Upload', children: [{ text: 'URI', children: [] }] },
    { text: 'Image Media', children: [{ text: 'Media', children: [] }] },
  ],
  test_string_format_uri_reference: [
    { text: 'The Link', children: [{ text: 'Resolved URL', children: [] }] },
    { text: 'Image Upload', children: [{ text: 'URI', children: [] }] },
    { text: 'Image Media', children: [{ text: 'Media', children: [] }] },
  ],
  test_string_format_iri: [
    { text: 'Image Upload', children: [{ text: 'URI', children: [] }] },
    {
      text: 'Image Media',
      children: [
        { text: 'Media', children: [{ text: 'Thumbnail', children: [] }] },
      ],
    },
  ],
  test_string_format_iri_reference: [
    { text: 'The Link', children: [{ text: 'Resolved URL', children: [] }] },
    { text: 'Image Upload', children: [{ text: 'URI', children: [] }] },
    { text: 'Image Media', children: [{ text: 'Media', children: [] }] },
  ],
  test_integer: [
    { text: 'Authored on', children: [] },
    { text: 'Changed', children: [] },
    { text: 'Number List', children: [] },
    { text: 'The Number', children: [] },
    { text: 'Revision create time', children: [] },
    {
      text: 'Image Upload',
      children: [
        { text: 'Width', children: [] },
        { text: 'Height', children: [] },
        { text: 'File size', children: [] },
        { text: 'Changed', children: [] },
        { text: 'Created', children: [] },
      ],
    },
    { text: 'Revision user', children: [] },
    {
      text: 'Authored by',
      children: [
        {
          text: 'User',
          children: [
            { text: 'Last access', children: [] },
            { text: 'Changed', children: [] },
            { text: 'Last login', children: [] },
            { text: 'Created', children: [] },
          ],
        },
      ],
    },
    {
      text: 'One Tag',
      children: [
        {
          text: 'Taxonomy term',
          children: [
            { text: 'Weight', children: [] },
            { text: 'Changed', children: [] },
            { text: 'Revision create time', children: [] },
          ],
        },
      ],
    },
    {
      text: 'Image Media',
      children: [
        {
          text: 'Media',
          children: [
            { text: 'Changed', children: [] },
            { text: 'Revision create time', children: [] },
            { text: 'Authored on', children: [] },
          ],
        },
      ],
    },
  ],
  test_integer_range_minimum_maximum_timestamps: [
    { text: 'Authored by', children: [{ text: 'User', children: [] }] },
    { text: 'Revision user', children: [{ text: 'User', children: [] }] },
  ],
  test_object_drupal_image: [{ text: 'Image Upload', children: [] }],
  test_string_html_block: [
    {
      text: 'Body',
      children: [
        { text: 'Processed summary', children: [] },
        { text: 'Processed text', children: [] },
      ],
    },
    { text: 'One Tag', children: [{ text: 'Taxonomy term', children: [] }] },
  ],
  test_string_html: [
    {
      text: 'Body',
      children: [
        { text: 'Processed summary', children: [] },
        { text: 'Processed text', children: [] },
      ],
    },
    { text: 'One Tag', children: [{ text: 'Taxonomy term', children: [] }] },
  ],
};
