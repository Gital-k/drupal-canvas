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
    'Preview iframe is sandboxed and renders cross-author imports via blob URLs',
    { retries: { openMode: 0, runMode: 3 } },
    () => {
      cy.drupalLogin('canvasUser', 'canvasUser');
      // `clearAutoSave` (on by default) derives an entity type/id pair from
      // `url`'s path segments assuming a `canvas/editor/{type}/{id}` shape;
      // it doesn't apply to this code-editor URL and its component machine
      // name, and this fixture (freshly installed per test, never edited
      // here) has no auto-save data to clear anyway.
      cy.loadURLandWaitForCanvasLoaded({
        url: 'canvas/code-editor/component/canvas_test_code_components_using_imports',
        clearAutoSave: false,
      });
      // The preview iframe's origin must stay opaque: no 'allow-same-origin',
      // so a compiled component can never read/write the parent's cookies or
      // DOM. @see \Drupal\canvas\Controller\CodeEditorPreviewController
      cy.get('[data-canvas-iframe="canvas-code-editor-preview"]').should(
        'have.attr',
        'sandbox',
        'allow-scripts',
      );
      // The imported components' compiled JS/CSS is delivered via
      // `postMessage()` and imported from same-document blob: URLs (see
      // rewriteComponentImports() in code-editor-preview.js) rather than a
      // same-origin, cookie-authenticated network request, since the opaque
      // origin above can't authenticate one. This renders both imports to
      // confirm that mechanism actually works end to end.
      cy.waitForElementContentInIframe(
        '.no-props-component',
        'No props',
        '[data-canvas-iframe="canvas-code-editor-preview"]',
      );
      cy.waitForElementContentInIframe(
        '.props-component',
        'Component With props, Hello Imported Name, 25 years old.',
        '[data-canvas-iframe="canvas-code-editor-preview"]',
      );
    },
  );
});
