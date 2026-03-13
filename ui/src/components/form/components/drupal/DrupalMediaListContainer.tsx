import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { useDndContext } from '@dnd-kit/core';
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';



import { useAppSelector } from '@/app/hooks';
import { toPropName } from '@/components/form/formUtil';
import { isEvaluatedComponentModel } from '@/features/layout/layoutModelSlice';
import { selectEditorFrameContext } from '@/features/ui/uiSlice';
import useInputUIData from '@/hooks/useInputUIData';
import { useUpdateComponentMutation } from '@/services/preview';



import type { ReactNode } from 'react';





interface SortableRegistrationContextType {
  registerItem: (id: string, element: any) => void;
  unregisterItem: (id: string) => void;
}

const SortableRegistrationContext =
  createContext<SortableRegistrationContextType | null>(null);

export const useSortableRegistration = () => {
  const context = useContext(SortableRegistrationContext);
  if (!context) {
    throw new Error(
      'useSortableRegistration must be used within DrupalMediaListContainer',
    );
  }
  return context;
};

interface DrupalMediaListContainerProps {
  children: ReactNode;
  onSort?: (newOrder: string[]) => void;
}

// Hook to track drag events from parent DndContext
function useDragMonitor(
  itemIds: string[],
  onDragOver: (newOrder: string[]) => void,
  onDragEnd: (newOrder: string[]) => void,
) {
  const { active, over } = useDndContext();

  const [lastActive, setLastActive] = useState<any>(null);
  const [lastOver, setLastOver] = useState<any>(null);
  const itemIdsRef = useRef<string[]>(itemIds);
  const hasDraggedRef = useRef(false);
  // Keep ref updated with latest itemIds
  useEffect(() => {
    itemIdsRef.current = itemIds;
  }, [itemIds]);

  // Detect drag start
  useEffect(() => {
    if (active && !lastActive) {
      setLastActive(active);
      setLastOver(null);
      hasDraggedRef.current = false;
    }
  }, [active, lastActive, itemIds]);

  // Update order in real-time during drag (visual feedback)
  useEffect(() => {
    if (active && over) {
      if (active.id !== over.id) {
        const currentOrder = itemIdsRef.current;
        const oldIndex = currentOrder.indexOf(active.id as string);
        const newIndex = currentOrder.indexOf(over.id as string);

        if (oldIndex !== -1 && newIndex !== -1 && oldIndex !== newIndex) {
          // Only update if the swap position changed
          if (lastOver?.id !== over.id) {
            const newOrder = [...currentOrder];
            const [removed] = newOrder.splice(oldIndex, 1);
            newOrder.splice(newIndex, 0, removed);
            onDragOver(newOrder);
            setLastOver(over);
            hasDraggedRef.current = true;
          }
        }
      }
    }
  }, [active, over, lastOver, onDragOver]);

  // Detect drag end and notify parent
  useEffect(() => {
    if (lastActive && !active) {
      const finalOrder = itemIdsRef.current;
      // console.log(`[DrupalMediaListContainer] 🎯 Drag ended`);
      // console.log(`[DrupalMediaListContainer] Final order:`, finalOrder);
      // console.log(`[DrupalMediaListContainer] Did reorder happen?`, hasDraggedRef.current);

      if (hasDraggedRef.current) {
        // console.log(`[DrupalMediaListContainer] 📤 Calling onDragEnd callback with final order:`, finalOrder);
        onDragEnd(finalOrder);
      }

      setLastActive(null);
      setLastOver(null);
      hasDraggedRef.current = false;
    }
  }, [active, lastActive, onDragEnd]);
}

const DrupalMediaListContainer = ({
  onSort,
  children,
}: DrupalMediaListContainerProps) => {
  // Store elements with injected IDs and track their order
  const [orderedElements, setOrderedElements] = useState<React.ReactElement[]>(
    [],
  );
  const [itemIds, setItemIds] = useState<string[]>([]);
  const elementIdMapRef = useRef<Map<React.ReactElement, string>>(new Map());
  const wrapperRef = useRef<HTMLDivElement>(null);
  const inputUIData = useInputUIData();
  const editorFrameContext = useAppSelector(selectEditorFrameContext);
  const { selectedComponent, selectedComponentType, version, model, components } =
    inputUIData || {};
  const [patchComponent] = useUpdateComponentMutation({
    fixedCacheKey: selectedComponent,
  });
  const selectedModel = useMemo(
    () => model?.[selectedComponent] || null,
    [model, selectedComponent],
  );
  // Initialize state with filtered children, cloning them with injected IDs
  useEffect(() => {
    const childrenArray = React.Children.toArray(children);
    const reactElements = childrenArray.filter(
      (child): child is React.ReactElement => React.isValidElement(child),
    );

    if (reactElements.length > 0 && orderedElements.length === 0) {
      // Clone elements and inject auto-generated IDs
      const elementsWithIds = reactElements.map((el, index) => {
        const autoId = `sortable-item-${index}-${Date.now()}`;
        elementIdMapRef.current.set(el, autoId);
        // Clone the element and inject the ID as a prop
        return React.cloneElement(el, { id: autoId, itemIndex: index } as any);
      });

      const ids = elementsWithIds.map((el) => el.props.id);

      setOrderedElements(elementsWithIds);
      setItemIds(ids);
    }
  }, [children, orderedElements.length]);

  // Handle sort by reordering the elements in state
  const handleSort = useCallback((newOrder: string[]) => {
    setOrderedElements((prev) => {
      // Create a map of id -> element
      const elementMap = new Map<string, React.ReactElement>();
      prev.forEach((el) => {
        const id = el.props.id;
        if (id) {
          elementMap.set(id, el);
        }
      });

      // Reorder based on newOrder
      const reordered = newOrder
        .map((id) => elementMap.get(id))
        .filter((el): el is React.ReactElement => el !== undefined);

      // console.log(`[DrupalMediaListContainer] ✅ Reordered ${reordered.length} elements`);
      return reordered;
    });

    // Update itemIds to match the new order
    setItemIds(newOrder);
  }, []);

  useEffect(() => {
    console.log('coco', components[selectedComponentType]);
    if (!wrapperRef.current) {
      return;
    }
    const isComponent = wrapperRef.current.closest(
      '[data-form-id="component_instance_form"]',
    );
    const handleRemove = (e: Event) => {
      if (!wrapperRef.current) {
        return;
      }
      const componentData = components?.[selectedComponentType];
      const propName = toPropName(
        wrapperRef.current.querySelector('[name*="target_id"]').name,
        selectedComponent,
      );
      console.log('componrnt data ' + propName, componentData);
      if (!componentData || !componentData?.propSources?.[propName]) {
        return;
      }
      const isRequired = !!componentData?.propSources?.[propName];
      console.log(
        'wow',
        componentData.propSources[propName].default_values.resolved,
      );

      if (selectedModel && isEvaluatedComponentModel(selectedModel)) {
        const newValue = isRequired
          ? componentData.propSources[propName].default_values.resolved
          : [];
        const source = isRequired
          ? selectedModel.source
          : {
              ...selectedModel?.source,
              [propName]: {
                ...selectedModel.source[propName],
                value: null,
              },
            };
        patchComponent({
          type: editorFrameContext,
          componentInstanceUuid: selectedComponent,
          componentType: `${selectedComponentType}@${version}`,
          model: {
            source,
            resolved: {
              ...selectedModel.resolved,
              [propName]: newValue,
            },
          },
        });
      }
    }
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault(); // Prevent default space scrolling
        handleRemove(e);
      }
    };
    console.log(`NUM ITEM IDS for component ${!!isComponent}`, itemIds.length);
    if (itemIds.length === 1 && wrapperRef.current && !!isComponent) {
      const removeButton = wrapperRef.current.querySelector('[data-canvas-media-remove-button]');
      console.log('REMOVE BUTTON', removeButton);
      if (removeButton) {
        console.log('Adding event listener to remove button', removeButton);
        removeButton.addEventListener('mousedown', handleRemove, { capture: true });
        removeButton.addEventListener('keydown', handleKeyDown);
      }

    }
  }, [itemIds]);

  // Final callback for when drag ends
  const handleDragEnd = useCallback(
    (newOrder: string[]) => {
      onSort?.(newOrder);
      if (!wrapperRef.current) {
        return;
      }
      // @ts-ignore
      const targetIds: string[] = [];
      let propName = '';
      [...wrapperRef.current.querySelectorAll('[name*="target_id"]')].forEach(
        (el: Element) => {
          const formEl = el as HTMLFormElement;
          propName = formEl.name && toPropName(formEl.name, selectedComponent);
          targetIds.push(formEl.getAttribute('value') || '');
        },
      );
      if (
        wrapperRef.current.closest(
          '[data-form-id="component_instance_form"]',
        ) &&
        propName
      ) {
        if (selectedModel && isEvaluatedComponentModel(selectedModel)) {
          patchComponent({
            type: editorFrameContext,
            componentInstanceUuid: selectedComponent,
            componentType: `${selectedComponentType}@${version}`,
            model: {
              source: {
                ...selectedModel.source,
                [propName]: {
                  ...selectedModel.source[propName],
                  value: targetIds,
                },
              },
              resolved: {
                ...selectedModel.resolved,
                [propName]: targetIds,
              },
            },
          });
        }
      }
      wrapperRef.current
        .querySelectorAll('[data-canvas-media-weight]')
        .forEach((el, index) => {
          el.setAttribute('value', index.toString());
        });
    },
    [
      editorFrameContext,
      onSort,
      patchComponent,
      selectedComponent,
      selectedComponentType,
      selectedModel,
      version,
    ],
  );

  useDragMonitor(itemIds, handleSort, handleDragEnd);

  return (
    <SortableRegistrationContext.Provider
      value={{ registerItem: () => {}, unregisterItem: () => {} }}
    >
      <SortableContext items={itemIds} strategy={verticalListSortingStrategy}>
        <div ref={wrapperRef} data-sort-wrapper style={{ display: 'contents' }}>
          {orderedElements}
        </div>
      </SortableContext>
    </SortableRegistrationContext.Provider>
  );
};

export default DrupalMediaListContainer;
