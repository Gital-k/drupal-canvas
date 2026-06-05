// Repeated stress-test of the 'Date + Time widget' using the current (1.x)
// implementation — realType() + the intercept/blur "fixes" intact.
// Runs the test 30 times to surface the intermittent CI failure reliably.
describe('Date + Time widget — 1.x repeated', () => {
  before(() => {
    cy.drupalCanvasInstall(['sdc_test_all_props', 'datetime_range']);
    cy.drupalLogin('canvasUser', 'canvasUser');
  });

  beforeEach(() => {
    cy.drupalLogin('canvasUser', 'canvasUser');
    cy.loadURLandWaitForCanvasLoaded();
    cy.openLibraryPanel();
    cy.insertComponent({ name: 'Two Column' });
    cy.findByLabelText('Column Width').should('exist');
    cy.insertComponent({ name: 'All props' });
    cy.openLayersPanel();
    cy.clickComponentInLayersView('All props');
    cy.findByLabelText('String — single line').should('exist');
  });

  afterEach(() => {
    cy.drupalRelativeURL('');
  });

  after(() => {
    cy.drupalUninstall();
  });

  const dateSelector =
    '[name$="[test_string_format_date_time][0][value][date]"]';
  const timeSelector =
    '[name$="[test_string_format_date_time][0][value][time]"]';

  const runTest = (label) => {
    it(label, { retries: { openMode: 0, runMode: 0 } }, () => {
      cy.findByText(
        'This is the description of String, format=date-time',
      ).should('exist');

      cy.get(dateSelector).should('have.value', '2016-09-16');
      cy.get(timeSelector).should('have.value', '20:20:39');
      cy.testInIframe('#test-string-format-date-time', (el) =>
        el.scrollIntoView(),
      );
      cy.waitForElementContentInIframe(
        '#test-string-format-date-time',
        '2016-09-16T20:20:39+00:00',
      );

      // ❌ The preview is re-rendered by a debounced PATCH to /layout/node/{id};
      // register the intercept before editing so every preview update the edits
      // trigger is captured.
      cy.intercept('PATCH', '**/canvas/api/v0/layout/node/1').as(
        'previewUpdate',
      );

      cy.get(dateSelector).focus();
      cy.realType('628{uparrow}');

      cy.get(timeSelector).focus();
      cy.realType('72135');

      cy.get(dateSelector).should('have.value', '2017-06-28');
      cy.get(timeSelector).should('have.value', '19:21:35');

      // ❌ Commit the time input's value: a native `<input type="time">` only fires
      // the `change` that drives the preview update on blur/Enter, and the time
      // input is otherwise never blurred here. (The date input commits implicitly
      // when focus moves to the time input above.)
      cy.get(timeSelector).blur();

      // ❌ Synchronize on the actual preview update rather than a timeout: wait
      // until a /layout/node PATCH whose payload carries the new time has
      // *completed* (its response landed), which is what re-renders the preview.
      // Multiple preview PATCHes fire around the edits (one for the date, one for
      // the time); we wait specifically for the one carrying 19:21:35.
      cy.get('@previewUpdate.all').should((updates) => {
        const carriedTime = updates.some((update) => {
          const body =
            typeof update.request.body === 'string'
              ? update.request.body
              : JSON.stringify(update.request.body ?? '');
          const hasTime =
            body.includes('19:21:35') || body.includes('19%3A21%3A35');
          return hasTime && update.response;
        });
        expect(
          carriedTime,
          'a completed preview update carried the new time',
        ).to.equal(true);
      });

      cy.waitForElementContentInIframe(
        '#test-string-format-date-time',
        '2017-06-28T19:21:35.000Z',
      );
    });
  };

  /* eslint-disable mocha/no-setup-in-describe */
  for (let i = 1; i <= 30; i++) {
    runTest(`Date + Time widget (run ${i} of 30)`);
  }
  /* eslint-enable mocha/no-setup-in-describe */
});
