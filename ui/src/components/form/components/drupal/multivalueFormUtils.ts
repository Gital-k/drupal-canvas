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
  const removeActionCell = tableRow?.querySelector('.canvas-remove-action');

  // Look for the Drupal remove button. Drupal adds these buttons to all rows
  // in unlimited cardinality fields.
  const removeButton = removeActionCell?.querySelector(
    'input[type="submit"][name*="remove_button"]',
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
 * Normalize the _weight selects/inputs of a multivalue table so that each
 * element's submitted value matches the visual DOM row order.
 *
 * The problem: after a wrong-order AJAX rebuild, PHP renders rows in the order
 * it sorted them (e.g. delta-1 first, delta-0 second). The <select> elements
 * keep their original delta-based names (field[0][_weight], field[1][_weight])
 * but now appear in the wrong DOM positions. Simply writing 0,1,2 by DOM
 * position won't help because the names don't match their positions — PHP
 * would still sort wrong.
 *
 * The correct fix: set each weight element's value equal to its DOM row index,
 * AND swap the name attributes so name[N][_weight] corresponds to the Nth DOM
 * row. PHP then receives delta-0=0, delta-1=1, etc. and sorts to the current
 * visual order.
 *
 * Call this immediately before triggering any AJAX action (add or remove).
 *
 * @param triggerElement - Any element inside the multivalue field wrapper.
 */
export const normalizeRowWeights = (
  triggerElement: HTMLElement | null,
): void => {
  if (!triggerElement) return;
  const tbody = triggerElement
    .closest('[data-canvas-multiple-values]')
    ?.querySelector('table.field-multiple-table tbody');
  if (!tbody) return;

  // Only operate on real (non-optimistic) draggable rows.
  const rows = [
    ...tbody.querySelectorAll<HTMLTableRowElement>('tr.draggable'),
  ].filter((row) => !row.hasAttribute('data-canvas-optimistic'));

  // Weight fields render as <select> when delta <= weight_select_max (15),
  // otherwise as <input type="number">. Collect them in DOM order.
  const weightEls = rows.map((row) =>
    row.querySelector<HTMLSelectElement | HTMLInputElement>(
      '[name*="_weight"]',
    ),
  );

  // Extract the delta from each element's name, e.g. "field[1][_weight]" → 1.
  // Then assign names sequentially (0, 1, 2…) to match DOM row order, and
  // set the corresponding value so PHP receives delta-N = weight N.
  const deltaPattern = /\[(\d+)\]\[_weight\]/;
  weightEls.forEach((el, i) => {
    if (!el) return;
    el.name = el.name.replace(deltaPattern, `[${i}][_weight]`);
    el.value = String(i);
  });
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

  tableRow.style.display = 'none';
  normalizeRowWeights(triggerElement);
  // Find the original Drupal remove button directly (the hidden input/button
  // that carries the AJAX behavior). The cell and button are hidden by
  // CSS but remain in the DOM.
  const removeActionCell = tableRow.querySelector('.canvas-remove-action');
  if (removeActionCell) {
    const removeButton = removeActionCell.querySelector(
      'input[type="submit"][name*="remove_button"]',
    ) as HTMLElement | null;
    if (removeButton) {
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

/**
 * Copy attributes from a source element or object to a target object.
 *
 * This helper copies all attributes except for those specified in the skip list.
 * It works with both DOM elements (reading from their attributes) and plain objects.
 * This is useful for transferring validation and configuration attributes from
 * hidden Drupal inputs to visible React inputs.
 *
 * @param source - The element or object to copy attributes from
 * @param attributesToSkip - Array of attribute names to skip (defaults to ['id', 'name', 'class', 'style', 'value', 'type', 'defaultValue', 'onChange', 'data-field-label'])
 * @returns An object with attribute names as keys and their values
 */
export const copyInputAttributes = (
  source: HTMLElement | Record<string, any> | null,
  attributesToSkip: string[] = [
    'id',
    'name',
    'class',
    'style',
    'value',
    'type',
    'defaultValue',
    'onChange',
    'data-field-label',
  ],
): Record<string, any> => {
  const copiedAttributes: Record<string, any> = {};

  if (!source) return copiedAttributes;

  // Check if source is a DOM element.
  if (source instanceof HTMLElement) {
    // Convert attributes to array and filter out the ones to skip.
    Array.from(source.attributes).forEach((attr) => {
      if (!attributesToSkip.includes(attr.name)) {
        copiedAttributes[attr.name] = attr.value;
      }
    });
  } else {
    // Source is a plain object, filter out unwanted keys.
    Object.keys(source).forEach((key) => {
      if (!attributesToSkip.includes(key) && source[key] !== undefined) {
        copiedAttributes[key] = source[key];
      }
    });
  }

  return copiedAttributes;
};

/**
 * Update an input element's value using the native value setter and dispatch events.
 *
 * This ensures that both React's controlled input handlers and Drupal's AJAX handlers
 * are properly notified of the value change. This is necessary because directly setting
 * input.value doesn't trigger React's onChange handlers.
 *
 * @param inputElement - The input element to update
 * @param value - The new value to set
 */
export const updateInputValue = (
  inputElement: HTMLInputElement | null,
  value: string,
): void => {
  if (!inputElement) return;

  // Only update if the value has actually changed.
  if (inputElement.value === value) return;

  // Use the native value setter to bypass React's controlled-input override.
  const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
    HTMLInputElement.prototype,
    'value',
  )?.set;

  nativeInputValueSetter?.call(inputElement, value);

  // Dispatch both input and change events so React and Drupal handlers are notified.
  inputElement.dispatchEvent(new Event('input', { bubbles: true }));
  inputElement.dispatchEvent(new Event('change', { bubbles: true }));
};
