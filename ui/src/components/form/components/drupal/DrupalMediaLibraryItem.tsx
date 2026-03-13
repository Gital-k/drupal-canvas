import { useCallback, useId, useRef } from 'react';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';

import { a2p } from '@/local_packages/utils';

import type { ReactNode } from 'react';

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

  // Use useSortable directly in this component
  const {
    attributes: sortableAttributes,
    listeners,
    setNodeRef,
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
      data-drupal-media-item-id={providedId}
      {...a2p(attributes)}
      {...sortableAttributes}
      {...listeners}
    >
      {children}
    </div>
  );
};

export default DrupalMediaLibraryItem;
