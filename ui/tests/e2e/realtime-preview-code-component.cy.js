// @cspell:ignore Meatspace
describe('⚡️ Real time code component previews', () => {
  beforeEach(() => {
    cy.drupalCanvasInstall(['canvas_test_code_components']);
    cy.drupalSession();
    // A larger viewport makes it easier to debug in the test runner app.
    cy.viewport(2000, 1000);
  });

  afterEach(() => {
    cy.drupalUninstall();
  });

  it(
    'Scalar code component props update the preview in real time',
    { retries: { openMode: 0, runMode: 3 } },
    () => {
      cy.drupalLogin('canvasUser', 'canvasUser');
      cy.loadURLandWaitForCanvasLoaded({ url: 'canvas/editor/node/2' });
      cy.openLibraryPanel();
      // Wait for the component list to load.
      cy.get('.primaryPanelContent').should('contain.text', 'Components');
      // Add the 'With props' code component.
      cy.insertComponent({ name: 'With props' });
      // Check the default values display in the preview.
      cy.waitForElementContentInIframe(
        'div',
        'Component With props, Hello Canvas, 40 years old.',
      );
      cy.get('[data-testid*="canvas-component-form-"]').as('inputForm');
      let previewHasUpdated = false;
      // Catch the PATCH request to the API Layout controller.
      cy.intercept(
        {
          method: 'PATCH',
          url: '**/canvas/api/v0/layout/node/2',
          // Should only happen once, polled until the user-entry has settled
          times: 1,
        },
        () => {
          // This should only fire after the preview has been updated.
          expect(previewHasUpdated).to.equal(true);
        },
      ).as('patch');
      // Clear and update the value of the name prop.
      cy.get('@inputForm').findByLabelText('name').clear();
      cy.get('@inputForm')
        .findByLabelText('name')
        .type('A Laughing Death in Meatspace');
      // The value should update in the preview in real-time before the PATCH
      // request has fired or completed.
      cy.waitForElementContentInIframe(
        'div',
        'Component With props, Hello A Laughing Death in Meatspace, 40 years old.',
      );
      previewHasUpdated = true;
      cy.wait('@patch').then(() => {
        // This should only fire after the preview has been updated.
        expect(previewHasUpdated).to.equal(true);
      });
    },
  );

  it(
    'Preview iframe stays sandboxed for a component with cross-author imports',
    { retries: { openMode: 0, runMode: 3 } },
    () => {
      cy.drupalLogin('canvasUser', 'canvasUser');
      // Not `loadURLandWaitForCanvasLoaded()`: it always finishes with
      // `previewReady()`, which waits for the *main content/layout* preview
      // iframe (`data-test-canvas-content-initialized`) — this code-editor
      // page has no such iframe, only Preview.tsx's own dedicated one below.
      // It also derives a `clearAutoSave` entity type/id pair from `url`'s
      // path segments assuming a `canvas/editor/{type}/{id}` shape, which
      // doesn't apply here either; this fixture is freshly installed per
      // test and never edited, so there's no auto-save data to clear anyway.
      cy.visit(
        'canvas/code-editor/component/canvas_test_code_components_using_imports',
      );
      // The preview iframe's origin must stay opaque: no 'allow-same-origin',
      // so a compiled component can never read/write the parent's cookies or
      // DOM. @see \Drupal\canvas\Controller\CodeEditorPreviewController
      cy.get('[data-canvas-iframe="canvas-code-editor-preview"]').should(
        'have.attr',
        'sandbox',
        'allow-scripts',
      );
    },
  );
});
