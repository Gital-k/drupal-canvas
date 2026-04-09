/**
 * Utility functions for multivalue form components.
 */

/**
 * Determine if the remove button should be enabled for a multivalue field item.
 *
 * This function checks:
 * 1. Whether a Drupal remove button exists and is enabled
 * 2. If the field is required and has only one item (prevents removal)
 *
 * @param triggerElement - The DOM element that triggers the popover (should be inside a table row)
 * @returns boolean - true if the remove button should be enabled, false otherwise
 */
export const isRemoveButtonEnabled = (
  triggerElement: HTMLElement | null,
): boolean => {
  if (!triggerElement) return false;

  // Check whether the table row has a Drupal remove button.
  const tableRow = triggerElement.closest('tr');
  const removeActionCell = tableRow?.querySelector(
    '[data-canvas-remove-button]',
  );

  // Look for the Drupal remove button. Drupal adds these buttons to all rows
  // in unlimited cardinality fields.
  const removeButton = removeActionCell?.querySelector(
    'input[type="submit"]',
  ) as HTMLInputElement | null;

  // Check if button exists and is not disabled
  if (!removeButton || removeButton.disabled) {
    return false;
  }

  // Get the field wrapper that contains row count and required status.
  // These are set by canvas_stark_preprocess_field_multiple_value_form.
  const fieldWrapperRowCount = tableRow?.closest('[data-canvas-row-count]');
  if (!fieldWrapperRowCount) {
    return true;
  }

  const rowCount = parseInt(
    fieldWrapperRowCount.getAttribute('data-canvas-row-count') || '0',
    10,
  );
  // Check if the field is required by looking for .form-required class.
  // This class is added by Drupal to the label or field wrapper.
  if (tableRow) {
    const table = tableRow.closest('table');
    const fieldWrapper = table?.closest('.js-form-wrapper, .form-item');
    const isRequired =
      fieldWrapper?.querySelector('.form-required, .js-form-required') !== null;

    // Disable remove button if required field with only one item.
    if (isRequired && rowCount === 1) {
      return false;
    }
  }

  return true;
};

/**
 * Trigger the Drupal remove button for a multivalue field row.
 *
 * This function finds and clicks the hidden Drupal remove button that carries
 * the AJAX behavior. The button is hidden by CSS but remains in the DOM.
 *
 * @param triggerElement - The DOM element that triggers the action (should be inside a table row)
 * @returns boolean - true if the remove button was found and triggered, false otherwise
 */
export const triggerDrupalRemoveButton = (
  triggerElement: HTMLElement | null,
): boolean => {
  if (!triggerElement) return false;

  // Traverse up from the trigger element to find the containing table row,
  // then locate the Drupal remove button in the .canvas-remove-action cell.
  const tableRow = triggerElement.closest('tr');
  if (!tableRow) return false;

  // Find the original Drupal remove button directly (the hidden input/button
  // that carries the AJAX behavior). The cell and button are hidden by
  // CSS but remain in the DOM.
  const removeActionCell = tableRow.querySelector(
    '[data-canvas-remove-button]',
  );
  if (removeActionCell) {
    const removeButton = removeActionCell.querySelector(
      'input[type="submit"][data-once="drupal-ajax"]',
    ) as HTMLElement | null;
    if (removeButton) {
      // eslint-disable-next-line no-console
      console.log('[canvas:remove] found remove button', {
        id: removeButton.id,
        name: (removeButton as HTMLInputElement).name,
        disabled: (removeButton as HTMLInputElement).disabled,
        dataOnce: removeButton.getAttribute('data-once'),
        drupalAjaxEntry: (window as any).drupalSettings?.ajax?.[
          removeButton.id
        ],
        jQueryEvents: (window as any).jQuery?.(removeButton).data?.('events'),
      });
      // Dispatch mousedown first (some Drupal AJAX handlers listen for it),
      // then click — mirroring what Drupal's AJAX system expects.
      const mousedownEvent = new MouseEvent('mousedown', {
        bubbles: true,
        cancelable: true,
        view: window,
      });
      removeButton.dispatchEvent(mousedownEvent);
      removeButton.click();
      return true;
    }
  }

  return false;
};
