import { useRef, useState } from 'react';
import clsx from 'clsx';
import {
  closestCenter,
  DndContext,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import {
  arrayMove,
  SortableContext,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { DragHandleDots2Icon } from '@radix-ui/react-icons';

import type { DragEndEvent } from '@dnd-kit/core';

import styles from './DrupalMediaLibraryMultivalueWidget.module.css';

/**
 * Updates the hidden weight inputs for each .js-media-library-item child of
 * the given container.
 */
function updateWeights(container: HTMLElement): void {
  Array.from(
    container.querySelectorAll<HTMLElement>('.js-media-library-item'),
  ).forEach((item, index) => {
    const w =
      item.querySelector<HTMLInputElement>('.js-media-library-item-weight') ||
      item.querySelector<HTMLInputElement>('input[type="number"]');
    if (w) w.value = String(index);
  });
}

interface SortableItemProps {
  id: string;
  children: React.ReactNode;
  isDisabled: boolean;
}

function SortableItem({ id, children, isDisabled }: SortableItemProps) {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id, disabled: isDisabled });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
  };

  return (
    <div
      ref={setNodeRef}
      style={style}
      className={clsx(styles.item, { [styles.dragging]: isDragging })}
    >
      <div
        {...attributes}
        {...listeners}
        className={clsx(styles.dragHandle, { [styles.disabled]: isDisabled })}
        aria-label="Drag to reorder"
      >
        <DragHandleDots2Icon />
      </div>
      {children}
    </div>
  );
}

interface ChildItem {
  id: string;
  element: React.ReactElement;
}

/**
 * Returns true if the node is a React element (not a string/number/null).
 */
function isElement(node: React.ReactNode): node is React.ReactElement {
  return node !== null && typeof node === 'object' && 'type' in node;
}

interface DrupalMediaLibraryMultivalueWidgetProps {
  children?: React.ReactNode;
}

/**
 * Sortable wrapper for the Canvas multivalue media library widget.
 *
 * @see themes/canvas_stark/templates/media_library/container--media-library-widget-selection.html.twig
 * @see src/Hook/ReduxIntegratedFieldWidgetsHooks.php
 */
const DrupalMediaLibraryMultivalueWidget = ({
  children,
}: DrupalMediaLibraryMultivalueWidgetProps) => {
  const containerRef = useRef<HTMLDivElement>(null);

  const [items, setItems] = useState<ChildItem[]>(() => {
    const nodes = Array.isArray(children) ? children : [];
    return nodes
      .filter(isElement)
      .map((child, i) => ({ id: String(i), element: child }));
  });

  const sensors = useSensors(
    useSensor(PointerSensor),
    useSensor(KeyboardSensor),
  );

  const isDisabled = items.length <= 1;

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    if (!over || active.id === over.id) return;

    setItems((prev) => {
      const oldIndex = prev.findIndex((item) => item.id === active.id);
      const newIndex = prev.findIndex((item) => item.id === over.id);
      const next = arrayMove(prev, oldIndex, newIndex);

      requestAnimationFrame(() => {
        if (containerRef.current) {
          updateWeights(containerRef.current);
        }
      });

      return next;
    });
  };

  return (
    <div
      ref={containerRef}
      className="js-media-library-selection media-library-selection js-form-wrapper form-wrapper"
      data-canvas-media-library-selection="true"
    >
      <DndContext
        sensors={sensors}
        collisionDetection={closestCenter}
        onDragEnd={handleDragEnd}
      >
        <SortableContext
          items={items.map((item) => item.id)}
          strategy={verticalListSortingStrategy}
        >
          {items.map((item) => (
            <SortableItem key={item.id} id={item.id} isDisabled={isDisabled}>
              {item.element}
            </SortableItem>
          ))}
        </SortableContext>
      </DndContext>
    </div>
  );
};

export default DrupalMediaLibraryMultivalueWidget;
