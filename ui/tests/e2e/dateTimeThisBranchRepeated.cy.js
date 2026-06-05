// Repeated stress-test of the 'Date + Time widget' using the proposed fix:
// realType() replaced with cy.type(), and the intercept/blur "fixes" removed.
// Runs the test 30 times to confirm the fix eliminates the intermittent failure.
describe('Date + Time widget — this branch repeated', () => {
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

      cy.get(dateSelector).focus();
      cy.get(dateSelector).clear();
      cy.get(dateSelector).type('2017-06-28');

      cy.get(timeSelector).focus();
      cy.get(timeSelector).clear();
      cy.get(timeSelector).type('19:21:35');

      cy.get(dateSelector).should('have.value', '2017-06-28');
      cy.get(timeSelector).should('have.value', '19:21:35');

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
