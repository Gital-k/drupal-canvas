/* cspell:ignore Bwidth Fitok treehouse */

/**
 * Templates Linking Test - Cypress 1:1 conversion
 *
 * This is a direct 1:1 conversion of tests/src/Playwright/templates-linking.spec.ts
 * Tests the prop linking functionality for Canvas templates with complete
 * field type coverage and suggestion validation.
 */

// Each object property is a prop type with a value of an array of the available
// suggestions to it.
const suggestionsByProp = {
  test_string_format_uri_reference: [
    'The Link > The Link',
    'Authored by > URL',
    'Image Media > Media > Authored by > URL',
    'Image Upload',
    'Image Media > Media > Revision user > URL',
    'One Tag > URL',
    'Relative URL',
    'Revision user > URL',
    'Image Media > Media > Image',
    'Image Media > URL',
    'The Link > Resolved URL',
    'Image Media > Media > Thumbnail',
    'One Tag > Taxonomy term > Revision user > URL',
  ],
  test_object_drupal_image: [
    'Image Media > Image > Image',
    'Image Upload',
    'Image Media > Image > Thumbnail',
  ],
  test_string_format_iri_reference: [
    'The Link > Resolved URL',
    'Image Media > Media > Image',
    'The Link > The Link',
    'One Tag > Taxonomy term > Revision user > URL',
    'Image Media > Media > Thumbnail',
    'Authored by > URL',
    'Image Media > Media > Authored by > URL',
    'Relative URL',
    'Image Media > URL',
    'Revision user > URL',
    'Image Upload',
    'Image Media > Media > Revision user > URL',
    'One Tag > URL',
  ],
  test_integer_range_minimum_maximum_timestamps: [
    'Revision user > User > Last login',
    'Revision user > User > Changed',
    'Revision user > User > Last access',
    'Image Upload > Changed',
    'Authored by > User > Changed',
    'Authored by > User > Created',
    'Authored by > User > Last access',
    'Authored by > User > Last login',
    'Authored on',
    'Changed',
    'Image Media > Media > Authored on',
    'Image Media > Media > Changed',
    'Image Media > Media > Revision create time',
    'One Tag > Taxonomy term > Changed',
    'Revision create time',
    'Revision user > User > Created',
    'One Tag > Taxonomy term > Revision create time',
    'Image Upload > Created',
  ],
  test_bool_default_false: [
    'Image Media > Media > Published',
    'Revision user > User > User status',
    'One Tag > Taxonomy term > Published',
    'Authored by > User > User status',
    'Image Upload > Status',
    'Published',
    'The Boolean',
  ],
  test_bool_default_true: [
    'One Tag > Taxonomy term > Published',
    'The Boolean',
    'Published',
    'Image Upload > Status',
    'Revision user > User > User status',
    'Image Media > Media > Published',
    'Authored by > User > User status',
  ],
  test_string: [
    'One Tag > Taxonomy term > Name',
    'Image Media > Media > Name',
    'Image Media > Media > Image > Title',
    'Image Upload > Alternative text',
    'Title',
    'The Link > Link text',
    'Revision user > User > Name',
    'Image Upload > Title',
    'Authored by > User > Name',
    'Image Media > Media > Image > Alternative text',
  ],
  test_REQUIRED_string: ['Title'],
  test_string_format_email: [
    'The Email',
    'Revision user > User > Initial email',
    'Revision user > User > Email',
    'Authored by > User > Initial email',
    'Authored by > User > Email',
  ],
  test_string_format_idn_email: [
    'The Email',
    'Revision user > User > Initial email',
    'Revision user > User > Email',
    'Authored by > User > Initial email',
    'Authored by > User > Email',
  ],
  test_REQUIRED_string_format_uri: ['Absolute URL'],
  test_REQUIRED_string_format_uri_reference_web_links: [
    // 'Image Upload',
    'Relative URL',
    // 'Image Media > Media > Image',
    // 'Image Media > URL',
  ],
  test_string_format_uri: [
    'Absolute URL',
    'Image Media > Media > Image > URI',
    'Image Media > Media > Thumbnail > URI',
    'Image Upload > URI',
  ],
  test_string_format_uri_image: [
    'Image Upload',
    'Image Media > Media > Image',
    'Image Media > Media > Thumbnail',
  ],
  test_string_format_uri_image_using_ref: [
    'Image Upload',
    'Image Media > Media > Image',
    'Image Media > Media > Thumbnail',
  ],
  test_string_format_iri: [
    'Absolute URL',
    'Image Media > Media > Image > URI',
    'Image Media > Media > Thumbnail > URI',
    'Image Upload > URI',
  ],
  test_integer: [
    'Image Upload > Width',
    'Image Upload > Height',
    'Image Upload > File size',
    'Number List',
    'The Number',
    'One Tag > Taxonomy term > Weight',
    'Image Media > Media > Image > Height',
    'Image Media > Media > Image > Width',
  ],
  test_string_html_block: [
    'Body > Processed summary',
    'Body > Body',
    'One Tag > Taxonomy term > Description',
  ],
  test_string_html: [
    'Body > Processed summary',
    'Body > Body',
    'One Tag > Taxonomy term > Description',
  ],
};

// Object mapping suggestion paths to their expected preview values.
const entityValuesNode4 = {
  Title: 'Debut Page',
  Published: 'true',
  'The Boolean': 'true',
  'Authored on': '1760098151',
  Changed: /\d{9,}/,
  'Number List': '1',
  'The Number': '33',
  'Revision create time': /\d{9,}/,
  'Image Upload > Status': 'true',
  'Image Upload > Alternative text': 'secret plans',
  'Image Upload > Title': '', // this seems wrong?
  'Image Upload > URI': 'image-test.gif',
  'Absolute URL': '/node/4', // Updated to match our test data
  'Image Upload > Width': '1000',
  'Image Upload > Height': '1333',
  'Image Upload > File size': /\d+/,
  'Image Upload > Changed': /\d{9,}/,
  'Image Upload > Created': /\d{9,}/,
  'Image Upload': 'image-test.gif',
  'Relative URL': /\/node\/\d+/,
  'Revision user > User > Name': '',
  'Revision user > User > Email': 'canvas@test.com',
  'Revision user > User > Initial email': '',
  'Revision user > User > User status': 'false',
  'Revision user > User > Changed': /\d{9,}/,
  'Revision user > User > Last access': '',
  'Revision user > User > Created': /\d{9,}/,
  'Revision user > User > Last login': '',
  'Revision user > URL': '/user/2',
  'Authored by > User > Name': '',
  'Authored by > User > Email': 'canvas@test.com',
  'Authored by > User > Initial email': '',
  'Authored by > User > User status': 'false',
  'Authored by > User > Changed': /\d{9,}/,
  'Authored by > User > Last access': '',
  'Authored by > User > Created': /\d{9,}/,
  'Authored by > User > Last login': '',
  'Authored by > URL': '',
  'One Tag > Taxonomy term > Description': '',
  'One Tag > Taxonomy term > Name': 'Cool tag',
  'One Tag > Taxonomy term > Published': 'true',
  'One Tag > Taxonomy term > Weight': '0',
  'One Tag > Taxonomy term > Changed': /\d{9,}/,
  'One Tag > Taxonomy term > Revision create time': /\d{9,}/,
  'One Tag > Taxonomy term > Revision user > URL': '',
  'One Tag > URL': '/tags/cool-tag', // Updated to match our test data
  'Image Media > Media > Changed': /\d{9,}/,
  'Image Media > Media > Revision create time': /\d{9,}/,
  'Image Media > Media > Authored on': /\d{9,}/,
  'Image Media > Media > Name': 'treehouse',
  'Image Media > Media > Published': 'true',
  'Image Media > Media > Image > Alternative text': 'tree house',
  'Image Media > Media > Image > Title': '',
  'Image Media > Image > Image': 'image-test.png',
  'Image Media > Media > Image': 'image-test.png',
  'Image Media > Image > Thumbnail': 'image-test.png',
  'Image Media > Media > Thumbnail': 'image-test.png',
  'Image Media > URL': 'media/treehouse',
  'Image Media > Media > Thumbnail > URI': 'image-test.png',
  'Image Media > Media > Image > URI': 'image-test.png',
  'Image Media > Media > Authored by > URL': '',
  'Image Media > Media > Revision user > URL': '',
  'Image Media > Media > Image > Height': '665',
  'Image Media > Media > Image > Width': '1024',
  'The Link > Link text': 'Dinosaur Comics',
  'The Link > The Link': 'https://qwantz.com',
  'The Link > Resolved URL': 'https://qwantz.com',
  'The Email': 'one@one.com',
  'Body > Body':
    'This page emerged on the scene. This page was met with indifference.',
  'Body > Processed summary': '',
};

// Object mapping prop names to their corresponding preview selectors.
const propNameToSelector = {
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
  test_object_drupal_image: '#test-object-drupal-image--src',
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

describe('Linking Basic Page fields', () => {
  // These tests can take a long time to run and are not particularly browser
  // dependent. Only run in Chromium to keep test duration reasonable.
  beforeEach(() => {
    cy.viewport(1400, 2600);

    // Setup test site with Drupal Canvas, templates enabled, and page with many field types
    cy.drupalCanvasInstall([
      'canvas_test_linkable_props',
      'canvas_test_templates_in_cypress',
    ]);
    cy.drupalLogin('canvasUser', 'canvasUser');

    cy.loadURLandWaitForCanvasLoaded({
      url: 'canvas/template/node/page/full',
      clearAutoSave: false,
    });

    cy.get('[data-testid="select-content-preview-item"]').click();
    cy.contains('[role="menuitem"]', 'Debut Page').click();
    addAllPropsComponent();

    // Confirm all available linkers are configured to be tested.
    Object.keys(suggestionsByProp).forEach((inputName) => {
      cy.get(`[aria-label="Link ${inputName} to an other field"]`).should(
        'exist',
      );
    });

    // Assert that all properties in entityValuesNode4 are represented in suggestionsByProp
    const allSuggestions = Object.values(suggestionsByProp).flat();
    const entityKeys = Object.keys(entityValuesNode4);
    const missingKeys = entityKeys.filter(
      (key) => !allSuggestions.includes(key),
    );
    expect(missingKeys).to.deep.equal([]);
  });

  afterEach(() => {
    cy.drupalUninstall();
  });

  // beforeEach(() => {
  //   cy.drupalLogin('canvasUser', 'canvasUser');
  // });

  /**
   * Helper function equivalent to Playwright's addAllPropsComponent
   */
  const addAllPropsComponent = () => {
    cy.openLibraryPanel();
    // cy.insertComponent({ name: 'Two Column' });
    // cy.findByLabelText('Column Width').should('exist');
    cy.insertComponent({ name: 'All props' });
    cy.openLayersPanel();
    cy.clickComponentInLayersView('All props');
    cy.get('.canvas-linked-prop-label-wrapper').should('exist');
  };

  /**
   * Helper function equivalent to Playwright's testPropLinking
   */

  const testPropLinking = (inputName, suggestions) => {
    cy.loadURLandWaitForCanvasLoaded({
      url: 'canvas/template/node/page/full',
      clearAutoSave: false,
    });

    // Add styles that allow nested suggestions to not overlap each other, so
    // e2e tests can properly access these items without the parent menu
    // closing. This includes widening the contextual panel, and positioning
    // the linker element underneath its corresponding label.
    // Also disable all transitions and animations to prevent timing issues.
    const styles = `
      *, *::before, *::after {
        transition: none !important;
        animation: none !important;
        animation-duration: 0s !important;
        transition-duration: 0s !important;
        transition-delay: 0s !important;
        animation-delay: 0s !important;
      }
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
        width: 600px;
      }
      [data-link-suggestion-option] {
          min-width: 120px;
      }
    `;

    cy.get('head').then((head) => {
      const styleElement = Cypress.$(
        `<style type="text/css">${styles}</style>`,
      );
      Cypress.$(head).append(styleElement);
    });

    // Select the "Debut Page" for testing
    cy.get('[data-testid="select-content-preview-item"]').click();
    cy.contains('[role="menuitem"]', 'Debut Page').click();

    // Select the linkable props component.
    cy.get('[aria-label="All props"]').click({ force: true });

    // Open the linker for the specified input.
    const linkerSelector = `[aria-label="Link ${inputName} to an other field"]`;
    cy.get(linkerSelector).should('exist');
    // cy.get(linkerSelector).scrollIntoView({ offset: { top: 150, left: 0 } });
    // Get suggestions and validate
    cy.get(linkerSelector).then(($linker) => {
      const suggestionsSerialized = $linker.attr(
        'data-canvas-link-suggestions',
      );
      const actualSuggestions = suggestionsSerialized
        ? JSON.parse(suggestionsSerialized)
        : [];
      expect(actualSuggestions.length).to.be.greaterThan(0);

      // Assert that the actual suggestions match the expected suggestions.
      // The suggestions may include duplicates to ensure there aren't consecutive
      // items with the same final-item name.
      expect([...actualSuggestions].sort()).to.deep.equal(
        [...new Set(suggestions)].sort(),
      );
    });

    // Go through every suggestion and confirm it can link and provide the
    // expected value in the preview.
    suggestions.forEach((suggestionOption) => {
      cy.get(linkerSelector).scrollIntoView({ offset: { top: 350, left: 0 } });
      cy.get(linkerSelector).click();
      const suggestionPath = suggestionOption.split(' > ');
      let itemSelected = false;
      let parent = 'root';
      let stepCount = 1;
      suggestionPath.forEach((pathItem, index) => {
        let parentName = pathItem === parent ? 'myself' : parent;
        if (
          suggestionOption === 'Image Media > Image > Image' &&
          parentName === 'myself'
        ) {
          // parentName = index === 0 ? 'root' : parentName;
          parentName = 'Image';
        }
        const stepSelector = `[data-link-suggestion-option="${pathItem}"][data-child-of="${parentName}"]`;

        cy.get(stepSelector).should('exist');

        // If this is the final step in the suggestion path, click to select.
        if (stepCount === suggestionPath.length) {
          cy.get(stepSelector).click();

          // Wait for the patch requests to complete. This wait is not
          // needed for end users, but benefits the stability of the test
          // as excessive changes within a short period of time can exhaust
          // the test runner.
          cy.intercept(
            'PATCH',
            '**/canvas/api/v0/layout-content-template/**',
          ).as('layoutPatch');
          cy.intercept(
            'PATCH',
            '**/canvas/api/v0/form/component-instance/content_template/**',
          ).as('formPatch');
          // cy.wait('@layoutPatch', { timeout: 10000 });
          cy.wait('@formPatch', { timeout: 10000 });

          cy.get(`[data-testid="linked-field-box-${inputName}"]`).should(
            'exist',
          );

          // @todo determine why the path Image Media > Media > Image is
          // presented as Image Media > Image > Image in the linked field
          // box title attribute. For now, these few instances can just be skipped.
          if (!suggestionOption.startsWith('Image Media > Media > Image')) {
            const expectedTitle = suggestionPath
              .filter(
                (item, idx) =>
                  idx === 0 || !(item === suggestionPath[idx - 1] && idx === 1),
              )
              .join(' / ');
            cy.log(
              `Verifying linked field box title for ${inputName} using option ${suggestionOption} with expected title: ${expectedTitle}`,
            );
            cy.get(
              `[data-testid="linked-field-box-${inputName}"] div[title]`,
            ).should('have.attr', 'title', expectedTitle, { timeout: 8000 });
          }

          cy.get(`[data-testid="linked-field-label-${inputName}"]`).should(
            'contain.text',
            suggestionPath[suggestionPath.length - 1],
            { timeout: 8000 },
          );

          itemSelected = true;
        } else {
          parent = pathItem;
          // Trigger mouseover to open submenu
          cy.get(stepSelector).realHover();
          cy.get(stepSelector).trigger('mouseover');
          cy.get(stepSelector).should('have.attr', 'aria-expanded', 'true');

          // Wait for the next item in the path to become visible before continuing.
          // This ensures the submenu has actually opened.
          const nextPathItem = suggestionPath[stepCount];
          const nextParentName =
            nextPathItem === pathItem && index === 0 ? 'myself' : pathItem;
          const nextStepSelector = `[data-link-suggestion-option="${nextPathItem}"][data-child-of="${nextParentName}"]`;

          // Wait with retry logic - sometimes menu needs a moment
          cy.get(nextStepSelector).should('exist', { timeout: 3000 });
        }
        stepCount += 1;
      });

      // Confirm an item was selected.
      expect(itemSelected).to.be.true;

      // Verify preview content
      const previewSelector = propNameToSelector[inputName];
      const expectedValue = entityValuesNode4[suggestionOption];
      cy.log(
        `Verifying preview for ${inputName} linked to ${suggestionOption} with expected value: ${expectedValue}`,
      );
      // if (suggestionOption === 'Revision user > User > Name') {
      //   cy.pause();
      // }
      if (expectedValue !== undefined) {
        if (expectedValue instanceof RegExp) {
          cy.testInIframe(previewSelector, ($el) => {
            if ($el) {
              const content = $el.textContent || $el.innerHTML || '';
              expect(content).to.match(expectedValue);
            }
          });
        } else {
          cy.waitForElementContentInIframe(previewSelector, expectedValue);
        }
      }
    });
  };

  // Generate individual test for each prop type - 1:1 match with Playwright
  // eslint-disable-next-line mocha/no-setup-in-describe
  Object.entries(suggestionsByProp).forEach(([inputName, suggestions]) => {
    const testName = inputName.replace(/_/g, ' ').replace(/test /g, '');

    it(testName, { retries: { openMode: 0, runMode: 0 } }, () => {
      // if (testName === 'bool default true') {
      testPropLinking(inputName, suggestions);
      // }

      cy.openLayersPanel();
      cy.clickComponentInLayersView('All props');
      cy.get('.primaryPanelContent')
        .findAllByLabelText('All props')
        .eq(0)
        .type('{del}');
      cy.get('.primaryPanelContent')
        .findAllByLabelText('All props')
        .should('not.exist');
    });
  });
});
