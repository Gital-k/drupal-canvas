/**
 * Comprehensive tests for multi-value form design for link fields.
 *
 * This test covers the new popover-based multi-value form UI for
 * link fields.
 *
 * The new design features:
 * - Popover-based editing (click item → popover with input).
 * - Custom drag handles and remove buttons via DrupalInputMultivalueForm.
 */

describe('Multivalue Form Design – Link Field', () => {
  before(() => {
    cy.drupalCanvasInstall([
      'canvas_test_article_fields',
      // @todo remove once https://drupal.org/i/3577946 is fixed.
      // Required for new multi-value form UI.
      'canvas_dev_mode',
    ]);
  });

  beforeEach(() => {
    cy.drupalSession();
    cy.drupalLogin('canvasUser', 'canvasUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  /**
   * Helper function to open the URL sub-field popover for a specific row and
   * type a URL.
   *
   * Each link row has two DrupalInputMultivalueForm instances. The first
   * (_listItem_ at index 0 within the row) is the URL input.
   */
  const typeUrlInRow = (fieldAlias, rowIndex, url) => {
    cy.get(fieldAlias)
      .find('tbody tr')
      .eq(rowIndex)
      .find('[class*="_listItem_"]')
      .eq(0)
      .click();
    cy.get('[role="dialog"]').find('input').first().clear();
    cy.get('[role="dialog"]').find('input').first().type(url);
    cy.get('[role="dialog"]').find('input').first().type('{enter}');
    cy.get('[role="dialog"]').should('not.exist');
  };

  /**
   * Helper function to open the title sub-field popover for a specific row and
   * type a link text.
   *
   * Each link row has two DrupalInputMultivalueForm instances. The second
   * (_listItem_ at index 1 within the row) is the title (link text) input.
   */
  const typeTitleInRow = (fieldAlias, rowIndex, title) => {
    cy.get(fieldAlias)
      .find('tbody tr')
      .eq(rowIndex)
      .find('[class*="_listItem_"]')
      .eq(1)
      .click();
    cy.get('[role="dialog"]').find('input[type="text"]').first().clear();
    cy.get('[role="dialog"]').find('input[type="text"]').first().type(title);
    cy.get('[role="dialog"]')
      .find('input[type="text"]')
      .first()
      .type('{enter}');
    cy.get('[role="dialog"]').should('not.exist');
  };

  /**
   * Helper to verify the text shown in the URL list item of a row.
   */
  const verifyUrlRowText = (fieldAlias, rowIndex, expectedText) => {
    cy.get(fieldAlias)
      .find('tbody tr')
      .eq(rowIndex)
      .find('[class*="_listItem_"]')
      .eq(0)
      .find('[class*="_itemText_"]')
      .should('have.text', expectedText);
  };

  /**
   * Helper to verify the text shown in the title list item of a row.
   */
  const verifyTitleRowText = (fieldAlias, rowIndex, expectedText) => {
    cy.get(fieldAlias)
      .find('tbody tr')
      .eq(rowIndex)
      .find('[class*="_listItem_"]')
      .eq(1)
      .find('[class*="_itemText_"]')
      .should('have.text', expectedText);
  };

  /**
   * Helper function to confirm the URL values displayed in all rows.
   * Reads the first _listItem_ per row (URL sub-field).
   */
  const confirmUrlInputs = (fieldAlias, expectedUrls) => {
    cy.get(fieldAlias)
      .find('tbody tr')
      .should('have.length', expectedUrls.length)
      .then(($rows) => {
        const items = [];
        $rows.each((ix, row) => {
          const listItems = Cypress.$(row).find('[class*="_listItem_"]');
          if (listItems.length > 0) {
            const textElement = Cypress.$(listItems[0]).find(
              '[class*="_itemText_"]',
            );
            const text = textElement.text().trim();
            items.push(text === 'Empty' ? '' : text);
          } else {
            items.push('');
          }
        });
        expect(items).to.deep.equal(expectedUrls);
      });
  };

  /**
   * Helper function to confirm the title values displayed in all rows.
   * Reads the second _listItem_ per row (title sub-field).
   */
  const confirmTitleInputs = (fieldAlias, expectedTitles) => {
    cy.get(fieldAlias)
      .find('tbody tr')
      .should('have.length', expectedTitles.length)
      .then(($rows) => {
        const items = [];
        $rows.each((ix, row) => {
          const listItems = Cypress.$(row).find('[class*="_listItem_"]');
          if (listItems.length > 1) {
            const textElement = Cypress.$(listItems[1]).find(
              '[class*="_itemText_"]',
            );
            const text = textElement.text().trim();
            items.push(text === 'Empty' ? '' : text);
          } else {
            items.push('');
          }
        });
        expect(items).to.deep.equal(expectedTitles);
      });
  };

  it('renders multi-value link fields with new popover-based UI', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });

    cy.findByTestId('canvas-contextual-panel--page-data').should(
      'have.attr',
      'data-state',
      'active',
    );
    cy.findByTestId('canvas-page-data-form').as('entityForm');

    // Find the multi-value link field container.
    cy.findByRole('heading', { name: 'Canvas Unlimited Link' })
      .parents('.js-form-wrapper')
      .as('unlimited-link');

    // Verify the multi-value container exists with proper structure.
    cy.get('@unlimited-link').find('.multivalue-container').should('exist');
    cy.get('@unlimited-link').find('table').should('be.visible');
    cy.get('@unlimited-link').find('tbody tr').should('have.length', 2);

    // Each link row has two list items (URL + title), so 2 rows × 2 = 4 total.
    cy.get('@unlimited-link')
      .find('[class*="_listItem_"]')
      .should('have.length', 4);

    // Verify the first row shows the default URL value.
    verifyUrlRowText('@unlimited-link', 0, 'https://drupal.org');
    // Verify the first row shows the default title value.
    verifyTitleRowText('@unlimited-link', 0, 'Drupal');

    // Verify the second row shows "Empty" for both sub-fields.
    verifyUrlRowText('@unlimited-link', 1, 'Empty');
    verifyTitleRowText('@unlimited-link', 1, 'Empty');
  });

  it('can edit link URL and title using popover interface', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.findByRole('heading', { name: 'Canvas Unlimited Link' })
      .parents('.js-form-wrapper')
      .as('unlimited-link');

    cy.intercept({
      url: '**/canvas/api/v0/layout/node/2',
      times: 1,
      method: 'POST',
    }).as('updatePreview');

    // Populate the empty second row URL using the popover interface.
    typeUrlInRow('@unlimited-link', 1, 'https://www.example.com');

    cy.wait('@updatePreview');
    cy.findByLabelText('Loading Preview').should('not.exist');

    // Verify the URL was set.
    verifyUrlRowText('@unlimited-link', 1, 'https://www.example.com');

    cy.intercept({
      url: '**/canvas/api/v0/layout/node/2',
      times: 1,
      method: 'POST',
    }).as('updatePreview2');

    // Populate the title for the second row.
    typeTitleInRow('@unlimited-link', 1, 'Example Site');

    cy.wait('@updatePreview2');
    cy.findByLabelText('Loading Preview').should('not.exist');

    // Verify the title was set.
    verifyTitleRowText('@unlimited-link', 1, 'Example Site');

    // Confirm all values.
    confirmUrlInputs('@unlimited-link', [
      'https://drupal.org',
      'https://www.example.com',
    ]);
    confirmTitleInputs('@unlimited-link', ['Drupal', 'Example Site']);
  });

  it('can add new link items using "+ Add new" button', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.findByRole('heading', { name: 'Canvas Unlimited Link' })
      .parents('.js-form-wrapper')
      .as('unlimited-link');

    const entityFormSelector = '[data-testid="canvas-page-data-form"]';

    // Populate the empty second item first.
    typeUrlInRow('@unlimited-link', 1, 'https://www.example.com');

    const waitForPreview = () => {
      cy.intercept({
        url: '**/canvas/api/v0/layout/node/2',
        times: 1,
        method: 'POST',
      }).as('updatePreview');
      cy.get(document.activeElement).blur();
      cy.wait('@updatePreview');
    };

    waitForPreview();

    // Verify the "+ Add new" button text.
    cy.get('@unlimited-link')
      .findByRole('button', { name: '+ Add new' })
      .should('be.visible');

    // Add another item.
    cy.get('@unlimited-link')
      .findByRole('button', { name: '+ Add new' })
      .click();

    cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');
    cy.get('@unlimited-link').find('tbody tr').should('have.length', 3);

    // Populate the new item.
    typeUrlInRow('@unlimited-link', 2, 'https://www.cypress.io');
    waitForPreview();
    cy.waitForAjax();

    confirmUrlInputs('@unlimited-link', [
      'https://drupal.org',
      'https://www.example.com',
      'https://www.cypress.io',
    ]);
  });

  it('can drag and drop link items with custom drag handles', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.findByRole('heading', { name: 'Canvas Unlimited Link' })
      .parents('.js-form-wrapper')
      .as('unlimited-link');

    const entityFormSelector = '[data-testid="canvas-page-data-form"]';

    // Populate the empty second item.
    typeUrlInRow('@unlimited-link', 1, 'https://www.example.com');

    const waitForPreview = () => {
      cy.intercept({
        url: '**/canvas/api/v0/layout/node/2',
        times: 1,
        method: 'POST',
      }).as('updatePreview');
      cy.get(document.activeElement).blur();
      cy.wait('@updatePreview');
    };

    waitForPreview();

    // Add a third item.
    cy.get('@unlimited-link')
      .findByRole('button', { name: '+ Add new' })
      .click();
    cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

    typeUrlInRow('@unlimited-link', 2, 'https://www.cypress.io');
    waitForPreview();
    cy.waitForAjax();

    confirmUrlInputs('@unlimited-link', [
      'https://drupal.org',
      'https://www.example.com',
      'https://www.cypress.io',
    ]);

    cy.log('Move "item 3" to position 2 using custom drag handle');

    // Ensure the drop target is in the viewport.
    cy.get('@unlimited-link').find('tbody tr').eq(0).scrollIntoView();

    const dndDefaults = {
      position: 'topLeft',
      scrollBehavior: false,
    };

    // Verify custom drag handles are present.
    cy.get('@unlimited-link')
      .find('.canvas-drag-handle a.tabledrag-handle')
      .should('have.length', 3);

    // Verify custom SVG drag handle icons are present.
    cy.get('@unlimited-link')
      .find('.canvas-drag-handle a.tabledrag-handle .drag-handle-icon')
      .should('have.length', 3);

    cy.get(
      '[data-drupal-selector="edit-field-cvt-unlimited-link"] tr.draggable:nth-child(3) [title="Change order"]',
    ).realDnd(
      '[data-drupal-selector="edit-field-cvt-unlimited-link"] tr.draggable:nth-child(2)',
      dndDefaults,
    );

    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');
    cy.waitForAjax();

    confirmUrlInputs('@unlimited-link', [
      'https://drupal.org',
      'https://www.cypress.io',
      'https://www.example.com',
    ]);
  });

  it('can remove link items using popover remove button', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.findByRole('heading', { name: 'Canvas Unlimited Link' })
      .parents('.js-form-wrapper')
      .as('unlimited-link');

    const entityFormSelector = '[data-testid="canvas-page-data-form"]';

    // Populate the empty second item.
    typeUrlInRow('@unlimited-link', 1, 'https://www.example.com');

    const waitForPreview = () => {
      cy.intercept({
        url: '**/canvas/api/v0/layout/node/2',
        times: 1,
        method: 'POST',
      }).as('updatePreview');
      cy.get(document.activeElement).blur();
      cy.wait('@updatePreview');
    };

    waitForPreview();

    // Add a third item.
    cy.get('@unlimited-link')
      .findByRole('button', { name: '+ Add new' })
      .click();
    cy.selectorShouldHaveUpdatedFormBuildId(entityFormSelector);
    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');

    typeUrlInRow('@unlimited-link', 2, 'https://www.cypress.io');
    waitForPreview();
    cy.waitForAjax();

    confirmUrlInputs('@unlimited-link', [
      'https://drupal.org',
      'https://www.example.com',
      'https://www.cypress.io',
    ]);

    // Open the URL popover for the second item and click Remove.
    cy.get('@unlimited-link')
      .find('tbody tr')
      .eq(1)
      .find('[class*="_listItem_"]')
      .eq(0)
      .click();

    cy.get('[role="dialog"]').should('be.visible');

    cy.get('[role="dialog"]')
      .findByRole('button', { name: /Remove/i })
      .should('be.visible');

    cy.get('[role="dialog"]')
      .findByRole('button', { name: /Remove/i })
      .click();

    cy.get('[role="dialog"]').should('not.exist');

    cy.get('body[data-canvas-ajax-behaviors="true"]').should('not.exist');
    cy.waitForAjax();

    cy.get('@unlimited-link').find('tbody tr').should('have.length', 2);
  });

  it('link URL popover opens and closes correctly', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');

    cy.findByRole('heading', { name: 'Canvas Unlimited Link' })
      .parents('.js-form-wrapper')
      .as('unlimited-link');

    // Click the URL list item in the first row to open the popover.
    cy.get('@unlimited-link')
      .find('tbody tr')
      .eq(0)
      .find('[class*="_listItem_"]')
      .eq(0)
      .click();

    cy.get('[role="dialog"]').should('be.visible');

    // The popover header should contain the field label.
    cy.get('[role="dialog"]').should('contain', 'Canvas Unlimited Link');

    // The input should show the current URL value.
    cy.get('[role="dialog"]')
      .find('input')
      .first()
      .should('be.visible')
      .should('have.value', 'https://drupal.org');

    // The Close button should exist.
    cy.get('[role="dialog"]').find('[aria-label="Close"]').should('exist');

    // Close the popover.
    cy.get('[aria-label="Close"]').click();
    cy.get('[role="dialog"]').should('not.exist');
  });

  it('link title popover opens and closes correctly', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');

    cy.findByRole('heading', { name: 'Canvas Unlimited Link' })
      .parents('.js-form-wrapper')
      .as('unlimited-link');

    // Click the title list item in the first row to open the popover.
    cy.get('@unlimited-link')
      .find('tbody tr')
      .eq(0)
      .find('[class*="_listItem_"]')
      .eq(1)
      .click();

    cy.get('[role="dialog"]').should('be.visible');

    // The popover header should contain the field label.
    cy.get('[role="dialog"]').should('contain', 'Canvas Unlimited Link');

    // The input should show the current title value.
    cy.get('[role="dialog"]')
      .find('input[type="text"]')
      .first()
      .should('be.visible')
      .should('have.value', 'Drupal');

    // The Close button should exist.
    cy.get('[role="dialog"]').find('[aria-label="Close"]').should('exist');

    // Close the popover.
    cy.get('[aria-label="Close"]').click();
    cy.get('[role="dialog"]').should('not.exist');
  });

  it('popover discards uncommitted changes when closed without Enter', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');

    cy.findByRole('heading', { name: 'Canvas Unlimited Link' })
      .parents('.js-form-wrapper')
      .as('unlimited-link');

    const originalUrl = 'https://drupal.org';
    const originalTitle = 'Drupal';

    // Open the URL popover, type something, then close without pressing Enter.
    cy.get('@unlimited-link')
      .find('tbody tr')
      .eq(0)
      .find('[class*="_listItem_"]')
      .eq(0)
      .click();

    cy.get('[role="dialog"]').find('input').first().clear();
    cy.get('[role="dialog"]')
      .find('input')
      .first()
      .type('https://this-should-not-be-saved.com');

    cy.get('[aria-label="Close"]').click();
    cy.get('[role="dialog"]').should('not.exist');

    // The original URL should be restored.
    verifyUrlRowText('@unlimited-link', 0, originalUrl);

    // Open the title popover, type something, then close without pressing Enter.
    cy.get('@unlimited-link')
      .find('tbody tr')
      .eq(0)
      .find('[class*="_listItem_"]')
      .eq(1)
      .click();

    cy.get('[role="dialog"]').find('input[type="text"]').first().clear();
    cy.get('[role="dialog"]')
      .find('input[type="text"]')
      .first()
      .type('This should not be saved');

    cy.get('[aria-label="Close"]').click();
    cy.get('[role="dialog"]').should('not.exist');

    // The original title should be restored.
    verifyTitleRowText('@unlimited-link', 0, originalTitle);
  });

  it('maintains form state across multiple link popover interactions', () => {
    cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
    cy.findByTestId('canvas-page-data-form').as('entityForm');
    cy.get('@entityForm').recordFormBuildId();

    cy.findByRole('heading', { name: 'Canvas Unlimited Link' })
      .parents('.js-form-wrapper')
      .as('unlimited-link');

    // Modify the first row's URL.
    cy.get('@unlimited-link')
      .find('tbody tr')
      .eq(0)
      .find('[class*="_listItem_"]')
      .eq(0)
      .click();

    cy.get('[role="dialog"]').find('input').first().clear();
    cy.get('[role="dialog"]')
      .find('input')
      .first()
      .type('https://modified-url.com{enter}');

    cy.get('[role="dialog"]').should('not.exist');

    cy.intercept({
      url: '**/canvas/api/v0/layout/node/2',
      times: 1,
      method: 'POST',
    }).as('updatePreview');

    // Modify the second row's URL.
    typeUrlInRow('@unlimited-link', 1, 'https://second-modified.com');

    cy.wait('@updatePreview');
    cy.findByLabelText('Loading Preview').should('not.exist');

    // Verify both URL values are maintained.
    verifyUrlRowText('@unlimited-link', 0, 'https://modified-url.com');
    verifyUrlRowText('@unlimited-link', 1, 'https://second-modified.com');

    confirmUrlInputs('@unlimited-link', [
      'https://modified-url.com',
      'https://second-modified.com',
    ]);
  });
});
