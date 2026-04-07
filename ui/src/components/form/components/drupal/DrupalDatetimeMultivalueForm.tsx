import { useEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import { ArrowRightIcon, Cross2Icon, TrashIcon } from '@radix-ui/react-icons';
import * as Popover from '@radix-ui/react-popover';
import { Button, Flex, Text } from '@radix-ui/themes';

import {
  isRemoveButtonEnabled,
  triggerDrupalRemoveButton,
} from './multivalueFormUtils';

import styles from './DrupalInputMultivalueForm.module.css';

/**
 * Format a date string for display using Intl.DateTimeFormat.
 * This matches the format shown in native date inputs.
 */
const formatDateForDisplay = (value: string): string => {
  if (!value) return value;

  try {
    // Parse ISO date string (YYYY-MM-DD) and format using browser's locale.
    const date = new Date(value + 'T00:00:00');
    // Use Intl.DateTimeFormat to match native input formatting.
    return new Intl.DateTimeFormat().format(date);
  } catch {
    return value;
  }
};

/**
 * Format time for display using Intl.DateTimeFormat.
 * This matches the format shown in native time inputs.
 */
const formatTimeForDisplay = (value: string): string => {
  if (!value) return value;

  try {
    // Create a date with the time value to format it.
    const date = new Date(`2000-01-01T${value}`);
    // Only show seconds if the value explicitly includes non-zero seconds.
    // Browsers normalize time input values to include ':00' seconds even when
    // only HH:MM was typed, so we check for non-zero seconds explicitly.
    const parts = value.split(':');
    const hasNonZeroSeconds =
      parts.length === 3 && parts[2] !== '00' && parts[2] !== '00.000';
    // Use Intl.DateTimeFormat to match native input formatting.
    return new Intl.DateTimeFormat(undefined, {
      hour: 'numeric',
      minute: 'numeric',
      second: hasNonZeroSeconds ? 'numeric' : undefined,
      hour12: true,
    }).format(date);
  } catch {
    return value;
  }
};

/**
 * DrupalDatetimeMultivalueForm component for datetime widgets within multivalue fields.
 *
 * This component wraps the entire datetime widget (date + time inputs) and displays them
 * as a single combined value in the list view, with separate date and time inputs in the popover.
 *
 * The children (real Drupal date/time inputs) are rendered inside Popover.Content with
 * forceMount={true} so they remain in the DOM for form submission at all times, hidden
 * via CSS when the popover is closed.
 */
const DrupalDatetimeMultivalueForm = ({
  children,
  fieldLabel = '',
}: {
  children?: React.ReactNode;
  fieldLabel?: string;
}) => {
  const triggerRowRef = useRef<HTMLDivElement | null>(null);
  // Callback ref: state is set when Radix mounts the portal content, which
  // may be deferred past the first render commit. Using state (rather than a
  // ref) means the useEffect below re-runs reliably as soon as the node is
  // available, without polling or an arbitrary timeout.
  const [popoverContainer, setPopoverContainer] =
    useState<HTMLDivElement | null>(null);
  const [popoverOpen, setPopoverOpen] = useState(false);
  const [displayDate, setDisplayDate] = useState('');
  const [displayTime, setDisplayTime] = useState('');
  // Tracks whether a time input exists — derived from the real DOM via the
  // useEffect below rather than queried at render time, so it is stable across
  // renders and correct before the popover container is first populated.
  const [hasTime, setHasTime] = useState(false);

  // Read initial values from the inputs once the popover container is in the DOM.
  // Depends only on popoverContainer: when children change (AJAX rebuild) Radix
  // remounts the content node, which re-fires setPopoverContainer and re-runs
  // this effect automatically.
  useEffect(() => {
    if (!popoverContainer) return;

    const dateInput = popoverContainer.querySelector(
      'input[type="date"]',
    ) as HTMLInputElement | null;
    const timeInput = popoverContainer.querySelector(
      'input[type="time"]',
    ) as HTMLInputElement | null;

    setDisplayDate(dateInput?.value || dateInput?.defaultValue || '');
    setDisplayTime(timeInput?.value || timeInput?.defaultValue || '');
    setHasTime(!!timeInput);
  }, [popoverContainer]);

  // Returns the date input from the popover container, or null.
  const getDateInput = (): HTMLInputElement | null =>
    popoverContainer?.querySelector('input[type="date"]') ?? null;

  // Returns the time input from the popover container, or null.
  const getTimeInput = (): HTMLInputElement | null =>
    popoverContainer?.querySelector('input[type="time"]') ?? null;

  // Validate an input element; shows native validation UI and returns false if invalid.
  const checkValid = (input: HTMLInputElement | null): boolean => {
    if (input && !input.checkValidity()) {
      input.reportValidity();
      return false;
    }
    return true;
  };

  // Handle Enter key press in popover inputs.
  const handleKeyDown = (e: React.KeyboardEvent<HTMLElement>) => {
    if (e.key !== 'Enter') return;

    e.preventDefault();

    const inputElement = e.target as HTMLInputElement;

    if (!checkValid(inputElement)) return;

    // If the date input is valid, also validate the time input (if present).
    if (inputElement.type === 'date' && !checkValid(getTimeInput())) return;

    // If the time input is valid, also validate the date input.
    if (inputElement.type === 'time' && !checkValid(getDateInput())) return;

    const dateInput = getDateInput();
    const timeInput = getTimeInput();
    setDisplayDate(dateInput?.value ?? '');
    setDisplayTime(timeInput?.value ?? '');
    setPopoverOpen(false);
  };

  const handlePopoverOpenChange = (open: boolean) => {
    if (!open) {
      // Cancel any in-progress edit: reset the real inputs back to the last
      // committed display values so the form doesn't submit uncommitted data.
      const dateInput = getDateInput();
      const timeInput = getTimeInput();
      if (dateInput) dateInput.value = displayDate;
      if (timeInput) timeInput.value = displayTime;
    }
    setPopoverOpen(open);
  };

  const handleRemove = (e: React.MouseEvent) => {
    e.preventDefault();
    // Close the popover first so the portal is cleaned up before AJAX replaces the DOM.
    setPopoverOpen(false);

    if (!triggerRowRef.current) return;

    // Try to trigger the Drupal remove button.
    if (triggerDrupalRemoveButton(triggerRowRef.current)) {
      return;
    }

    // Fallback: no Drupal remove button, clear display.
    setDisplayDate('');
    setDisplayTime('');
  };

  // Build the combined display value.
  const combinedDisplayValue = (() => {
    if (!hasTime) {
      return formatDateForDisplay(displayDate) || 'Empty';
    }
    if (displayDate || displayTime) {
      return `${formatDateForDisplay(displayDate)}${displayDate && displayTime ? ', ' : ''}${formatTimeForDisplay(displayTime)}`;
    }
    return 'Empty';
  })();

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
              className={styles.listItem}
              type="button"
              aria-label={`Edit ${fieldLabel}: ${combinedDisplayValue}`}
            >
              <Text
                size="2"
                className={styles.itemText}
                data-canvas-multivalue-label
              >
                {combinedDisplayValue}
              </Text>
              <ArrowRightIcon className={styles.arrowIcon} />
            </button>
          </Popover.Trigger>
        </Flex>

        {/* Edit Popover — forceMount keeps the real inputs in the DOM for
            form submission even when the popover is closed. */}
        <Popover.Content
          forceMount={true}
          ref={setPopoverContainer}
          side="left"
          align="start"
          sideOffset={6}
          className={clsx(styles.popoverContent, [
            !popoverOpen && styles.visuallyHiddenInput,
          ])}
          onKeyDown={handleKeyDown}
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

          {/* Date and time inputs rendered by Drupal */}
          {children}

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

export default DrupalDatetimeMultivalueForm;
