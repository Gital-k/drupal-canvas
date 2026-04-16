import { useCallback, useEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import { ArrowRightIcon, Cross2Icon, TrashIcon } from '@radix-ui/react-icons';
import * as Popover from '@radix-ui/react-popover';
import { Box, Button, Flex, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import DrupalInput from '@/components/form/components/drupal/DrupalInput';
import {
  selectUpdatePreview,
  setUpdatePreview,
} from '@/features/layout/layoutModelSlice';

import {
  dispatchSyntheticChange,
  isAutocompleteMenuOpen,
  isRemoveButtonEnabled,
  revertInputValue,
  toAutocompleteEntityUri,
  triggerDrupalRemoveButton,
} from './multivalueFormUtils';

import type { NumericInputAttributes } from '@/types/DrupalAttribute';

import styles from './DrupalInputMultivalueForm.module.css';

type MultivalueRefs = {
  isPreviewPaused: boolean;
  triggerRow: HTMLDivElement | null;
  triggerButton: HTMLButtonElement | null;
  popoverInput: HTMLInputElement | null;
  popoverContainer: HTMLDivElement | null;
  valueAtOpen: string;
  shouldRevertOnClose: boolean;
  pendingSyntheticCommit: string | null;
  closeAfterSyntheticCommit: boolean;
  autocompleteCleanup: (() => void) | null;
  autocompleteJustSelected: boolean;
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
  const dispatch = useAppDispatch();
  const updatePreview = useAppSelector(selectUpdatePreview);
  const initialValue = attributes.value || attributes.defaultValue || '';
  const refs = useRef<MultivalueRefs>({
    isPreviewPaused: false,
    triggerRow: null,
    triggerButton: null,
    popoverInput: null,
    popoverContainer: null,
    valueAtOpen: initialValue as string,
    shouldRevertOnClose: true,
    pendingSyntheticCommit: null,
    closeAfterSyntheticCommit: false,
    autocompleteCleanup: null,
    autocompleteJustSelected: false,
  });
  const [displayValue, setDisplayValue] = useState<string>(
    initialValue as string,
  );
  // Controlled popover state so we can close it programmatically on remove.
  const [popoverOpen, setPopoverOpen] = useState(false);
  const fieldLabel = attributes['data-field-label'] || '';

  const triggerValidationOnChange = (input: HTMLInputElement) => {
    if (typeof attributes.onChange !== 'function') {
      return;
    }
    const changeEvent = new Event('change');
    Object.defineProperty(changeEvent, 'target', {
      writable: false,
      value: input,
    });
    attributes.onChange(
      changeEvent as unknown as React.ChangeEvent<HTMLInputElement>,
    );
  };

  const hasInvalidValueInPopover = (): boolean =>
    !!refs.current.popoverContainer?.querySelector(
      '[data-invalid-prop-value="true"], [data-has-field-error="true"]',
    );

  const focusTriggerAfterClose = () => {
    setTimeout(() => {
      refs.current.triggerButton?.focus();
    }, 30);
  };

  const retryValidationIfNeeded = (
    committedValue: string,
    attemptsLeft = 4,
  ): void => {
    if (!refs.current.popoverInput || attemptsLeft <= 0) {
      return;
    }
    if (!hasInvalidValueInPopover()) {
      return;
    }
    setTimeout(() => {
      if (!refs.current.popoverInput || !hasInvalidValueInPopover()) {
        return;
      }
      refs.current.popoverInput.setAttribute(
        'data-canvas-force-update',
        'true',
      );
      dispatchSyntheticChange(refs.current.popoverInput, committedValue);
      triggerValidationOnChange(refs.current.popoverInput);
      retryValidationIfNeeded(committedValue, attemptsLeft - 1);
    }, 120);
  };

  const setPopoverOpenAndRefocus = (open: boolean) => {
    setPopoverOpen(open);
    if (!open) {
      focusTriggerAfterClose();
    }
  };

  const pausePreviewUpdates = useCallback(() => {
    if (!refs.current.isPreviewPaused) {
      dispatch(setUpdatePreview(false));
      refs.current.isPreviewPaused = true;
    }
  }, [dispatch]);

  const resumePreviewUpdates = useCallback(() => {
    if (refs.current.isPreviewPaused) {
      dispatch(setUpdatePreview(true));
      refs.current.isPreviewPaused = false;
    }
  }, [dispatch]);

  // Listen for the custom autocomplete-selected event dispatched by
  // autocomplete.extend.js when the user picks a suggestion.

  // Clean up the autocomplete listener whenever the popover closes.
  useEffect(() => {
    if (!popoverOpen) {
      refs.current.autocompleteCleanup?.();
      refs.current.autocompleteCleanup = null;
    }
  }, [popoverOpen]);

  useEffect(() => {
    if (!updatePreview) return;
    const pendingValue = refs.current.pendingSyntheticCommit;
    if (!pendingValue || !refs.current.popoverInput) return;
    refs.current.pendingSyntheticCommit = null;
    const closeAfterCommit = refs.current.closeAfterSyntheticCommit;
    refs.current.closeAfterSyntheticCommit = false;
    // Wait one tick so wrapped input handlers observe latest preview flag.
    setTimeout(() => {
      if (refs.current.popoverInput) {
        refs.current.popoverInput.setAttribute(
          'data-canvas-force-update',
          'true',
        );
        dispatchSyntheticChange(refs.current.popoverInput, pendingValue);
        triggerValidationOnChange(refs.current.popoverInput);

        // Autocomplete can mutate the input value shortly after selection
        // without firing change; trigger one follow-up change for validators.
        setTimeout(() => {
          if (!refs.current.popoverInput) {
            return;
          }
          const latestValue = refs.current.popoverInput.value;
          const latestCommittedValue = toAutocompleteEntityUri(latestValue);
          if (latestValue !== pendingValue || hasInvalidValueInPopover()) {
            refs.current.popoverInput.setAttribute(
              'data-canvas-force-update',
              'true',
            );
            dispatchSyntheticChange(
              refs.current.popoverInput,
              latestCommittedValue,
            );
            triggerValidationOnChange(refs.current.popoverInput);
            retryValidationIfNeeded(latestCommittedValue);
          }
          if (closeAfterCommit) {
            if (hasInvalidValueInPopover()) return;
            setPopoverOpenAndRefocus(false);
          }
        }, 80);
      }
    }, 0);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [updatePreview]);

  // Handle Enter and Escape key presses in popover input.
  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    // Handle Escape key to close popover without committing changes
    if (e.key === 'Escape') {
      resumePreviewUpdates();
      handlePopoverOpenChange(false);
      return;
    }

    if (e.key === 'Enter') {
      // If a jQuery UI autocomplete suggestion was just selected synchronously
      // (before this React event handler ran via event delegation), do NOT
      // close the popover — the data-canvas-autocomplete-selected handler
      // already committed the correct value.
      if (refs.current.autocompleteJustSelected) {
        refs.current.autocompleteJustSelected = false;
        return;
      }

      // If a jQuery UI autocomplete dropdown is currently open, do NOT
      // intercept Enter — let jQuery UI process the selection so that
      // the autocomplete select event fires. The data-canvas-autocomplete-selected
      // listener will then commit the correct suggestion value and close the popover.
      const inputElement =
        refs.current.popoverInput || (e.target as HTMLInputElement);
      if (isAutocompleteMenuOpen(inputElement)) {
        return;
      }

      e.preventDefault();

      setTimeout(() => {
        if (refs.current.popoverInput) {
          const hasError = !!refs.current.popoverInput.closest(
            '[data-has-field-error="true"]',
          );
          if (!hasError) {
            const committedValue = refs.current.popoverInput.value;
            setDisplayValue(committedValue);
            refs.current.shouldRevertOnClose = false;
            refs.current.pendingSyntheticCommit = committedValue;
            refs.current.closeAfterSyntheticCommit = true;
            resumePreviewUpdates();
          }
        }
      });
    }
  };

  const handlePopoverOpenChange = (open: boolean) => {
    if (open) {
      pausePreviewUpdates();
      refs.current.shouldRevertOnClose = true;
      setTimeout(() => {
        if (refs.current.popoverContainer) {
          const handleSelected = (e: Event) => {
            if (!refs.current.popoverContainer?.contains(e.target as Node))
              return;
            // Use the custom event detail as the source of truth for the
            // chosen suggestion text; relying on input.value here can race.
            const selectedLabelValue =
              (e as CustomEvent<{ value: string }>).detail?.value ||
              (e.target instanceof HTMLInputElement ? e.target.value : '');
            const committedValue = toAutocompleteEntityUri(selectedLabelValue);

            refs.current.autocompleteJustSelected = true;
            // Keep the compact row text user-friendly while committing
            // transform-ready value to form state.
            setDisplayValue(selectedLabelValue);
            refs.current.shouldRevertOnClose = false;
            refs.current.pendingSyntheticCommit = committedValue;
            refs.current.closeAfterSyntheticCommit = true;

            // Commit immediately so the transformed value persists to form state
            // and preview, then keep deferred flow as a safety net for delayed
            // autocomplete mutations.
            if (refs.current.popoverInput) {
              refs.current.popoverInput.setAttribute(
                'data-canvas-selected-committed-value',
                committedValue,
              );
              refs.current.popoverInput.setAttribute(
                'data-canvas-force-update',
                'true',
              );
              dispatchSyntheticChange(
                refs.current.popoverInput,
                committedValue,
              );
              triggerValidationOnChange(refs.current.popoverInput);
            }

            resumePreviewUpdates();
          };
          document.addEventListener(
            'data-canvas-autocomplete-selected',
            handleSelected,
          );
          refs.current.autocompleteCleanup = () =>
            document.removeEventListener(
              'data-canvas-autocomplete-selected',
              handleSelected,
            );
        }
        const input = refs.current.popoverContainer?.querySelector(
          'input',
        ) as HTMLInputElement | null;
        if (input) {
          refs.current.popoverInput = input;
          refs.current.valueAtOpen = input.value;
          input.setAttribute('data-canvas-popover-autocomplete', 'true');
          input.select();
        }
      }, 0);
    } else {
      // If we explicitly close the popover, the changes made thus far should
      // be undone, and the field reverted to its value prior to opening.
      if (refs.current.shouldRevertOnClose && refs.current.popoverInput) {
        revertInputValue(refs.current.popoverInput, refs.current.valueAtOpen);
      }

      setPopoverOpenAndRefocus(false);
      resumePreviewUpdates();
      refs.current.shouldRevertOnClose = true;
      return;
    }

    setPopoverOpenAndRefocus(true);
  };

  const handleRemove = (e: any) => {
    e.preventDefault();
    const triggerElement = refs.current.triggerRow;
    if (!triggerElement) return;
    // Close the popover first so the portal is cleaned up before AJAX replaces the DOM.
    setPopoverOpenAndRefocus(false);
    resumePreviewUpdates();
    setTimeout(() => {
      triggerDrupalRemoveButton(triggerElement);
    });
  };

  return (
    <div style={{ display: 'contents' }}>
      <Popover.Root open={popoverOpen} onOpenChange={handlePopoverOpenChange}>
        <Flex
          ref={(node) => {
            refs.current.triggerRow = node;
          }}
          align="center"
          gap="2"
          className={styles.itemRow}
        >
          {/* List Item View - Trigger */}
          <Popover.Trigger asChild>
            <button
              ref={(node) => {
                refs.current.triggerButton = node;
              }}
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
          ref={(node) => {
            refs.current.popoverContainer = node;
          }}
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
              return;
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
              disabled={!isRemoveButtonEnabled(refs.current.triggerRow)}
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
