import { useMemo } from 'react';
import {
  InfoCircledIcon,
  QuestionMarkCircledIcon,
} from '@radix-ui/react-icons';
import { Box, Callout, Flex, Grid, TextField, Tooltip } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  addSlot,
  removeSlot,
  reorderSlots,
  selectCodeComponentProperty,
  selectInitialSlotIds,
  updateSlot,
} from '@/features/code-editor/codeEditorSlice';
import {
  FormElement,
  Label,
} from '@/features/code-editor/component-data/FormElement';
import FormPropTypeSlot from '@/features/code-editor/component-data/forms/FormPropTypeSlot';
import SortableList from '@/features/code-editor/component-data/SortableList';

import './Slots.css';

import {
  getDuplicateSlotSchemaKeys,
  getSlotSchemaKey,
} from '@/features/code-editor/utils/utils';

import type { CodeComponentSlot } from '@/types/CodeComponent';

export default function Slots() {
  const dispatch = useAppDispatch();
  const slots = useAppSelector(selectCodeComponentProperty('slots'));
  const componentStatus = useAppSelector(selectCodeComponentProperty('status'));
  const initialSlotIds = useAppSelector(selectInitialSlotIds);

  // Memoized Set of slot IDs that need to be disabled from editing name.
  const disabledSlotIds = useMemo(() => {
    if (!componentStatus) return new Set<string>();
    return new Set(initialSlotIds);
  }, [componentStatus, initialSlotIds]);

  const duplicateSlotSchemaKeys = useMemo(
    () => getDuplicateSlotSchemaKeys(slots),
    [slots],
  );

  const handleAddSlot = () => {
    dispatch(addSlot());
  };

  const handleRemoveSlot = (slotId: string) => {
    dispatch(removeSlot({ slotId }));
  };

  const handleReorder = (oldIndex: number, newIndex: number) => {
    dispatch(reorderSlots({ oldIndex, newIndex }));
  };

  const renderSlotContent = (slot: CodeComponentSlot) => {
    const defaultKeyHint = slot.name
      ? getSlotSchemaKey({ ...slot, machineName: '' })
      : '';
    return (
      <Flex direction="column" flexGrow="1" gap="4">
        <Grid columns={{ initial: '1', sm: '2' }} gap="4" width="100%">
          <Box minWidth="0" width="100%">
            <FormElement>
              <Flex align="center" className="slots-form-label-row">
                <Label htmlFor={`slot-name-${slot.id}`}>Title</Label>
              </Flex>
              <TextField.Root
                autoComplete="off"
                id={`slot-name-${slot.id}`}
                placeholder="Enter a label"
                value={slot.name}
                size="1"
                className="slots-field-full-width"
                onChange={(e) =>
                  dispatch(
                    updateSlot({
                      id: slot.id,
                      updates: { name: e.target.value },
                    }),
                  )
                }
                disabled={disabledSlotIds.has(slot.id)}
              />
            </FormElement>
          </Box>
          <Box minWidth="0" width="100%">
            <FormElement>
              <Flex align="center" gap="1" className="slots-form-label-row">
                <Label htmlFor={`slot-machine-name-${slot.id}`}>
                  Machine name
                </Label>
                <Tooltip content="The key used for this slot in config. If empty, it is derived from the title.">
                  <QuestionMarkCircledIcon aria-label="About slot machine name" />
                </Tooltip>
              </Flex>
              <TextField.Root
                autoComplete="off"
                id={`slot-machine-name-${slot.id}`}
                placeholder={
                  slot.name ? `Default: ${defaultKeyHint}` : 'Set title first'
                }
                value={slot.machineName ?? ''}
                size="1"
                className="slots-field-full-width"
                onChange={(e) =>
                  dispatch(
                    updateSlot({
                      id: slot.id,
                      updates: { machineName: e.target.value },
                    }),
                  )
                }
                disabled={disabledSlotIds.has(slot.id)}
              />
            </FormElement>
          </Box>
        </Grid>
        <FormPropTypeSlot id={slot.id} example={slot.example} />
      </Flex>
    );
  };

  return (
    <>
      <Box flexGrow="1" pt="4" maxWidth="500px" mx="auto">
        <Callout.Root size="1" variant="surface" color="gray">
          <Callout.Icon>
            <QuestionMarkCircledIcon />
          </Callout.Icon>
          <Callout.Text>
            Slots allow you to place other components inside of your component.
          </Callout.Text>
        </Callout.Root>
      </Box>
      {/* Show a callout to inform the user the slot name is locked if there
       are any slot ids disabled from editing. */}
      {disabledSlotIds.size > 0 && (
        <Box flexGrow="1" pt="4" maxWidth="500px" mx="auto">
          <Callout.Root size="1" variant="surface">
            <Callout.Icon>
              <InfoCircledIcon />
            </Callout.Icon>
            <Callout.Text>
              Changing the name of an existing slot is not allowed when a
              component is added to <b>Components</b> in the Library. Remove
              slot and create a new one instead.
            </Callout.Text>
          </Callout.Root>
        </Box>
      )}
      {duplicateSlotSchemaKeys.length > 0 && (
        <Box flexGrow="1" pt="4" maxWidth="500px" mx="auto">
          <Callout.Root size="1" variant="surface" color="red">
            <Callout.Icon>
              <InfoCircledIcon />
            </Callout.Icon>
            <Callout.Text>
              Two or more slots use the same configuration key (
              {duplicateSlotSchemaKeys.join(', ')}). The first slot with each
              key is what gets saved; give each slot a unique machine name (or
              title) before the component can compile or auto-save.
            </Callout.Text>
          </Callout.Root>
        </Box>
      )}
      <SortableList
        items={slots}
        onAdd={handleAddSlot}
        onReorder={handleReorder}
        onRemove={handleRemoveSlot}
        renderContent={renderSlotContent}
        getItemId={(item) => item.id}
        data-testid="slot"
        moveAriaLabel="Move slot"
        removeAriaLabel="Remove slot"
      />
    </>
  );
}
