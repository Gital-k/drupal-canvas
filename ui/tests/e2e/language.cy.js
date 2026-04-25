const testCanvasPagesCanBeCreatedInMultilingualSites = (
  cy,
  title,
  expectedUrlAlias,
  expectedPathPrefix,
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

  cy.findByTestId('canvas-publish-review').click();
  cy.findByTestId('canvas-publish-review-select-all').click();
  cy.findByText('Publish 1 selected').click();

  cy.findByText('All changes published!').should('exist');
  cy.findByText('Errors').should('not.exist');

  cy.visit(`/es/admin/content/pages`);
  cy.findByText(title).click();
  cy.get('body').should('contain.text', title);
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
    );
  });

  after(() => {
    cy.drupalUninstall();
  });
});
