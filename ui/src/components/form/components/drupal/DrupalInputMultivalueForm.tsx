import { useRef, useState } from 'react';
import clsx from 'clsx';
import {
  ArrowRightIcon,
  Cross2Icon,
  DragHandleDots2Icon,
  TrashIcon,
} from '@radix-ui/react-icons';
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
  const fieldLabel = attributes['data-field-label'] || '';

  // Handle input changes - update both local state and call original onChange
  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const newValue = e.target.value;
    setDisplayValue(newValue);
  };

  // Handle remove - triggers change through the input element to use InputBehaviors
  const handleRemove = () => {
    setDisplayValue('');
    // Find the input element within the wrapper and trigger change through it
    if (!inputWrapperRef.current) return;

    const inputElement = inputWrapperRef.current.querySelector(
      'input',
    ) as HTMLInputElement | null;
    if (inputElement) {
      // Set the native value using the native setter to properly trigger React's onChange
      const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
        HTMLInputElement.prototype,
        'value',
      )?.set;
      nativeInputValueSetter?.call(inputElement, '');
      // Dispatch input event to trigger the InputBehaviors onChange handler
      const event = new Event('input', { bubbles: true });
      inputElement.dispatchEvent(event);
    }
  };

  return (
    <Popover.Root>
      <Flex align="center" gap="2" className={styles.itemRow}>
        {/* Drag Handle - Outside the clickable text area */}
        <Box className={styles.dragHandle}>
          <DragHandleDots2Icon />
        </Box>
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
        sideOffset={4}
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
          <Popover.Close>
            <Button
              variant="ghost"
              color="red"
              size="1"
              onClick={handleRemove}
            >
              <TrashIcon />
              Remove
            </Button>
          </Popover.Close>
        </Flex>
      </Popover.Content>
    </Popover.Root>
  );
};

export default DrupalInputMultivalueForm;
