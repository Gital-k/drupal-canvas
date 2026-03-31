import { useCallback, useEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import {
  ArrowRightIcon,
  Cross2Icon,
  ExclamationTriangleIcon,
  TrashIcon,
} from '@radix-ui/react-icons';
import { Box, Button, Callout, Flex, Popover, Text } from '@radix-ui/themes';

import DrupalInput from '@/components/form/components/drupal/DrupalInput';
import TextField from '@/components/form/components/TextField';
import InputBehaviors from '@/components/form/inputBehaviors';
import { a2p } from '@/local_packages/utils';

import type { NumericInputAttributes } from '@/types/DrupalAttribute';

import styles from './DrupalInputMultivalueForm.module.css';

// Create the wrapped TextField component with InputBehaviors HOC.
const TextFieldWithBehaviors = InputBehaviors(TextField);

// Type for validation results.
type ValidationResult = {
  valid: boolean;
  errorMessage?: string;
};

/**
 * DrupalInputMultivalueForm component for inputs within multivalue widgets.
 *
 * This component displays a compact list item with drag handle that opens
 * an edit popover when clicked. The design matches the multivalue field
 * pattern with:
 * - List view: drag handle + text preview
 * - Edit popover: label, close button, input field, remove button
 */
const DrupalInputMultivalueForm = ({
  attributes = {},
}: {
  attributes?: NumericInputAttributes & {
    onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
    value?: string;
    name?: string;
    id?: string;
    'data-field-label'?: string;
  };
}) => {
  // Get the initial value from attributes
  const initialValue = attributes.value || attributes.defaultValue || '';
  // Manage local state to sync display value with input changes
  const [displayValue, setDisplayValue] = useState<string>(
    initialValue as string,
  );
  // Temporary value for the popover input (only committed on Enter).
  const [tempValue, setTempValue] = useState<string>(initialValue as string);
  const inputWrapperRef = useRef<HTMLDivElement | null>(null);
  // Ref for the trigger area that lives inside the actual table row.
  const triggerRowRef = useRef<HTMLDivElement | null>(null);
  // Controlled popover state so we can close it programmatically on remove.
  const [popoverOpen, setPopoverOpen] = useState(false);
  // Validation error message to display in the popover.
  const [validationError, setValidationError] = useState<string | null>(null);
  const fieldLabel = attributes['data-field-label'] || '';
  // Ref for the popover trigger to restore focus after closing.
  const triggerRef = useRef<HTMLButtonElement | null>(null);
  // Ref for the popover input to focus it when opening.
  const popoverInputRef = useRef<HTMLInputElement | null>(null);
  // Ref to the Box containing the TextField so we can find the input element
  const popoverTextFieldWrapperRef = useRef<HTMLDivElement | null>(null);
  const popoverContainerRef = useRef<HTMLDivElement | null>(null);

  // Sync displayValue with attributes.value when it changes (e.g., after AJAX updates)
  useEffect(() => {
    const newValue = attributes.value || attributes.defaultValue || '';
    setDisplayValue(newValue as string);
    setTempValue(newValue as string);
  }, [attributes.value, attributes.defaultValue]);

  // Validation callback — uses native HTML5 validation but displays messages in the popover.
  // The popover input is React-controlled (value={tempValue}), so its native value already
  // reflects the current input; checkValidity() can be called directly.
  const validateInputValue = useCallback(
    (inputElement: HTMLInputElement | null): ValidationResult => {
      if (!inputElement) {
        return { valid: true };
      }

      if (!inputElement.checkValidity()) {
        return {
          valid: false,
          errorMessage: inputElement.validationMessage,
        };
      }

      return { valid: true };
    },
    [],
  );

  // Commit a value directly — used by the autocomplete selection handler so
  // the table row updates immediately.
  // Returns true if the value was committed, false if validation failed.
  const commitValue = useCallback(
    (newValue: string, skipValidation = false): boolean => {
      // Validate before committing unless explicitly skipped.
      if (!skipValidation && popoverInputRef.current) {
        const validationResult = validateInputValue(popoverInputRef.current);
        if (!validationResult.valid) {
          setValidationError(validationResult.errorMessage || 'Invalid value');
          return false;
        }
      }

      // Clear any validation errors on successful commit.
      setValidationError(null);
      setDisplayValue(newValue);
      setTempValue(newValue);

      if (inputWrapperRef.current) {
        const realInput = inputWrapperRef.current.querySelector(
          'input',
        ) as HTMLInputElement | null;
        if (realInput && realInput.value !== newValue) {
          const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
            HTMLInputElement.prototype,
            'value',
          )?.set;
          nativeInputValueSetter?.call(realInput, newValue);
          realInput.dispatchEvent(new Event('input', { bubbles: true }));
          realInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
      }
      return true;
    },
    [validateInputValue],
  );

  // Listen for the custom autocomplete-selected event dispatched by
  // autocomplete.extend.js when the user picks a suggestion.
  const autocompleteCleanupRef = useRef<(() => void) | null>(null);
  // Flag to indicate an autocomplete suggestion was just selected. Set by the
  // data-canvas-autocomplete-selected handler so that handleKeyDown (which may
  // fire after jQuery UI has already closed the dropdown) does not overwrite
  // the committed value with the stale tempValue.
  const autocompleteJustSelectedRef = useRef(false);

  // Clean up the autocomplete listener whenever the popover closes.
  useEffect(() => {
    if (!popoverOpen) {
      autocompleteCleanupRef.current?.();
      autocompleteCleanupRef.current = null;
    }
  }, [popoverOpen]);

  // Handle temporary input changes in popover (not committed until Enter).
  const handleTempInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    // Clear validation error when user starts typing.
    setValidationError(null);
    setTempValue(e.target.value);
  };

  // Commit the temporary value to the actual input and display.
  // commitValue now handles validation internally and returns success status.
  const handleCommitValue = () => {
    const success = commitValue(tempValue);
    if (success) {
      setPopoverOpen(false);
    }
  };

  // Handle Enter key press in popover input.
  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      // If a jQuery UI autocomplete suggestion was just selected synchronously
      // (before this React event handler ran via event delegation), do NOT
      // commit the stale tempValue. The data-canvas-autocomplete-selected
      // handler already committed the correct suggestion value.
      if (autocompleteJustSelectedRef.current) {
        autocompleteJustSelectedRef.current = false;
        return;
      }

      // If a jQuery UI autocomplete dropdown is currently open, do NOT
      // intercept Enter — let jQuery UI process the selection so that
      // the auto complete select event fires. The data-canvas-autocomplete-selected
      // listener will then commit the correct suggestion value and close the popover.
      const inputElement =
        popoverInputRef.current || (e.target as HTMLInputElement);
      const $jq =
        typeof window !== 'undefined' && (window as any).jQuery
          ? (window as any).jQuery
          : null;
      const isAutocompleteOpen =
        $jq &&
        $jq(inputElement)
          .data('ui-autocomplete')
          ?.menu?.element?.is?.(':visible');

      if (isAutocompleteOpen) {
        return;
      }

      e.preventDefault();

      // Use handleCommitValue which validates and closes the popover on success.
      handleCommitValue();
    }
  };

  // Handle popover open/close - reset tempValue when opening,
  // revert when closing without commit.
  const handlePopoverOpenChange = (open: boolean) => {
    if (open) {
      // When opening, set tempValue to current displayValue and clear any previous errors.
      setTempValue(displayValue);
      setValidationError(null);
      // Focus the input field in the popover. Find it inside the container
      // since TextFieldAutocomplete does not forward the ref from attributes.
      // Also re-attach Drupal behaviors so that entity_autocomplete (jQuery UI
      // autocomplete) is initialized on the input that just appeared inside
      // the Radix UI portal — it was never seen by Drupal.behaviors.
      // autocomplete during the initial page load.
      setTimeout(() => {
        if (popoverContainerRef.current) {
          // @todo Refactor this as a part of https://www.drupal.org/i/3581159.
          window.Drupal?.attachBehaviors(popoverContainerRef.current);

          const handleSelected = (e: Event) => {
            if (!popoverContainerRef.current?.contains(e.target as Node))
              return;
            autocompleteJustSelectedRef.current = true;
          };
          document.addEventListener(
            'data-canvas-autocomplete-selected',
            handleSelected,
          );
          autocompleteCleanupRef.current = () =>
            document.removeEventListener(
              'data-canvas-autocomplete-selected',
              handleSelected,
            );
        }
        const input = popoverContainerRef.current?.querySelector(
          'input',
        ) as HTMLInputElement | null;
        if (input) {
          popoverInputRef.current = input;
          input.select();
        }
      }, 0);
    } else {
      // Restore focus to the trigger button after closing.
      setTimeout(() => {
        if (triggerRef.current) {
          triggerRef.current.focus();
        }
      }, 0);
    }
    setPopoverOpen(open);
  };

  // Determine if the remove button should be enabled.
  const isRemoveButtonEnabled = () => {
    // Check whether the table row has a Drupal remove button.
    const tableRow = triggerRowRef.current?.closest('tr');
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

    // Get the field wrapper that contains row count and required status
    // These are set by canvas_stark_preprocess_field_multiple_value_form
    const fieldWrapperRowCount = tableRow?.closest('[data-canvas-row-count]');
    if (!fieldWrapperRowCount) {
      return true;
    }

    const rowCount = parseInt(
      fieldWrapperRowCount.getAttribute('data-canvas-row-count') || '0',
      10,
    );
    // Check if the field is required by looking for .form-required class
    // This class is added by Drupal to the label or field wrapper
    if (tableRow) {
      const table = tableRow.closest('table');
      const fieldWrapper = table?.closest('.js-form-wrapper, .form-item');
      const isRequired =
        fieldWrapper?.querySelector('.form-required, .js-form-required') !==
        null;

      // Disable remove button if required field with only one item
      if (isRequired && rowCount === 1) {
        return false;
      }
    }

    return true;
  };

  const handleRemove = () => {
    // Close the popover first so the portal is cleaned up before AJAX replaces the DOM.
    setPopoverOpen(false);

    if (!triggerRowRef.current) return;

    // Traverse up from the trigger element to find the containing table row,
    // then locate the Drupal remove button in the .canvas-remove-action cell.
    const tableRow = triggerRowRef.current.closest('tr');
    if (!tableRow) return;

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
        return;
      }
    }

    setDisplayValue('');
    if (!inputWrapperRef.current) return;
    const inputElement = inputWrapperRef.current.querySelector(
      'input',
    ) as HTMLInputElement | null;
    if (inputElement) {
      const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
        HTMLInputElement.prototype,
        'value',
      )?.set;
      nativeInputValueSetter?.call(inputElement, '');
      const event = new Event('input', { bubbles: true });
      inputElement.dispatchEvent(event);
    }
  };

  return (
    <>
      {/* Hidden input field for accessibility and form functionality */}
      <Box
        ref={inputWrapperRef}
        className={styles.visuallyHiddenInput}
        aria-hidden="true"
      >
        <TextFieldWithBehaviors
          {...(attributes.class ? { className: clsx(attributes.class) } : {})}
          {...{
            attributes: {
              ...a2p(attributes, {}, { skipAttributes: ['class'] }),
              tabIndex: -1,
              // Skip validation in InputBehaviors for this hidden input.
              // Validation is handled in the popover instead.
              'data-canvas-skip-validation': 'true',
            },
          }}
        />
      </Box>

      <Popover.Root open={popoverOpen} onOpenChange={handlePopoverOpenChange}>
        <Flex
          ref={triggerRowRef}
          align="center"
          gap="2"
          className={styles.itemRow}
        >
          {/* List Item View - Trigger */}
          <Popover.Trigger>
            <button
              ref={triggerRef}
              className={styles.listItem}
              type="button"
              aria-label={`Edit ${fieldLabel}: ${displayValue || 'Empty'}`}
            >
              <Text size="2" className={styles.itemText}>
                {displayValue || 'Empty'}
              </Text>
              <ArrowRightIcon className={styles.arrowIcon} />
            </button>
          </Popover.Trigger>
        </Flex>

        {/* Edit Popover */}
        <Popover.Content
          ref={popoverContainerRef}
          side="left"
          align="start"
          sideOffset={6}
          className={styles.popoverContent}
          style={{ maxWidth: '235px' }}
          onInteractOutside={(e) => {
            // Prevent the popover from closing when the user clicks on a
            // jQuery UI autocomplete suggestion. The dropdown is rendered in
            // a portal outside the popover, so Radix treats it as an outside
            // click and would close the popover before the selection is
            // committed via the MutationObserver.
            const target = e.target as Element | null;
            if (target?.closest('.ui-autocomplete, .ui-menu')) {
              e.preventDefault();
            }
          }}
        >
          {/* Popover Header */}
          <Flex
            justify="between"
            align="center"
            className={styles.popoverHeader}
          >
            <Text size="1" weight="medium" className={styles.popoverLabel}>
              {fieldLabel}
            </Text>
            <Popover.Close aria-label="Close">
              <Cross2Icon />
            </Popover.Close>
          </Flex>

          {/* Input Field - Visual duplicate for popover editing */}
          <Box ref={popoverTextFieldWrapperRef}>
            {attributes?.class instanceof Array &&
            attributes?.class?.includes('form-autocomplete') ? (
              <DrupalInput
                attributes={{
                  ...attributes,
                  value: tempValue,
                  onChange: handleTempInputChange,
                  onKeyDown: handleKeyDown,
                  // Skip validation in InputBehaviors - validation is handled manually in the popover.
                  'data-canvas-skip-validation': 'true',
                }}
              />
            ) : (
              <TextField
                className={clsx(attributes.class)}
                attributes={{
                  ...attributes,
                  value: tempValue,
                  onChange: handleTempInputChange,
                  onKeyDown: handleKeyDown,
                  type: attributes.type || 'text',
                  placeholder: attributes.placeholder,
                  ...Object.fromEntries(
                    ['min', 'max', 'step']
                      .filter(
                        (key) =>
                          attributes[key as keyof typeof attributes] !==
                          undefined,
                      )
                      .map((key) => [
                        key,
                        attributes[key as keyof typeof attributes],
                      ]),
                  ),
                }}
              />
            )}
          </Box>

          {/* Validation Error Display */}
          {validationError && (
            <Callout.Root color="red" size="1" mt="2">
              <Callout.Icon>
                <ExclamationTriangleIcon />
              </Callout.Icon>
              <Callout.Text size="1">{validationError}</Callout.Text>
            </Callout.Root>
          )}

          {/* Remove Button */}
          <Flex justify="center" className={styles.removeButtonContainer}>
            <Button
              variant="ghost"
              color="red"
              size="1"
              onClick={handleRemove}
              disabled={!isRemoveButtonEnabled()}
            >
              <TrashIcon />
              Remove
            </Button>
          </Flex>
        </Popover.Content>
      </Popover.Root>
    </>
  );
};

export default DrupalInputMultivalueForm;
