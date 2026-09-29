/**
 * @file
 * Inserts a "Tour" trigger into the Canvas editor's topbar and wires it to
 * core Tour's own toggle.
 *
 * Core Tour's own trigger is rendered by the Toolbar module (see
 * tour.module's hook_toolbar()), which Canvas's editor shell never builds —
 * there is no toolbar region in this React-rendered page at all. This
 * behavior inserts an equivalent trigger directly into the Canvas topbar
 * instead, and calls `Drupal.tour.setActive()` — core Tour's own public
 * toggle, already initialized by tour.js from the `_tour_internal`
 * drupalSettings that \Drupal\canvas_tour\EventSubscriber\TourSubscriber
 * attaches — so the tour becomes reachable without duplicating any of
 * Tour's own step-building logic.
 *
 * @see \Drupal\canvas_tour\EventSubscriber\TourSubscriber
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.canvasTourBridge = {
    attach: function (context) {
      once('canvas-tour-bridge', 'body', context).forEach(function () {
        pollUntilFound(insertTourTrigger, 30000);
      });
    },
  };

  /**
   * Repeatedly attempts `check()` (a function returning true once it has
   * done its job) until it succeeds, retrying on every DOM mutation. The
   * Canvas topbar is an asynchronous React render this behavior can't
   * simply wait on. Gives up after `timeoutMs` so the MutationObserver
   * doesn't outlive a page whose target never appears (e.g. a future
   * Canvas UI change).
   */
  function pollUntilFound(check, timeoutMs) {
    if (check()) {
      return;
    }
    var observer = new MutationObserver(function () {
      if (check()) {
        observer.disconnect();
      }
    });
    observer.observe(document.body, { childList: true, subtree: true });
    setTimeout(function () {
      observer.disconnect();
    }, timeoutMs);
  }

  /**
   * Inserts the "Tour" trigger button next to Redo in the Canvas topbar.
   *
   * Calls Tour's own public API directly rather than relying on Tour's own
   * click-wiring, which only scans the DOM once at page load and would miss
   * a button added later by this behavior.
   */
  function insertTourTrigger() {
    var redo = document.querySelector('[data-testid="canvas-topbar"] [aria-label="Redo"]');
    if (!redo || document.querySelector('.js-canvas-tour-start-button')) {
      return !!redo;
    }
    // Reuse the shared sizing/spacing class the other topbar buttons share
    // (a CSS-module-hashed class, e.g. "_topBarButton_l21s9_30" — brittle
    // across Canvas UI rebuilds, but there's no stable equivalent exposed),
    // paired with Radix Themes' own utility classes for a real button look
    // (matching how "Preview" is styled) rather than plain browser chrome.
    var sharedClassMatch = redo.className.match(/_topBarButton\S+/);
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'rt-reset rt-BaseButton rt-r-size-1 rt-variant-soft rt-Button js-canvas-tour-start-button'
      + (sharedClassMatch ? ' ' + sharedClassMatch[0] : '');
    button.setAttribute('data-accent-color', 'blue');
    button.setAttribute('aria-label', Drupal.t('Tour'));
    button.textContent = Drupal.t('Tour');
    button.addEventListener('click', function () {
      if (window.Drupal && Drupal.tour && typeof Drupal.tour.setActive === 'function') {
        Drupal.tour.setActive(true);
      }
    });
    // Give the trigger a bit of breathing room from Undo/Redo, matching the
    // existing divider Canvas itself uses between the exit button and
    // Undo/Redo, rather than a hardcoded margin.
    var existingDivider = document.querySelector('[data-testid="canvas-topbar"] [class*="_verticalDivider_"]');
    if (existingDivider) {
      var divider = existingDivider.cloneNode(false);
      redo.insertAdjacentElement('afterend', divider);
      divider.insertAdjacentElement('afterend', button);
    }
    else {
      button.style.marginLeft = '8px';
      redo.insertAdjacentElement('afterend', button);
    }
    return true;
  }

})(Drupal, once);
