import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { Box } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { useComponentTransforms } from '@/components/ComponentInstanceForm';
import { getPropsValues } from '@/components/form/formUtil';
import { syncPropSourcesToResolvedValues } from '@/components/form/InputBehaviorsComponentPropsForm';
import { FORM_TYPES } from '@/features/form/constants';
import {
  selectFormValues,
  setFieldValue,
} from '@/features/form/formStateSlice';
import { isEvaluatedComponentModel } from '@/features/layout/layoutModelSlice';
import {
  selectEditorFrameContext,
  selectSelectedComponentUuid,
} from '@/features/ui/uiSlice';
import useInputUIData from '@/hooks/useInputUIData';
import { useGetComponentsQuery } from '@/services/componentAndLayout';
import { useUpdateComponentMutation } from '@/services/preview';
import { isPropSourceComponent } from '@/types/Component';

import FieldItemEditPanel from './FieldItemEditPanel';
import FieldItemList from './FieldItemList';
import { parseFieldItems, updateItemWeights } from './fieldItemUtils';

import type { FieldItem } from './types';
import type { PropSourceComponent } from '@/types/Component';

import styles from './FieldItemEditor.module.css';

interface FieldItemEditorProps {
  fieldName: string;
  fieldLabel?: string;
  cardinality: number;
  isRequired: boolean;
}

export default function FieldItemEditor({
  fieldName,
  fieldLabel,
  cardinality,
  isRequired,
}: FieldItemEditorProps) {
  const dispatch = useAppDispatch();
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  const [selectedIndex, setSelectedIndex] = useState<number | null>(null);
  const [portalContainer, setPortalContainer] = useState<HTMLElement | null>(
    null,
  );
  const [tableElement, setTableElement] = useState<HTMLElement | null>(null);
  const containerRef = useRef<HTMLDivElement>(null);
  const editPanelContentRef = useRef<HTMLDivElement>(null);
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const formState = useAppSelector((state) =>
    selectFormValues(state, FORM_TYPES.COMPONENT_INSTANCE_FORM),
  );
  const { data: components } = useGetComponentsQuery();
  const transforms = useComponentTransforms();
  const inputAndUiData = useInputUIData();
  const { selectedComponentType, version } = inputAndUiData;
  const component = components?.[selectedComponentType] as PropSourceComponent;
  const [patchComponent] = useUpdateComponentMutation({
    fixedCacheKey: selectedComponent,
  });

  const [items, setItems] = useState<FieldItem[]>([]);

  useEffect(() => {
    if (selectedComponent && fieldName) {
      const parsedItems = parseFieldItems(
        formState,
        fieldName,
        selectedComponent,
      );
      setItems(parsedItems);
    }
  }, [formState, fieldName, selectedComponent]);

  useEffect(() => {
    if (containerRef.current) {
      const formItem = containerRef.current.closest('.js-form-item');
      if (formItem) {
        const parent = formItem.parentElement?.closest('.js-form-item');

        if (parent) {
          let portal = parent.querySelector('.field-item-editor-portal');
          if (!portal) {
            portal = document.createElement('div');
            portal.className = 'field-item-editor-portal';
            parent.insertBefore(portal, parent.firstChild);
          }
          setPortalContainer(portal as HTMLElement);

          parent.classList.add(styles.hasFieldEditor);

          const table = parent.querySelector('table') as HTMLElement;
          if (table) {
            setTableElement(table);
            table.style.display = 'none';
          }
          const addButton = parent.querySelector('.clearfix');
          if (addButton) {
            (addButton as HTMLElement).style.display = 'none';
          }
        } else {
          let portal = formItem.querySelector('.field-item-editor-portal');
          if (!portal) {
            portal = document.createElement('div');
            portal.className = 'field-item-editor-portal';
            const label = formItem.querySelector('label');
            if (label) {
              label.insertAdjacentElement('afterend', portal);
            } else {
              formItem.insertBefore(portal, formItem.firstChild);
            }
          }
          setPortalContainer(portal as HTMLElement);

          formItem.classList.add(styles.hasFieldEditor);

          const inputElement = formItem.querySelector(
            'input[type="text"], textarea',
          ) as HTMLElement;
          if (inputElement) {
            setTableElement(formItem as HTMLElement);
            inputElement.style.display = 'none';
          }
        }
      }
    }
  }, []);

  useEffect(() => {
    if (!tableElement || !editPanelContentRef.current) return;

    const contentDiv = editPanelContentRef.current;

    if (selectedIndex !== null && items[selectedIndex]) {
      const selectedDelta = items[selectedIndex].delta;

      let inputElement: HTMLElement | null = null;

      if (cardinality === 1) {
        inputElement = tableElement.querySelector(
          `input[name*="[${fieldName}][value]"], textarea[name*="[${fieldName}][value]"]`,
        ) as HTMLElement;
      } else {
        inputElement = tableElement.querySelector(
          `input[name*="[${selectedDelta}][value]"], textarea[name*="[${selectedDelta}][value]"]`,
        ) as HTMLElement;
      }

      if (inputElement) {
        contentDiv.innerHTML = '';

        const clonedInput = inputElement.cloneNode(true) as
          | HTMLInputElement
          | HTMLTextAreaElement;
        clonedInput.style.display = 'block';
        clonedInput.style.width = '100%';
        clonedInput.style.padding = '8px 12px';
        clonedInput.style.border = '1px solid var(--gray-a6)';
        clonedInput.style.borderRadius = 'var(--radius-2)';
        clonedInput.style.fontSize = '14px';
        clonedInput.style.boxSizing = 'border-box';
        clonedInput.style.fontFamily = 'inherit';

        clonedInput.value = (inputElement as HTMLInputElement).value;

        clonedInput.addEventListener('input', (e) => {
          const target = e.target as HTMLInputElement;
          (inputElement as HTMLInputElement).value = target.value;
          inputElement.dispatchEvent(new Event('input', { bubbles: true }));
          inputElement.dispatchEvent(new Event('change', { bubbles: true }));
        });

        contentDiv.appendChild(clonedInput);
      }
    } else {
      contentDiv.innerHTML = '';
    }
  }, [selectedIndex, tableElement, items, cardinality, fieldName]);

  const triggerFormUpdate = useCallback(() => {
    if (!selectedComponent || !component) return;

    const { propsValues: values, selectedModel } = getPropsValues(
      formState,
      inputAndUiData,
      transforms,
    );

    const resolved = { ...selectedModel.resolved, ...values };

    if (isEvaluatedComponentModel(selectedModel) && component) {
      patchComponent({
        type: editorFrameContext,
        componentInstanceUuid: selectedComponent,
        componentType: `${selectedComponentType}@${version}`,
        model: {
          source: syncPropSourcesToResolvedValues(
            selectedModel.source,
            component,
            resolved,
          ),
          resolved,
        },
      });
    } else {
      patchComponent({
        type: editorFrameContext,
        componentInstanceUuid: selectedComponent,
        componentType: `${selectedComponentType}@${version}`,
        model: {
          ...selectedModel,
          resolved,
        },
      });
    }
  }, [
    selectedComponent,
    component,
    formState,
    inputAndUiData,
    transforms,
    patchComponent,
    editorFrameContext,
    selectedComponentType,
    version,
  ]);

  const handleItemClick = (index: number) => {
    setSelectedIndex(selectedIndex === index ? null : index);
  };

  const handleClose = () => {
    setSelectedIndex(null);
  };

  const handleReorder = (oldIndex: number, newIndex: number) => {
    if (!selectedComponent || !tableElement) return;

    const reorderedItems = [...items];
    const [movedItem] = reorderedItems.splice(oldIndex, 1);
    reorderedItems.splice(newIndex, 0, movedItem);

    const updatedItems = updateItemWeights(reorderedItems);

    updatedItems.forEach((item, newIdx) => {
      const weightFieldName = `canvas_component_props[${selectedComponent}][${fieldName}][${item.delta}][_weight]`;

      dispatch(
        setFieldValue({
          formId: FORM_TYPES.COMPONENT_INSTANCE_FORM,
          fieldName: weightFieldName,
          value: String(newIdx),
        }),
      );

      const weightInput = tableElement.querySelector(
        `input[name*="[${item.delta}][_weight]"]`,
      ) as HTMLInputElement;
      if (weightInput) {
        weightInput.value = String(newIdx);
      }
    });

    setTimeout(() => {
      triggerFormUpdate();
    }, 50);

    if (selectedIndex === oldIndex) {
      setSelectedIndex(newIndex);
    } else if (selectedIndex !== null) {
      if (oldIndex < selectedIndex && newIndex >= selectedIndex) {
        setSelectedIndex(selectedIndex - 1);
      } else if (oldIndex > selectedIndex && newIndex <= selectedIndex) {
        setSelectedIndex(selectedIndex + 1);
      }
    }
  };

  const handleAdd = () => {
    if (!selectedComponent || !tableElement) return;

    const newDelta = items.length > 0 ? Math.max(...items.map((i) => i.delta)) + 1 : 0;

    const tbody = tableElement.querySelector('tbody');
    if (tbody) {
      const existingRow = tableElement.querySelector('tr:has(input, textarea)');
      if (existingRow) {
        const newRow = existingRow.cloneNode(true) as HTMLElement;

        newRow.querySelectorAll('input, textarea').forEach((input) => {
          const inputEl = input as HTMLInputElement | HTMLTextAreaElement;
          const oldName = inputEl.name;
          const newName = oldName.replace(/\[\d+\](?=[^\[]*\[(?:value|_weight)\])/, `[${newDelta}]`);
          inputEl.name = newName;
          inputEl.value = inputEl.name.includes('_weight') ? String(items.length) : '';
          const oldId = inputEl.id || '';
          inputEl.id = oldId.replace(/-\d+-/, `-${newDelta}-`);
        });

        const label = newRow.querySelector('label');
        if (label && label.htmlFor) {
          label.htmlFor = label.htmlFor.replace(/-\d+-/, `-${newDelta}-`);
        }

        tbody.appendChild(newRow);
      }
    }

    const valueFieldName = `canvas_component_props[${selectedComponent}][${fieldName}][${newDelta}][value]`;
    dispatch(
      setFieldValue({
        formId: FORM_TYPES.COMPONENT_INSTANCE_FORM,
        fieldName: valueFieldName,
        value: '',
      }),
    );

    const weightFieldName = `canvas_component_props[${selectedComponent}][${fieldName}][${newDelta}][_weight]`;
    dispatch(
      setFieldValue({
        formId: FORM_TYPES.COMPONENT_INSTANCE_FORM,
        fieldName: weightFieldName,
        value: String(items.length),
      }),
    );

    setTimeout(() => {
      triggerFormUpdate();
    }, 50);

    setSelectedIndex(items.length);
  };

  const handleRemove = () => {
    if (selectedIndex === null || !selectedComponent || !tableElement) return;

    const itemToRemove = items[selectedIndex];
    if (!itemToRemove) return;

    const rowToRemove = tableElement.querySelector(
      `tr:has(input[name*="[${itemToRemove.delta}][value]"])`,
    ) as HTMLElement;
    if (rowToRemove) {
      rowToRemove.remove();
    }

    const valueFieldName = `canvas_component_props[${selectedComponent}][${fieldName}][${itemToRemove.delta}][value]`;
    dispatch(
      setFieldValue({
        formId: FORM_TYPES.COMPONENT_INSTANCE_FORM,
        fieldName: valueFieldName,
        value: '',
      }),
    );

    const weightFieldName = `canvas_component_props[${selectedComponent}][${fieldName}][${itemToRemove.delta}][_weight]`;
    dispatch(
      setFieldValue({
        formId: FORM_TYPES.COMPONENT_INSTANCE_FORM,
        fieldName: weightFieldName,
        value: '',
      }),
    );

    const updatedItems = items.filter((_, index) => index !== selectedIndex);
    const reindexed = updateItemWeights(updatedItems);

    reindexed.forEach((item, newIdx) => {
      const weightFieldName = `canvas_component_props[${selectedComponent}][${fieldName}][${item.delta}][_weight]`;
      dispatch(
        setFieldValue({
          formId: FORM_TYPES.COMPONENT_INSTANCE_FORM,
          fieldName: weightFieldName,
          value: String(newIdx),
        }),
      );

      const weightInput = tableElement.querySelector(
        `input[name*="[${item.delta}][_weight]"]`,
      ) as HTMLInputElement;
      if (weightInput) {
        weightInput.value = String(newIdx);
      }
    });

    setTimeout(() => {
      triggerFormUpdate();
    }, 50);

    setSelectedIndex(null);
  };

  const content = (
    <Box className={styles.container}>
      <FieldItemList
        items={items}
        cardinality={cardinality}
        onItemClick={handleItemClick}
        onReorder={handleReorder}
        onAdd={handleAdd}
        selectedIndex={selectedIndex}
      />

      <FieldItemEditPanel
        isOpen={selectedIndex !== null}
        selectedIndex={selectedIndex}
        onClose={handleClose}
        onRemove={handleRemove}
        isRequired={isRequired}
        cardinality={cardinality}
        fieldLabel={fieldLabel}
      >
        <div ref={editPanelContentRef} />
      </FieldItemEditPanel>
    </Box>
  );

  return (
    <div ref={containerRef} style={{ display: 'none' }}>
      {portalContainer ? createPortal(content, portalContainer) : null}
    </div>
  );
}
