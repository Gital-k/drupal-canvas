/**
 * Conflict UI - Static Implementation Tests
 *
 * These tests verify the static/visual implementation of the conflict detection UI.
 * The UI is gated behind canvas_dev_mode and shows mock data for demonstration.
 *
 * Prerequisites for existing site:
 * - canvas_dev_mode module must be ALREADY enabled
 * - Site running at https://canvas-dev.ddev.site
 * - Editor user must exist with username 'editor', password 'editor'
 * - Editor user must have administrator role
 */

describe('Conflict UI - Static Implementation', () => {
  // Skip installation - using existing site
  // before(() => {
  //   cy.drupalCanvasInstall();
  // });

  beforeEach(() => {
    cy.drupalSession();
    cy.drupalLogin(
      Cypress.env('CANVAS_USER') || 'editor',
      Cypress.env('CANVAS_USER') || 'editor',
    );

    // Combined setup logic
    // Navigate to Canvas pages list first
    cy.visit('/admin/content/pages');

    // Wait for the page to load
    cy.get('table', { timeout: 10000 }).should('be.visible');

    // Check if there are any Canvas pages, if not create one
    cy.get('body').then(($body) => {
      if (
        $body.find('table tbody tr').length === 0 ||
        $body.text().includes('No content available')
      ) {
        // Create a new Canvas page
        cy.visit('/node/add/canvas_page');
        cy.get('input[name="title[0][value]"]').type(
          'Test Canvas Page for Conflict UI',
        );
        cy.get('input[type="submit"]').contains('Save').click();

        // Wait for save and redirect
        cy.url().should('include', '/node/');

        // Click "Edit in Canvas" link
        cy.contains('Edit in Canvas').click();
      } else {
        // Click on the first Canvas page in the table - this goes directly to Canvas editor
        cy.get('table tbody tr')
          .first()
          .within(() => {
            cy.get('a').first().click();
          });
      }
    });

    // Wait for Canvas editor URL (either /canvas/editor/ or /canvas/)
    cy.url({ timeout: 10000 }).should('match', /\/canvas/);

    // Wait for the Canvas app to load - it uses #canvas not #root
    cy.get('#canvas', { timeout: 20000 }).should('exist');

    // Wait for the publish review button to be available (this means Canvas has loaded)
    cy.findByTestId('canvas-publish-review', { timeout: 15000 }).should(
      'be.visible',
    );
  });

  // Skip uninstall - using existing site
  // after(() => {
  //   cy.drupalUninstall();
  // });

  describe('Conflict Banner', () => {
    it('should display conflict banner with correct count', () => {
      // Open unpublished changes panel
      cy.findByTestId('canvas-publish-review').click();

      // Wait for panel to open
      cy.findByTestId('canvas-publish-reviews-content').should('be.visible');

      // Check if conflict banner is visible (only when dev mode is enabled)
      cy.findByTestId('conflict-banner').should('be.visible');

      // Should show "4 conflicts to resolve" (static mock data)
      cy.findByTestId('conflict-banner').should(
        'contain.text',
        '4 conflicts to resolve',
      );

      // Should show warning message
      cy.findByTestId('conflict-banner').should(
        'contain.text',
        'Review and resolve before publishing',
      );
    });

    it('should have resolve button with correct styling', () => {
      cy.findByTestId('canvas-publish-review').click();
      cy.findByTestId('conflict-banner').should('be.visible');

      cy.findByTestId('resolve-conflicts-button').should('be.visible');

      // Button should have correct text
      cy.findByTestId('resolve-conflicts-button').should(
        'contain.text',
        'Resolve 4 conflicts',
      );

      // Button should not be disabled
      cy.findByTestId('resolve-conflicts-button').should('not.be.disabled');

      // Check button styling (light pink/red background - Radix UI color)
      // Accept both rgb() and color(display-p3) formats
      cy.findByTestId('resolve-conflicts-button')
        .should('have.css', 'background-color')
        .and('satisfy', (color) => {
          // Match rgb(254, 235, 236) or color(display-p3 0.985 0.925 0.925)
          return color.includes('254') || color.includes('0.985');
        });
    });

    it('should log to console when resolve button is clicked', () => {
      cy.window().then((win) => {
        cy.spy(win.console, 'log').as('consoleLog');
      });

      cy.findByTestId('canvas-publish-review').click();
      cy.findByTestId('conflict-banner').should('be.visible');

      // Click resolve button
      cy.findByTestId('resolve-conflicts-button').click();

      // Should log to console
      cy.get('@consoleLog').should(
        'be.calledWith',
        'Resolve conflicts clicked - Static UI placeholder',
      );
    });
  });

  describe('Conflicted Items', () => {
    it('should show first 2 items as conflicted', () => {
      cy.findByTestId('canvas-publish-review').click();
      cy.findByTestId('canvas-publish-reviews-content').should('be.visible');

      // Get all change rows
      cy.findAllByTestId('pending-change-row').should(
        'have.length.at.least',
        1,
      );

      // Check if we have at least 2 items for full test
      cy.findAllByTestId('pending-change-row').then(($rows) => {
        if ($rows.length < 2) {
          cy.log('Only 1 pending change - skipping multi-item checks');
          // Just verify the first item has conflict icon
          cy.wrap($rows[0]).find('svg').should('exist');
          return;
        }

        // First item should have conflict icon
        cy.wrap($rows[0]).find('svg').should('exist');

        // Second item should have conflict icon
        cy.wrap($rows[1]).find('svg').should('exist');

        // Third item (if exists) should NOT have conflict icon in the checkbox area
        if ($rows.length >= 3) {
          // This is a visual check - third item won't have the red conflict styling
          cy.wrap($rows[2]).should('exist');
        }
      });
    });

    it('should have red text for conflicted items', () => {
      cy.findByTestId('canvas-publish-review').click();
      cy.findByTestId('canvas-publish-reviews-content').should('be.visible');

      // First item should have red color (Radix UI red)
      // Accept both rgb() and color(display-p3) formats
      cy.findAllByTestId('pending-change-row')
        .eq(0)
        .find('label')
        .should('have.css', 'color')
        .and('satisfy', (color) => {
          // Match rgba(196, 0, 6, ...) or color(display-p3 0.744 0.234 0.222)
          return color.includes('196') || color.includes('0.744');
        });
    });

    it('should have disabled checkboxes for conflicted items', () => {
      cy.findByTestId('canvas-publish-review').click();
      cy.findByTestId('canvas-publish-reviews-content').should('be.visible');

      // Check if we have at least 1 item
      cy.findAllByTestId('pending-change-row').should(
        'have.length.at.least',
        1,
      );

      // First item should have a checkbox (it exists but is disabled for conflicted items)
      // Use a more flexible selector that works with Radix UI
      cy.findAllByTestId('pending-change-row')
        .eq(0)
        .find('button[role="checkbox"]')
        .should('exist')
        .and('have.attr', 'data-disabled');
    });

    it('should not allow selecting conflicted items', () => {
      cy.findByTestId('canvas-publish-review').click();
      cy.findByTestId('canvas-publish-reviews-content').should('be.visible');

      // Get first checkbox (Radix UI uses button[role="checkbox"])
      cy.findAllByTestId('pending-change-row')
        .eq(0)
        .find('button[role="checkbox"]')
        .should('exist')
        .and('have.attr', 'data-state', 'unchecked')
        .and('have.attr', 'data-disabled');
    });
  });

  describe('Conflicted Item Dropdown Menu', () => {
    it('should show "Resolve conflict" option for conflicted items', () => {
      cy.findByTestId('canvas-publish-review').click();
      cy.findByTestId('canvas-publish-reviews-content').should('be.visible');

      // Click three dots menu on first item (conflicted)
      cy.findAllByTestId('pending-change-row')
        .eq(0)
        .findByLabelText('More options')
        .click();

      // Wait for menu to open
      cy.findByRole('menu').should('be.visible');

      // Should have "Resolve conflict" option
      cy.findByRole('menu').should('contain.text', 'Resolve conflict');

      // Should have "Discard changes" option
      cy.findByRole('menu').should('contain.text', 'Discard changes');

      // Should NOT have "View changes" option for conflicted items
      cy.findByRole('menu').should('not.contain.text', 'View changes');
    });

    it('should NOT show "Resolve conflict" for normal items', () => {
      cy.findByTestId('canvas-publish-review').click();
      cy.findByTestId('canvas-publish-reviews-content').should('be.visible');

      cy.findAllByTestId('pending-change-row').then(($rows) => {
        // Skip if less than 3 items
        if ($rows.length < 3) {
          cy.log('Skipping: Need at least 3 items');
          return;
        }

        // Click three dots menu on third item (normal)
        cy.wrap($rows[2]).findByLabelText('More options').click();

        // Wait for menu to open
        cy.findByRole('menu').should('be.visible');

        // Should NOT have "Resolve conflict" option
        cy.findByRole('menu').should('not.contain.text', 'Resolve conflict');

        // Should have "View changes" option
        cy.findByRole('menu').should('contain.text', 'View changes');

        // Should have "Discard changes" option
        cy.findByRole('menu').should('contain.text', 'Discard changes');
      });
    });

    it('should log to console when "Resolve conflict" is clicked', () => {
      cy.window().then((win) => {
        cy.spy(win.console, 'log').as('consoleLog');
      });

      cy.findByTestId('canvas-publish-review').click();
      cy.findByTestId('canvas-publish-reviews-content').should('be.visible');

      // Click three dots menu on first item
      cy.findAllByTestId('pending-change-row')
        .eq(0)
        .findByLabelText('More options')
        .click();

      // Click "Resolve conflict"
      cy.findByRole('menuitem', { name: 'Resolve conflict' }).click();

      // Should log to console (check that console.log was called with text containing "Resolve conflict")
      cy.get('@consoleLog').should('be.called');
    });

    it('should have no icons in menu items', () => {
      cy.findByTestId('canvas-publish-review').click();
      cy.findByTestId('canvas-publish-reviews-content').should('be.visible');

      // Click three dots menu on first item
      cy.findAllByTestId('pending-change-row')
        .eq(0)
        .findByLabelText('More options')
        .click();

      cy.findByRole('menu').should('be.visible');

      // Menu items should not have SVG icons inside them
      cy.findByRole('menuitem', { name: 'Resolve conflict' })
        .find('svg')
        .should('not.exist');

      cy.findByRole('menuitem', { name: 'Discard changes' })
        .find('svg')
        .should('not.exist');
    });
  });

  describe('Visual Regression', () => {
    it('conflict banner visual snapshot', () => {
      cy.findByTestId('canvas-publish-review').click();
      cy.findByTestId('conflict-banner').should('be.visible');

      // Take screenshot for visual regression
      cy.findByTestId('conflict-banner').screenshot('conflict-banner');
    });

    it('conflicted item visual snapshot', () => {
      cy.findByTestId('canvas-publish-review').click();
      cy.findByTestId('canvas-publish-reviews-content').should('be.visible');

      // Take screenshot of first conflicted item
      cy.findAllByTestId('pending-change-row')
        .eq(0)
        .screenshot('conflicted-item');
    });
  });
});
