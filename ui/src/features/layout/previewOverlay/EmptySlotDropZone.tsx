import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { kebabCase } from 'lodash';
import { useDroppable } from '@dnd-kit/core';
import { BoxModelIcon } from '@radix-ui/react-icons';

import { useAppSelector } from '@/app/hooks';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { findNodePathByUuid } from '@/features/layout/layoutUtils';
import useGetComponentName from '@/hooks/useGetComponentName';
import { useIsDropAllowed } from '@/hooks/useIsDropAllowed';

import type React from 'react';
import type {
  ComponentNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';

import styles from '@/features/layout/previewOverlay/PreviewOverlay.module.css';

export interface EmptySlotDropZoneProps {
  slot: SlotNode;
  slotName: string;
  parentComponent: ComponentNode;
}
const EmptySlotDropZone: React.FC<EmptySlotDropZoneProps> = (props) => {
  const { slot, slotName, parentComponent } = props;
  const layout = useAppSelector(selectLayout);
  const [activeName, setActiveName] = useState('');
  const parentComponentName = useGetComponentName(parentComponent);
  const isDropAllowed = useIsDropAllowed(slot, parentComponent.type);

  const slotPath = findNodePathByUuid(layout, slot.id);
  if (!slotPath) {
    throw new Error(`Unable to ascertain 'path' to component ${slot.id}`);
  }
  // We want to drop into the first (0th) space in the empty slot.
  slotPath.push(0);

  const accepts = ['overlay', 'library'];

  const {
    setNodeRef: setDropRef,
    isOver,
    active,
  } = useDroppable({
    id: `${slot.id}`,
    // No disabled prop — always registered for collision detection.
    // Origin filtering handled by isDropAllowed and drop handlers.
    // See #3563163: disabled+useEffect causes a timing bug where
    // the droppable misses dnd-kit's initial measurement pass.
    data: {
      component: parentComponent,
      parentSlot: slot,
      parentComponentType: parentComponent.type,
      path: slotPath,
      accepts,
    },
  });

  useEffect(() => {
    if (isOver && active) {
      setActiveName(active.data?.current?.name);
    } else {
      setActiveName('');
    }
  }, [active, isOver]);

  return (
    <div className={clsx(styles.emptySlotContainer, {
      [styles.isDragging]: !!active,
    })} data-testid="canvas-empty-slot">
      <div
        className={clsx(styles.emptySlotDropZone, {
          [styles.isOver]: isOver && isDropAllowed,
          [styles.isDragging]: !!active,
        })}
        data-testid={`canvas-empty-slot-drop-zone-${kebabCase(parentComponentName)}:${kebabCase(slotName)}`}
        ref={setDropRef}
      >
        {activeName ? (
          activeName
        ) : (
          <>
            <BoxModelIcon />
            <div>{slotName}</div>
          </>
        )}
      </div>
    </div>
  );
};

export default EmptySlotDropZone;
