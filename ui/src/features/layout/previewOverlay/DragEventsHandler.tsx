import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { DragOverlay, useDndMonitor } from '@dnd-kit/core';
import {
  restrictToFirstScrollableAncestor,
  restrictToWindowEdges,
} from '@dnd-kit/modifiers';

import { useAppDispatch } from '@/app/hooks';
import {
  setCodeDragging,
  setListDragging,
  setPreviewDragging,
  setTargetSlot,
  setTargetSlotInfo,
  setTreeDragging,
  setUpdatingComponent,
  unsetTargetSlot,
} from '@/features/ui/uiSlice';
import { useDropFromLayoutHandler } from '@/hooks/useDropFromLayoutHandler';
import { useDropFromLibraryHandler } from '@/hooks/useDropFromLibraryHandler';
import { useDropOnFolderHandler } from '@/hooks/useDropOnFolderHandler';
import { useGetComponentsQuery } from '@/services/componentAndLayout';
import { getSlotAllowedComponentIds } from '@/utils/slot-utils';

import type { PropSourceComponent } from '@/types/Component';

import {
  cleanupMouseTracking,
  initMouseTracking,
  snapRightToCursor,
} from './snapRightToCursor';

import type React from 'react';
import type {
  DragEndEvent,
  DragOverEvent,
  DragStartEvent,
} from '@dnd-kit/core';

import styles from './DragOverlay.module.css';

const DragEventsHandler: React.FC = () => {
  const dispatch = useAppDispatch();
  const [componentName, setComponentName] = useState('...');
  const [dragOrigin, setDragOrigin] = useState('');
  const [isDraggingFolder, setIsDraggingFolder] = useState(false);
  const [isFolderAtBoundary, setIsFolderAtBoundary] = useState(false);
  const [isDropNotAllowed, setIsDropNotAllowed] = useState(false);
  const { handleFolderDrop } = useDropOnFolderHandler();
  const { handleNewDrop } = useDropFromLibraryHandler();
  const { handleExistingDrop } = useDropFromLayoutHandler();
  const { data: components } = useGetComponentsQuery();

  // Apply/remove folderAtBoundary class when state changes.
  useEffect(() => {
    if (isFolderAtBoundary) {
      window.document.body.classList.add(styles.folderAtBoundary);
    } else {
      window.document.body.classList.remove(styles.folderAtBoundary);
    }

    // Cleanup: remove class when component unmounts.
    return () => {
      window.document.body.classList.remove(styles.folderAtBoundary);
    };
  }, [isFolderAtBoundary]);

  // Apply/remove notAllowed class when hovering over a disallowed drop zone.
  useEffect(() => {
    if (isDropNotAllowed) {
      window.document.body.classList.add(styles.notAllowed);
    } else {
      window.document.body.classList.remove(styles.notAllowed);
    }

    return () => {
      window.document.body.classList.remove(styles.notAllowed);
    };
  }, [isDropNotAllowed]);

  const afterDrag = (
    elements: HTMLElement[] = [],
    successful?: boolean,
    componentUuid?: string,
  ) => {
    if (successful && componentUuid) {
      dispatch(setUpdatingComponent(componentUuid));
    }
  };

  const getOrigin = (
    event: any,
  ): 'library' | 'overlay' | 'layers' | 'code' | 'unknown' => {
    if (event.active?.data?.current?.origin) {
      return event.active.data.current.origin;
    } else {
      return 'unknown';
    }
  };

  // Custom modifier to restrict folder dragging to sidebar area.
  const restrictFolderToSidebar = ({ transform, active }: any) => {
    if (active?.data?.current?.type !== 'folder') {
      return transform;
    }

    // Get the sidebar element using the existing test ID.
    const sidebar = document.querySelector(
      '[data-testid="canvas-primary-panel"]',
    );
    if (!sidebar) {
      return transform;
    }

    const sidebarRect = sidebar.getBoundingClientRect();
    const activeRect = active.rect.current.translated;

    if (!activeRect) {
      return transform;
    }

    // Check if dragging beyond the right edge of sidebar.
    if (activeRect.left + transform.x > sidebarRect.right) {
      // Constrain to sidebar right boundary and change cursor to default.
      if (!isFolderAtBoundary) {
        setIsFolderAtBoundary(true);
      }
      return {
        ...transform,
        x: sidebarRect.right - activeRect.left,
      };
    }

    // Reset cursor to grabbing if within bounds.
    if (isFolderAtBoundary) {
      setIsFolderAtBoundary(false);
    }
    return transform;
  };

  const modifiers = ['layers', 'code'].includes(dragOrigin)
    ? [snapRightToCursor, restrictToFirstScrollableAncestor]
    : isDraggingFolder
      ? [restrictFolderToSidebar]
      : [snapRightToCursor, restrictToWindowEdges];

  function handleDragStart(event: DragStartEvent) {
    initMouseTracking();
    setComponentName(event.active.data?.current?.name);
    const isFolderDrag = event.active.data?.current?.type === 'folder';
    setIsDraggingFolder(isFolderDrag);
    setIsFolderAtBoundary(false);
    window.document.body.classList.add(styles.dragging);
    const origin = getOrigin(event);
    setDragOrigin(origin);
    if (origin === 'overlay') {
      dispatch(setPreviewDragging(true));
    } else if (origin === 'library') {
      dispatch(setListDragging(true));
    } else if (origin === 'layers') {
      dispatch(setTreeDragging(true));
    } else if (origin === 'code') {
      dispatch(setCodeDragging(true));
    }
  }

  /**
   * Check if the dragged component is allowed in the target drop zone.
   */
  function checkDropAllowed(active: any, over: any): boolean {
    if (!over || !components) return true;

    // Get dragged component ID
    const origin = active?.data?.current?.origin;
    let draggedId: string | undefined;
    if (origin === 'library') {
      draggedId = active?.data?.current?.item?.id;
    } else if (origin === 'layers' || origin === 'overlay') {
      const compType = active?.data?.current?.component?.type;
      if (compType) {
        draggedId = compType.split('@')[0];
      }
    }
    if (!draggedId) return true;

    // Check if dragged component is restricted
    let isRestricted = false;
    for (const comp of Object.values(components)) {
      const propSource = comp as PropSourceComponent;
      const slots = propSource?.metadata?.slots;
      if (slots) {
        for (const slot of Object.values(slots)) {
          const allowedIds = getSlotAllowedComponentIds(slot, components as Record<string, any>);
          if (allowedIds?.includes(draggedId)) {
            isRestricted = true;
            break;
          }
        }
      }
      if (isRestricted) break;
    }

    const parentSlot = over?.data?.current?.parentSlot;
    const parentRegion = over?.data?.current?.parentRegion;

    // Region drop — restricted components cannot be dropped here.
    // Also check if the region contains components with restricted slots
    // that don't include the dragged component (defense-in-depth for when
    // `over` resolves to RegionDropZone instead of EmptySlotDropZone).
    if (!parentSlot || parentRegion) {
      if (isRestricted) return false;

      // Check if any component in this region has slots that restrict the dragged component
      const regionNode = over?.data?.current?.region;
      if (regionNode?.components && components) {
        for (const comp of regionNode.components) {
          const [compType] = comp.type.split('@');
          const compMeta = (components[compType] as PropSourceComponent)?.metadata?.slots;
          if (compMeta) {
            for (const slotDef of Object.values(compMeta)) {
              const allowedIds = getSlotAllowedComponentIds(slotDef, components as Record<string, any>);
              if (allowedIds && !allowedIds.includes(draggedId)) {
                return false;
              }
            }
          }
        }
      }
      return true;
    }

    // Slot drop — check if slot allows this component
    // parentComponentType may come from layers droppable data, or from overlay's component
    const parentCompType = over?.data?.current?.parentComponentType || over?.data?.current?.component?.type;
    if (!parentCompType) return !isRestricted;

    const [parentType] = parentCompType.split('@');
    const parentComp = components[parentType] as PropSourceComponent | undefined;
    if (!parentComp?.metadata?.slots) return !isRestricted;

    const slotName = parentSlot.name || parentSlot.id;
    const slotDef = parentComp.metadata.slots[slotName];
    const allowedIds = getSlotAllowedComponentIds(slotDef, components);
    if (!allowedIds) return !isRestricted;

    return allowedIds.includes(draggedId);
  }

  function handleDragOver(event: DragOverEvent) {
    const { over, active } = event;
    const parentSlot = over?.data?.current?.parentSlot;
    const parentRegion = over?.data?.current?.parentRegion;

    // If dragging a folder and hovering over non-folder destination, prevent visual feedback.
    if (active.data?.current?.type === 'folder') {
      if (!over || over.data?.current?.destination !== 'folder') {
        dispatch(unsetTargetSlot());
        setIsDropNotAllowed(false);
        return;
      }
    }

    // Check slot restrictions using the simple `over` target.
    if (over) {
      const allowed = checkDropAllowed(active, over);
      setIsDropNotAllowed(!allowed);
    } else {
      setIsDropNotAllowed(false);
    }

    if (parentRegion) {
      dispatch(setTargetSlot(parentRegion.id));
      dispatch(setTargetSlotInfo(undefined));
    } else if (parentSlot) {
      dispatch(setTargetSlot(parentSlot.id));
      const parentComponentType =
        over?.data?.current?.component?.type as string | undefined;
      if (parentComponentType) {
        dispatch(
          setTargetSlotInfo({
            slotId: parentSlot.name || parentSlot.id,
            parentComponentType,
          }),
        );
      } else {
        dispatch(setTargetSlotInfo(undefined));
      }
    }
  }

  function dragEndCancelCommon() {
    dispatch(setPreviewDragging(false));
    dispatch(setListDragging(false));
    dispatch(setTreeDragging(false));
    dispatch(setCodeDragging(false));
    dispatch(unsetTargetSlot());
    dispatch(setTargetSlotInfo(undefined));
    setIsDraggingFolder(false);
    setIsFolderAtBoundary(false);
    setIsDropNotAllowed(false);
    window.document.body.classList.remove(styles.dragging);
    window.document.body.classList.remove(styles.notAllowed);

    // Ensure the mouse tracking is cleaned up
    cleanupMouseTracking();
  }

  function handleDragEnd(event: DragEndEvent) {
    dragEndCancelCommon();
    const { over, active } = event;
    const elementsInsideIframe =
      active.data?.current?.elementsInsideIframe || [];
    if (!over) {
      // If the dragged item wasn't dropped into a valid dropZone, do nothing.
      afterDrag(elementsInsideIframe, false);
      return;
    }
    const origin = getOrigin(event);

    if (
      ['folder', 'uncategorized'].includes(
        over.data?.current?.destination || '',
      ) &&
      ['library', 'code', 'folder'].includes(origin)
    ) {
      // Handle drop into folder from library or folder reordering.
      handleFolderDrop(event);
    } else if (
      origin === 'overlay' ||
      over.data.current?.destination === 'layers'
    ) {
      // Handle dropping an existing instance back into layout from overlay or layers panel
      handleExistingDrop(event, afterDrag);
    } else if (
      origin === 'library' &&
      active.data?.current?.type !== 'folder'
    ) {
      // Handle dropping components/patterns from library (folders excluded).
      handleNewDrop(event);
    }
  }

  function handleDragCancel() {
    dragEndCancelCommon();
  }

  useDndMonitor({
    onDragStart: handleDragStart,
    onDragOver: handleDragOver,
    onDragEnd: handleDragEnd,
    onDragCancel: handleDragCancel,
  });

  return (
    <DragOverlay
      modifiers={modifiers}
      className={clsx(styles.dragOverlay, {
        [styles.notAllowedOverlay]: isDropNotAllowed,
      })}
      dropAnimation={null}
    >
      {!isDraggingFolder && <div>{componentName}</div>}
    </DragOverlay>
  );
};

export default DragEventsHandler;
