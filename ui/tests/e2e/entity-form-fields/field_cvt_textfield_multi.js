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
    cy.findByLabelText(`Canvas Unlimited Text (value ${ix + 2})`).type(item);
    cy.findByLabelText(`Canvas Unlimited Text (value ${ix + 2})`).should(
      'have.value',
      item,
    );
    // Wait for the preview to finish loading.
    cy.wait('@updatePreview');

    // @todo this wait is a temp fix. We shouldn't merge this, but instead
    // find the underlying cause.
    cy.log(`Setting up monitoring for iteration ${ix + 1}`);

    // Set up monitoring BEFORE the wait
    // cy.window().then((win) => {
    //   const startTime = Date.now();
    //   const originalFetch = win.fetch;
    //   const originalXHROpen = win.XMLHttpRequest.prototype.open;
    //
    //   console.log(`[Iteration ${ix + 1}] Starting 2ms wait at ${startTime}`);
    //
    //   // Track fetch requests
    //   win.fetch = function(...args) {
    //     const elapsed = Date.now() - startTime;
    //     const url = args[0]?.url || args[0];
    //     console.log(`[Iteration ${ix + 1}] [${elapsed}ms] FETCH:`, url, args[1]?.method || 'GET');
    //     return originalFetch.apply(this, args);
    //   };
    //
    //   // Track XHR requests
    //   win.XMLHttpRequest.prototype.open = function(method, url) {
    //     const elapsed = Date.now() - startTime;
    //     console.log(`[Iteration ${ix + 1}] [${elapsed}ms] XHR:`, method, url);
    //     return originalXHROpen.apply(this, arguments);
    //   };
    //
    //   // Sample state periodically during the wait
    //   let intervalCount = 0;
    //   const checkInterval = setInterval(() => {
    //     intervalCount++;
    //     const elapsed = Date.now() - startTime;
    //
    //     console.log(`[Iteration ${ix + 1}] [${elapsed}ms] Interval check #${intervalCount}`);
    //
    //     // Check postPreview queue
    //     const queueLength = win.__postPreviewQueue ? win.__postPreviewQueue.length : 'undefined';
    //     console.log(`[Iteration ${ix + 1}] [${elapsed}ms] postPreviewQueue:`, queueLength);
    //
    //     // Check if AJAX is running
    //     const ajaxActive = win.jQuery ? win.jQuery.active : 'no jQuery';
    //     console.log(`[Iteration ${ix + 1}] [${elapsed}ms] jQuery.active:`, ajaxActive);
    //
    //     // Check Redux store
    //     if (win.reduxStore) {
    //       const state = win.reduxStore.getState();
    //       const buildId = state.formState?.pageData?.form_build_id;
    //       console.log(`[Iteration ${ix + 1}] [${elapsed}ms] form_build_id:`, buildId);
    //     } else {
    //       console.log(`[Iteration ${ix + 1}] [${elapsed}ms] No reduxStore available`);
    //     }
    //   }, 500); // Check every 500ms
    //
    //   // Store cleanup function
    //   win.__cleanupMonitoring = () => {
    //     clearInterval(checkInterval);
    //     win.fetch = originalFetch;
    //     win.XMLHttpRequest.prototype.open = originalXHROpen;
    //     const totalElapsed = Date.now() - startTime;
    //     console.log(`[Iteration ${ix + 1}] Completed 2ms wait after ${totalElapsed}ms (${intervalCount} interval checks)`);
    //   };
    // });

    // Now do the actual wait
    cy.wait(25); // eslint-disable-line cypress/no-unnecessary-waiting

    // Clean up monitoring
    // cy.window().then((win) => {
    //   if (win.__cleanupMonitoring) {
    //     win.__cleanupMonitoring();
    //     delete win.__cleanupMonitoring;
    //   }
    // });

    // Queue another intercept for the wait in the main test and/or the next
    // iteration in the loop.
    cy.intercept({
      url: '**/canvas/api/v0/layout/node/2',
      times: 1,
      method: 'POST',
    }).as('updatePreview');

    // cy.waitForAjax().then(() => {
    //   console.log(`[Iteration ${ix + 1}] waitForAjax completed`);
    // });

    cy.log(`adding item ${ix + 1}`);

    cy.get('@add-another-text')
      .click();

    cy.waitForAjax().then(() => {
      console.log(`[Iteration ${ix + 1}] waitForAjax after click completed`);
    });

    cy.selectorShouldHaveUpdatedFormBuildId(
      '[data-testid="canvas-page-data-form"]',
      15000,
    ).then(() => {
      console.log(`[Iteration ${ix + 1}] Form build ID updated`);
    });
    // @todo this wait is a temp fix. We shouldn't merge this, but instead
    // find the underlying cause.
    // cy.wait(4000); // eslint-disable-line cypress/no-unnecessary-waiting
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
