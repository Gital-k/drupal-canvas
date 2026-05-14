import { useEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import { ArrowRightIcon, Cross2Icon, TrashIcon } from '@radix-ui/react-icons';
import * as Popover from '@radix-ui/react-popover';
import { Button, Flex, Text } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import DrupalInput from '@/components/form/components/drupal/DrupalInput';
import { toPropName } from '@/components/form/formUtil';
import { removeFieldValue } from '@/features/form/formStateSlice';
import { isEvaluatedComponentModel } from '@/features/layout/layoutModelSlice';
import { selectEditorFrameContext } from '@/features/ui/uiSlice';
import useInputUIData from '@/hooks/useInputUIData';
import { useUpdateComponentMutation } from '@/services/preview';

import {
  buildModelWithItemRemoved,
  extractPositionFromFieldName,
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
  const dispatch = useAppDispatch();
  const inputUIData = useInputUIData();
  const [patchComponent] = useUpdateComponentMutation({
    fixedCacheKey: inputUIData?.selectedComponent || '-',
  });
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  const isAutocomplete =
    attributes?.class instanceof Array &&
    attributes?.class?.includes('form-autocomplete');
  const initialValue = attributes.value ?? attributes.defaultValue ?? '';
  const triggerRowRef = useRef<HTMLDivElement | null>(null);
  const triggerButtonRef = useRef<HTMLButtonElement | null>(null);
  const [popoverOpen, setPopoverOpen] = useState(false);
  const [popoverContainer, setPopoverContainer] =
    useState<HTMLDivElement | null>(null);
  const [displayValue, setDisplayValue] = useState<string>(
    String(initialValue ?? ''),
  );

  const fieldLabel = attributes['data-field-label'] || '';
  const displayText = displayValue === '' ? 'Empty' : String(displayValue);

  const hasError = () =>
    popoverContainer &&
    !!popoverContainer.querySelector('[data-has-field-error="true"]');

  const setPopoverOpenAndRefocus = (open: boolean) => {
    setPopoverOpen(open);
    if (!open) {
      setTimeout(() => triggerButtonRef.current?.focus(), 30);
    }
  };

  useEffect(() => {
    // Listen for the custom autocomplete-selected event dispatched by
    // autocomplete.extend.js when the user picks a suggestion.
    const input = popoverContainer?.querySelector(
      'input',
    ) as HTMLInputElement | null;
    if (
      !isAutocomplete ||
      !input ||
      !popoverOpen ||
      (input as any).__listenersAdded
    )
      return;
    (input as any).__listenersAdded = true;
    input.addEventListener('data-canvas-autocomplete-selected', (e: Event) => {
      const customEvent = e as CustomEvent<{ value: string }>;
      setTimeout(() => {
        setDisplayValue(customEvent.detail.value);
        setPopoverOpenAndRefocus(false);
      });
    });
  }, [isAutocomplete, popoverContainer, popoverOpen]);

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Escape' || e.key === 'Enter') {
      handlePopoverOpenChange(false);
      e.preventDefault();
      return;
    }
  };

  const handlePopoverOpenChange = (open: boolean) => {
    if (open) {
      setTimeout(() => {
        const input = popoverContainer?.querySelector(
          'input',
        ) as HTMLInputElement | null;
        input?.select();
      }, 0);
    }
    if (!hasError()) {
      setPopoverOpenAndRefocus(open);
    }
  };

  const handleRemove = (e: React.MouseEvent) => {
    e.preventDefault();
    const triggerElement = triggerRowRef.current;
    if (!triggerElement) return;
    setPopoverOpenAndRefocus(false);
    setTimeout(() => {
      const formId = attributes['data-form-id'] as string | undefined;
      const { name } = attributes;

      // First call: pass formId so the click is suppressed for
      // component_instance_form, but the button name is still returned.
      const removeButtonName: string | null = triggerDrupalRemoveButton(
        triggerElement,
        formId,
      );

      // If we successfully triggered the remove button and have the necessary
      // data, dispatch removeFieldValue.
      if (removeButtonName && formId && name) {
        const fieldNames: string[] = [name];

        // Add the _weight field (replace [value] with [_weight])
        if (name.includes('[value]')) {
          fieldNames.push(name.replace('[value]', '[_weight]'));
        }

        // Add the remove button name
        fieldNames.push(removeButtonName);

        // If this is a component, update the model to reflect the removal
        // immediately, then fire the actual Drupal AJAX click.
        if (formId === 'component_instance_form') {
          const propName = toPropName(name, inputUIData.selectedComponent);
          const position = extractPositionFromFieldName(name, propName);
          const model = inputUIData.model?.[inputUIData.selectedComponent];
          if (!model || !isEvaluatedComponentModel(model)) {
            return;
          }
          patchComponent({
            type: editorFrameContext,
            componentInstanceUuid: inputUIData.selectedComponent,
            componentType: `${inputUIData.selectedComponentType}@${inputUIData.version}`,
            model: buildModelWithItemRemoved(model, propName, position ?? 0),
          });
          // Second call: now that the model is patched, invoke the real
          // Drupal AJAX click (no formId passed so the click is not suppressed).
          setTimeout(() => triggerDrupalRemoveButton(triggerElement));
        }

        dispatch(
          removeFieldValue({
            formId: formId as any,
            fieldName: fieldNames,
          }),
        );
      }
    });
  };

  return (
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
            data-multivalue-popover-trigger
            ref={triggerButtonRef}
            className={styles.listItem}
            type="button"
            aria-label={`Edit ${fieldLabel}: ${displayText}`}
          >
            <Text
              size="2"
              className={styles.itemText}
              data-canvas-multivalue-label
            >
              {displayText}
            </Text>
            <ArrowRightIcon className={styles.arrowIcon} />
          </button>
        </Popover.Trigger>
      </Flex>

      <Popover.Content
        forceMount={true}
        ref={setPopoverContainer}
        side="left"
        align="start"
        sideOffset={36}
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
        <Flex justify="between" align="center" className={styles.popoverHeader}>
          <Text size="1" weight="medium" className={styles.popoverLabel}>
            {fieldLabel}
          </Text>
          <Popover.Close aria-label="Close">
            <Cross2Icon />
          </Popover.Close>
        </Flex>
        <DrupalInput
          attributes={{
            ...attributes,
            onKeyDown: handleKeyDown,
            onInput: (e: any) => {
              setTimeout(() => {
                if (!hasError()) setDisplayValue(e.target.value);
              });
            },
          }}
        />
        <Flex justify="center" className={styles.removeButtonContainer}>
          <Button
            data-multivalue-remove-item="true"
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
  );
};

export default DrupalInputMultivalueForm;
