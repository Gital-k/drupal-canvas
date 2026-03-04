const testCanvasPagesCanBeCreatedInMultilingualSites = (
  cy,
  title,
  expectedUrlAlias,
  expectedPathPrefix,
  expectedMediaAlt
) => {
  cy.visit(`/es/canvas`);

  cy.findByTestId('canvas-primary-panel').should('exist');
  cy.findByTestId('canvas-navigation-button').click();
  cy.findByTestId('canvas-navigation-new-button').click();
  cy.findByTestId('canvas-navigation-new-page-button').click();

  cy.url().should('contain', '/canvas/editor/canvas_page/');
  cy.findByTestId('canvas-editor-frame').should('exist');

  cy.get('#edit-title-0-value').clear();
  cy.get('#edit-title-0-value').type(title);

  cy.get('#edit-path-0-alias').should('have.value', expectedUrlAlias);

  cy.openLibraryPanel();
  cy.insertComponent({
    name: 'Canvas test SDC with optional image, without example',
  });

  cy.waitForElementNotInIframe('.layout-content img');
  cy.get(
    '[class*="contextualPanel"] .js-media-library-open-button[data-once="drupal-ajax"]',
  )
    .first()
    .click();
  cy.get('div[role="dialog"]').should('exist');
  // Media library shows the checkbox label using untranslated media,
  // but displays the thumbnail using the translated media.
  cy.findByLabelText('Select Example').check();
  cy.get('button:contains("Insert selected")').click();
  cy.get('div[role="dialog"]').should('not.exist');
  cy.waitForElementInIframe('img[alt="Spanish Example"]');

  cy.findByTestId('canvas-publish-review').click();
  cy.findByTestId('canvas-publish-review-select-all').click();
  cy.findByText('Publish 1 selected').click();

  cy.findByText('All changes published!').should('exist');
  cy.findByText('Errors').should('not.exist');

  cy.visit(`/es/admin/content/pages`);
  cy.findByText(title).click();
  cy.get('body').should('contain.text', title);
  cy.get('img[alt="' + expectedMediaAlt + '"]').should('exist')
  cy.url().should(
    'eq',
    `${Cypress.config().baseUrl}${expectedPathPrefix}${expectedUrlAlias}`,
  );
};

describe('Canvas pages can be created in any language', () => {
  before(() => {
    cy.drupalCanvasInstall([
      'canvas_test_sdc',
      'canvas_test_e2e_code_components',
    ]);
    cy.drupalRecipeApply('../tests/fixtures/recipes/language/');
  });

  beforeEach(() => {
    cy.drupalLogin('canvasUser', 'canvasUser');
  });

  it('Using default language', () => {
    testCanvasPagesCanBeCreatedInMultilingualSites(
      cy,
      'Page created from ES language - Default',
      '/page-created-from-es-language-default',
      '',
      'Example',
    );
  });

  it('Using interface language', () => {
    cy.drupalRecipeApply(
      '../tests/fixtures/recipes/language/interface_negotiation',
    );
    testCanvasPagesCanBeCreatedInMultilingualSites(
      cy,
      'Page created from ES language - Interface',
      '/page-created-from-es-language-interface',
      '/es',
      'Spanish Example',
    );
  });

  after(() => {
    cy.drupalUninstall();
  });
});
