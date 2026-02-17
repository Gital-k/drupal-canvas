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

installLogsCollector();

// Global pre-retry cleanup for better test reliability
Cypress.on('test:before:run', (test, runnable) => {
  // Only run cleanup on retry attempts (not first run)
  if (Cypress.currentRetry > 0) {
    console.log(
      `🔄 RETRY ATTEMPT ${Cypress.currentRetry} - Performing Drupal cleanup for test: ${test.title}`,
    );

    // Check if we have a Drupal installation to clean up
    const prefix = Cypress.env('drupalDbPrefix');

    if (prefix && prefix.length) {
      try {
        // Force uninstall any existing Drupal installation
        cy.drupalUninstall();
        console.log(`✅ Pre-retry cleanup completed successfully`);

        // Small wait to ensure cleanup completes before proceeding
        cy.wait(500);
      } catch (error) {
        // Log the failure but don't block the retry
        console.error(`⚠️ Pre-retry cleanup failed: ${error.message}`);
      }
    } else {
      console.log(
        `ℹ️ No Drupal installation found to clean up (no prefix set)`,
      );
    }
  }
});

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
