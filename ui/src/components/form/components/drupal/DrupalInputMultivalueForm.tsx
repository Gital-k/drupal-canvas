import { useState } from 'react';
import { Cross2Icon, DragHandleDots2Icon, TrashIcon } from '@radix-ui/react-icons';
import { Box, Button, Flex, Popover, Text } from '@radix-ui/themes';
import InputBehaviors from '@/components/form/inputBehaviors';
import TextField from '@/components/form/components/TextField';
import type { Attributes } from '@/types/DrupalAttribute';
import { a2p } from '@/local_packages/utils';

import styles from './DrupalInputMultivalueForm.module.css';
import clsx from 'clsx';

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
  };
}) => {
  // Get the initial value from attributes
  const initialValue = attributes.value || attributes.defaultValue || '';
  // Manage local state to sync display value with input changes
  const [displayValue, setDisplayValue] = useState<string>(initialValue as string);
  const fieldLabel = 'Text';

  // Handle input changes - update both local state and call original onChange
  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const newValue = e.target.value;
    setDisplayValue(newValue);
  };


  return (
    <Popover.Root>
      <Flex align="center" gap="2" className={styles.itemRow}>
        {/* Drag Handle - Outside the clickable text area */}
        <Box className={styles.dragHandle}>
          <DragHandleDots2Icon />
        </Box>
        {/* List Item View - Trigger */}
        <Popover.Trigger asChild>
          <Box className={styles.listItem}>
            <Text size="2" className={styles.itemText}>
              {displayValue || 'Empty'}
            </Text>
          </Box>
        </Popover.Trigger>
      </Flex>

      {/* Edit Popover */}
      <Popover.Content
        side="bottom"
        align="start"
        sideOffset={4}
        className={styles.popoverContent}
      >
        {/* Popover Header */}
        <Flex justify="between" align="center" className={styles.popoverHeader}>
          <Text size="1" weight="medium" className={styles.popoverLabel}>
            {fieldLabel}
          </Text>
          <Popover.Close aria-label="Close" className={styles.closeButton}>
            <Cross2Icon />
          </Popover.Close>
        </Flex>

        {/* Input Field */}
        <Box className={styles.inputWrapper}>
          <TextFieldWithBehaviors
            className={clsx(attributes.class)}
            attributes={{...a2p(attributes, {}, { skipAttributes: ['class'] }), onPostChange: handleInputChange }}
          />
        </Box>

        {/* Remove Button */}
        <Flex justify="center" mt="3">
          <Popover.Close>
            <Button
              variant="ghost"
              color="red"
              size="1"
              onClick={() => {
                setDisplayValue('');
                if (attributes.onChange) {
                  const syntheticEvent = {
                    target: { value: '', name: attributes.name },
                  } as React.ChangeEvent<HTMLInputElement>;
                  attributes.onChange(syntheticEvent);
                }
              }}
              className={styles.removeButton}
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

