import { useCallback, useEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import { ArrowRightIcon, Cross2Icon, TrashIcon } from '@radix-ui/react-icons';
import * as Popover from '@radix-ui/react-popover';
import { Box, Button, Flex, Text } from '@radix-ui/themes';

import DrupalInput from '@/components/form/components/drupal/DrupalInput';

import {
  isRemoveButtonEnabled,
  triggerDrupalRemoveButton,
} from './multivalueFormUtils';

import type { NumericInputAttributes } from '@/types/DrupalAttribute';

import styles from './DrupalInputMultivalueForm.module.css';

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
  const initialValue = attributes.value || attributes.defaultValue || '';
  const [displayValue, setDisplayValue] = useState<string>(
    initialValue as string,
  );
  // Ref for the trigger area that lives inside the actual table row.
  const triggerRowRef = useRef<HTMLDivElement | null>(null);
  // Controlled popover state so we can close it programmatically on remove.
  const [popoverOpen, setPopoverOpen] = useState(false);
  const fieldLabel = attributes['data-field-label'] || '';
  // Ref for the popover trigger to restore focus after closing.
  const triggerRef = useRef<HTMLButtonElement | null>(null);
  // Ref for the popover input — set when the popover opens.
  const popoverInputRef = useRef<HTMLInputElement | null>(null);
  const popoverContainerRef = useRef<HTMLDivElement | null>(null);

  // Commit a value directly — used by the autocomplete selection handler so
  // the table row updates immediately.
  const commitValue = useCallback((newValue: string) => {
    setDisplayValue(newValue);
  }, []);

  // Listen for the custom autocomplete-selected event dispatched by
  // autocomplete.extend.js when the user picks a suggestion.
  const autocompleteCleanupRef = useRef<(() => void) | null>(null);
  // Flag to indicate an autocomplete suggestion was just selected. Set by the
  // data-canvas-autocomplete-selected handler so that handleKeyDown (which may
  // fire after jQuery UI has already closed the dropdown) does not close the
  // popover before the committed value has been reflected.
  const autocompleteJustSelectedRef = useRef(false);

  // Clean up the autocomplete listener whenever the popover closes.
  useEffect(() => {
    if (!popoverOpen) {
      autocompleteCleanupRef.current?.();
      autocompleteCleanupRef.current = null;
    }
  }, [popoverOpen]);

  // Handle Enter key press in popover input.
  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      // If a jQuery UI autocomplete suggestion was just selected synchronously
      // (before this React event handler ran via event delegation), do NOT
      // close the popover — the data-canvas-autocomplete-selected handler
      // already committed the correct value.
      if (autocompleteJustSelectedRef.current) {
        autocompleteJustSelectedRef.current = false;
        return;
      }

      // If a jQuery UI autocomplete dropdown is currently open, do NOT
      // intercept Enter — let jQuery UI process the selection so that
      // the autocomplete select event fires. The data-canvas-autocomplete-selected
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

      setTimeout(() => {
        if (popoverInputRef.current) {
          const hasError = !!popoverInputRef.current.closest(
            '[data-has-field-error="true"]',
          );
          if (!hasError) {
            setDisplayValue(popoverInputRef.current.value);
            setPopoverOpen(false);
          }
        }
      });
    }
  };

  const handlePopoverOpenChange = (open: boolean) => {
    if (open) {
      setTimeout(() => {
        if (popoverContainerRef.current) {
          const handleSelected = (e: Event) => {
            if (!popoverContainerRef.current?.contains(e.target as Node))
              return;
            autocompleteJustSelectedRef.current = true;
            commitValue((e as CustomEvent<{ value: string }>).detail.value);
            setPopoverOpen(false);
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
      // If we explicitly close the popover, the changes made thus far should
      // be undone, and the field reverted to its value prior to opening.
      if (popoverInputRef.current) {
        // Set the value in a way that triggers React's onChange
        const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
          window.HTMLInputElement.prototype,
          'value',
        )?.set;
        if (nativeInputValueSetter) {
          nativeInputValueSetter.call(popoverInputRef.current, displayValue);
          // Dispatch input event to trigger React's onChange
          const inputEvent = new Event('input', { bubbles: true });
          popoverInputRef.current.dispatchEvent(inputEvent);
        }
      }
      // Restore focus to the trigger button after closing.
      setTimeout(() => {
        if (triggerRef.current) {
          triggerRef.current.focus();
        }
      }, 0);
    }
    setPopoverOpen(open);
  };

  const handleRemove = (e: any) => {
    e.preventDefault();
    const triggerElement = triggerRowRef.current;
    if (!triggerElement) return;
    // Close the popover first so the portal is cleaned up before AJAX replaces the DOM.
    setPopoverOpen(false);

    // Try to trigger the Drupal remove button.
    if (triggerDrupalRemoveButton(triggerElement)) {
      return;
    }

    // Fallback: no Drupal remove button (e.g. non-AJAX field), just clear.
    setDisplayValue('');
  };

  return (
    <div style={{ display: 'contents' }}>
      <Popover.Root open={popoverOpen} onOpenChange={handlePopoverOpenChange}>
        <Flex
          ref={triggerRowRef}
          align="center"
          gap="2"
          className={styles.itemRow}
        >
          {/* List Item View - Trigger */}
          <Popover.Trigger asChild>
            <button
              ref={triggerRef}
              className={styles.listItem}
              type="button"
              aria-label={`Edit ${fieldLabel}: ${displayValue || 'Empty'}`}
            >
              <Text
                size="2"
                className={styles.itemText}
                data-canvas-multivalue-label
              >
                {displayValue || 'Empty'}
              </Text>
              <ArrowRightIcon className={styles.arrowIcon} />
            </button>
          </Popover.Trigger>
        </Flex>

        {/* Edit Popover */}
        <Popover.Content
          forceMount={true}
          ref={popoverContainerRef}
          side="left"
          align="start"
          sideOffset={6}
          className={clsx(styles.popoverContent, [
            !popoverOpen && styles.visuallyHiddenInput,
          ])}
          style={{ maxWidth: '235px' }}
          onInteractOutside={(e) => {
            // Prevent the popover from closing when the user clicks on a
            // jQuery UI autocomplete suggestion. The dropdown is rendered in
            // a portal outside the popover, so Radix treats it as an outside
            // click and would close the popover before the selection is
            // committed.
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

          {/* Input Field */}
          <Box>
            <DrupalInput
              attributes={{ ...attributes, onKeyDown: handleKeyDown }}
            />
          </Box>

          {/* Remove Button */}
          <Flex justify="center" className={styles.removeButtonContainer}>
            <Button
              variant="ghost"
              color="red"
              size="1"
              onClick={handleRemove}
              disabled={!isRemoveButtonEnabled(triggerRowRef.current)}
            >
              <TrashIcon />
              Remove
            </Button>
          </Flex>
        </Popover.Content>
      </Popover.Root>
    </div>
  );
};

export default DrupalInputMultivalueForm;
