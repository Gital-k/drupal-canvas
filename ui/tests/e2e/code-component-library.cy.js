describe('Code component library', () => {
  const testCodeComponentSelector =
    '[data-testid="canvas-primary-panel"] [data-canvas-component-id="js.test-code-component"]';

  const openTestCodeComponentMenu = () => {
    cy.get(testCodeComponentSelector).within(() => {
      // Force because the dots button has 0 height and width until hover.
      cy.findAllByLabelText('Open contextual menu').first().click({ force: true });
    });
  };

  const openTestCodeComponentMenuInCodeEditor = () => {
    cy.findByTestId('canvas-primary-panel').within(() => {
      cy.findByText('Test Code Component')
        .parents('[role="listitem"]')
        .first()
        .within(() => {
          cy.findAllByLabelText('Open contextual menu')
            .first()
            .click({ force: true });
        });
    });
  };

  const assertInLayoutDialog = () => {
    cy.findByRole('dialog').within(() => {
      cy.contains('Component in use').should('be.visible');
      cy.contains(
        'Please remove all instances of the component on the page before removing or deleting.',
      ).should('be.visible');
      cy.contains('You can re-add it to Components').should('not.exist');
    });
  };

  before(() => {
    cy.drupalCanvasInstall();
  });

  beforeEach(() => {
    cy.drupalLogin('canvasUser', 'canvasUser');
  });

  after(() => {
    cy.drupalUninstall();
  });

  it('shows the in-layout dialog when removing a used code component from the library', () => {
    cy.loadURLandWaitForCanvasLoaded();

    cy.openLibraryPanel();

    openTestCodeComponentMenu();

    cy.findByRole('menuitem', { name: 'Remove from components' }).click({
      force: true,
    });

    assertInLayoutDialog();
  });

  it('shows the in-layout dialog when removing a used code component from the code editor', () => {
    cy.loadURLandWaitForCanvasLoaded();
    cy.openLibraryPanel();

    openTestCodeComponentMenu();

    cy.findByRole('menuitem', { name: 'Edit code' }).click({
      force: true,
    });

    cy.url().should('include', '/code-editor/component/test-code-component');
    cy.findByTestId('canvas-code-editor-container').should('exist');
    cy.findByTestId('canvas-primary-panel').should('exist');

    openTestCodeComponentMenuInCodeEditor();

    cy.findByRole('menuitem', { name: 'Remove from components' }).click({
      force: true,
    });

    assertInLayoutDialog();
  });
});
