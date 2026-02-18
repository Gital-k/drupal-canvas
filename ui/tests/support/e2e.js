// ***********************************************************
// This example support/e2e.js is processed and
// loaded automatically before your test files.
//
// This is a great place to put global configuration and
// behavior that modifies Cypress.
//
// You can change the location of this file or turn off
// automatically serving support files with the
// 'supportFile' configuration option.
//
// You can read more here:
// https://on.cypress.io/configuration
// ***********************************************************

// Import commands.js using ES2015 syntax:
import '@cypress/skip-test/support.js';
import './commands.js';
import 'cypress-axe';
import 'cypress-real-events';

import installLogsCollector from 'cypress-terminal-report/src/installLogsCollector.js';

// Monkey-patch Cypress.on BEFORE installLogsCollector to filter out ResizeObserver logs
const originalCypressOn = Cypress.on;
Cypress.on = function (event, handler) {
  if (event === 'log:added' || event === 'log:changed') {
    const wrappedHandler = function (attrs, ...args) {
      // Filter out ResizeObserver uncaught exception logs
      if (attrs.message?.includes('ResizeObserver loop')) {
        // Don't call the handler for ResizeObserver logs
        return;
      }
      return handler.call(this, attrs, ...args);
    };
    return originalCypressOn.call(this, event, wrappedHandler);
  }
  return originalCypressOn.call(this, event, handler);
};

installLogsCollector();

// Alternatively you can use CommonJS syntax:
// require('./commands')

Cypress.on('uncaught:exception', (err, runnable) => {
  // This is safe to ignore, and often is with Cypress E2E tests.
  // @see https://github.com/w3c/csswg-drafts/issues/6173
  // @see https://github.com/w3c/csswg-drafts/issues/6185
  if (
    err.message.includes('ResizeObserver loop limit exceeded') ||
    err.message.includes('ResizeObserver loop completed')
  ) {
    return false;
  }
});

Cypress.on('test:after:run', (test) => {
  if (test.state === 'failed') {
    const { retries, currentRetry } = test;
    const retriesRemaining = retries - currentRetry;
    if (retriesRemaining > 0) {
      const prefix = Cypress.env('drupalDbPrefix');
      if (prefix) {
        cy.drupalUninstall();
        cy.drupalCanvasInstall();
        cy.drupalLogin('canvasUser', 'canvasUser');
      }
    }
  }
});
