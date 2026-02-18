const items = [
  'The Music Tapes',
  'Neutral Milk Hotel',
  'of Montreal',
  'The Olivia Tremor Control',
];
export const edit = (cy) => {
  cy.findByRole('heading', { name: 'Canvas Unlimited Text' })
    .parents('.js-form-wrapper')
    .as('textfield_multi');
  cy.get('@textfield_multi')
    .findByRole('button', { name: 'Add another item' })
    .as('add-another-text');
  cy.findByLabelText('Canvas Unlimited Text (value 1)').should(
    'have.value',
    'Marshmallow Coast',
  );
  items.forEach((item, ix) => {
    cy.findByLabelText(`Canvas Unlimited Text (value ${ix + 2})`, {
      timeout: 10000,
    }).type(item);
    cy.findByLabelText(`Canvas Unlimited Text (value ${ix + 2})`).should(
      'have.value',
      item,
    );
    // Wait for the preview to finish loading.
    // cy.wait('@updatePreview');
    cy.reasonableWait();
    // Queue another intercept for the wait in the main test and/or the next
    // iteration in the loop.
    cy.intercept({
      url: '**/canvas/api/v0/layout/node/2',
      times: 1,
      method: 'POST',
    }).as('updatePreview');

    cy.log(`adding item ${ix + 1}`);

    cy.get('@add-another-text').click();
    cy.wait('@updatePreview');
    cy.reasonableWait();

    // cy.selectorShouldHaveUpdatedFormBuildId(
    //   '[data-testid="canvas-page-data-form"]',
    //   15000,
    // ).then(() => {
    //   console.log(`[Iteration ${ix + 1}] Form build ID updated`);
    // });
  });
};
export const assertData = (response) => {
  // Add the default field value.
  // @see \canvas_test_article_fields_install().
  expect(response.attributes.field_cvt_unlimited_text).to.deep.eq([
    'Marshmallow Coast',
    ...items,
  ]);
};
