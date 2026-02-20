/**
 * @file
 * Replaces default tabledrag handle icons with custom SVG icons.
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Custom SVG icon for drag handle (matches Radix UI DragHandleDots2Icon).
   */
  const dragHandleSvg = `<svg width="15" height="15" viewBox="0 0 15 15" fill="none" xmlns="http://www.w3.org/2000/svg" class="drag-handle-icon" aria-hidden="true">
    <path d="M5.5 4.625C6.12132 4.625 6.625 4.12132 6.625 3.5C6.625 2.87868 6.12132 2.375 5.5 2.375C4.87868 2.375 4.375 2.87868 4.375 3.5C4.375 4.12132 4.87868 4.625 5.5 4.625ZM9.5 4.625C10.1213 4.625 10.625 4.12132 10.625 3.5C10.625 2.87868 10.1213 2.375 9.5 2.375C8.87868 2.375 8.375 2.87868 8.375 3.5C8.375 4.12132 8.87868 4.625 9.5 4.625ZM6.625 7.5C6.625 8.12132 6.12132 8.625 5.5 8.625C4.87868 8.625 4.375 8.12132 4.375 7.5C4.375 6.87868 4.87868 6.375 5.5 6.375C6.12132 6.375 6.625 6.87868 6.625 7.5ZM9.5 8.625C10.1213 8.625 10.625 8.12132 10.625 7.5C10.625 6.87868 10.1213 6.375 9.5 6.375C8.87868 6.375 8.375 6.87868 8.375 7.5C8.375 8.12132 8.87868 8.625 9.5 8.625ZM6.625 11.5C6.625 12.1213 6.12132 12.625 5.5 12.625C4.87868 12.625 4.375 12.1213 4.375 11.5C4.375 10.8787 4.87868 10.375 5.5 10.375C6.12132 10.375 6.625 10.8787 6.625 11.5ZM9.5 12.625C10.1213 12.625 10.625 12.1213 10.625 11.5C10.625 10.8787 10.1213 10.375 9.5 10.375C8.87868 10.375 8.375 10.8787 8.375 11.5C8.375 12.1213 8.87868 12.625 9.5 12.625Z" fill="currentColor" fill-rule="evenodd" clip-rule="evenodd"></path>
  </svg>`;


  /**
   * Behavior to replace tabledrag handle icons with custom SVGs.
   */
  Drupal.behaviors.canvasTableDragIcons = {
    attach: function (context, settings) { // eslint-disable-line no-unused-vars
      // Find all tabledrag handles within multivalue containers and replace their content.
      // Use 'once' to ensure we only process each element once, even after AJAX updates.
      const handles = once('canvas-drag-handle', '.multivalue-container .tabledrag-handle', context);
      handles.forEach(function (handle) {
        // Replace the inner content (the .handle div) with our custom SVG
        handle.innerHTML = dragHandleSvg;
        // Add ARIA label for accessibility
        handle.setAttribute('aria-label', Drupal.t('Drag to reorder'));
      });

      // Find all remove action cells and hide them. The original Drupal remove
      // button is kept in the DOM (hidden) so the popover's Remove button in
      // DrupalInputMultivalueForm can trigger it directly via AJAX.
      const removeActions = once('canvas-remove-action', '.multivalue-container .canvas-remove-action', context);
      removeActions.forEach(function (cell) {
        // Find the original remove button - check multiple selectors for compatibility
        const removeButton = cell.querySelector(
          'input[type="submit"][name*="remove"], ' +
          'button[name*="remove"], ' +
          '.remove-button, ' +
          '[data-drupal-selector*="remove-button"]'
        );

        if (!removeButton) {
          return;
        }

        // Hide the original button but keep it in DOM for AJAX functionality.
        removeButton.style.display = 'none';
        // Hide the entire cell so no empty column is shown; the popover's
        // Remove button is the only UI for removal.
        cell.style.display = 'none';
      });
    },

    detach: function (context, settings, trigger) {
      // No custom event handlers to clean up — the popover's Remove button
      // triggers the hidden Drupal remove button directly.
    }
  };

})(Drupal, once);

