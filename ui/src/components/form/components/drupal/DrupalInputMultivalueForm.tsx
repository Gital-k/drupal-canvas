import { useRef, useState } from 'react';
import clsx from 'clsx';
import { ArrowRightIcon, Cross2Icon, TrashIcon } from '@radix-ui/react-icons';
import { Box, Button, Flex, Popover, Text } from '@radix-ui/themes';

import TextField from '@/components/form/components/TextField';
import InputBehaviors from '@/components/form/inputBehaviors';
import { a2p } from '@/local_packages/utils';

import type { Attributes } from '@/types/DrupalAttribute';

import styles from './DrupalInputMultivalueForm.module.css';

// Create the wrapped TextField component with InputBehaviors HOC.
const TextFieldWithBehaviors = InputBehaviors(TextField);

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
  attributes?: Attributes & {
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
  const inputWrapperRef = useRef<HTMLDivElement | null>(null);
  // Ref for the trigger area that lives inside the actual table row.
  const triggerRowRef = useRef<HTMLDivElement | null>(null);
  // Controlled popover state so we can close it programmatically on remove.
  const [popoverOpen, setPopoverOpen] = useState(false);
  const fieldLabel = attributes['data-field-label'] || '';

  // Handle input changes - update both local state and call original onChange
  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const newValue = e.target.value;
    setDisplayValue(newValue);
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
    // tabledrag-icons.js but remain in the DOM.
    const removeActionCell = tableRow.querySelector('.canvas-remove-action');
    if (removeActionCell) {
      const removeButton = removeActionCell.querySelector(
        'input[type="submit"][name*="remove"], ' +
          'button[name*="remove"], ' +
          '.remove-button, ' +
          '[data-drupal-selector*="remove-button"]',
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

    // Last resort fallback: clear the input value locally.
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
    <Popover.Root open={popoverOpen} onOpenChange={setPopoverOpen}>
      <Flex
        ref={triggerRowRef}
        align="center"
        gap="2"
        className={styles.itemRow}
      >
        {/* List Item View - Trigger */}
        <Popover.Trigger>
          <Box className={styles.listItem}>
            <Text size="2" className={styles.itemText}>
              {displayValue || 'Empty'}
            </Text>
            <ArrowRightIcon className={styles.arrowIcon} />
          </Box>
        </Popover.Trigger>
      </Flex>

      {/* Edit Popover */}
      <Popover.Content
        side="left"
        align="start"
        sideOffset={6}
        className={styles.popoverContent}
        style={{ maxWidth: '232px' }}
      >
        {/* Popover Header */}
        <Flex justify="between" align="center" className={styles.popoverHeader}>
          <Text size="1" weight="medium" className={styles.popoverLabel}>
            {fieldLabel}
          </Text>
          <Popover.Close aria-label="Close">
            <Cross2Icon />
          </Popover.Close>
        </Flex>

        {/* Input Field */}
        <Box ref={inputWrapperRef}>
          <TextFieldWithBehaviors
            {...{ className: clsx(attributes.class) }}
            {...{
              attributes: {
                ...a2p(attributes, {}, { skipAttributes: ['class'] }),
                onPostChange: handleInputChange,
              },
            }}
          />
        </Box>

        {/* Remove Button */}
        <Flex justify="center">
          <Button variant="ghost" color="red" size="1" onClick={handleRemove}>
            <TrashIcon />
            Remove
          </Button>
        </Flex>
      </Popover.Content>
    </Popover.Root>
  );
};

export default DrupalInputMultivalueForm;
