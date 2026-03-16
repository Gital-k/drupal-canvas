import { useCallback, useContext, useId, useRef } from 'react';
import clsx from 'clsx';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { DragHandleDots2Icon } from '@radix-ui/react-icons';

import { a2p } from '@/local_packages/utils';
import { SortableRegistrationContext } from '@/components/form/components/drupal/DrupalMediaListContainer';

import type { ReactNode } from 'react';

import styles from './DrupalMediaLibraryItem.module.css';

interface DrupalMediaLibraryItemProps {
  id?: string; // Optional: unique ID for sorting (will be auto-generated if not provided)
  children: ReactNode;
  attributes?: Record<string, any>;
  itemIndex: number;
}

const DrupalMediaLibraryItem = ({
  id: providedId,
  itemIndex,
  children,
  attributes = {},
}: DrupalMediaLibraryItemProps) => {
  const autoId = useId();
  const id = providedId || `media-item-${autoId}`;
  const localRef = useRef<HTMLDivElement | null>(null);

  const sortableCtx = useContext(SortableRegistrationContext);
  const isSortable = sortableCtx ? sortableCtx.itemCount > 1 : false;

  // Use useSortable directly in this component
  const {
    attributes: sortableAttributes,
    listeners,
    setNodeRef,
    setActivatorNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({
    id,
    transition: {
      duration: 200,
      easing: 'cubic-bezier(0.25, 1, 0.5, 1)',
    },
  });

  // Merge both refs
  const mergedRef = useCallback(
    (node: HTMLDivElement | null) => {
      localRef.current = node;
      setNodeRef(node);
    },
    [setNodeRef],
  );

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  };

  return (
    <div
      data-item-index={itemIndex}
      ref={mergedRef}
      style={style}
      {...a2p(attributes, {}, { skipAttributes: ['class'] })}
      className={clsx(styles.item, attributes.class, {
        [styles.dragging]: isDragging,
      })}
      data-drupal-media-item-id={providedId}
      {...sortableAttributes}
    >
      {isSortable && (
        <div
          ref={setActivatorNodeRef}
          {...listeners}
          className={styles.dragHandle}
          aria-label="Drag to reorder"
        >
          <DragHandleDots2Icon />
        </div>
      )}
      {children}
    </div>
  );
};

export default DrupalMediaLibraryItem;
