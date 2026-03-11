import { useDndContext } from '@dnd-kit/core';

import { useGetComponentsQuery } from '@/services/componentAndLayout';
import { getSlotAllowedComponentIds } from '@/utils/slot-utils';

import type { SlotNode } from '@/features/layout/layoutModelSlice';
import type { PropSourceComponent } from '@/types/Component';

/**
 * Check if a component is restricted (only allowed in specific slots).
 * Supports tag-based matching: a component is restricted if any slot's
 * expected entries (IDs or tags) resolve to include it.
 */
function isRestrictedComponent(
  componentId: string,
  components: Record<string, unknown> | undefined,
): boolean {
  if (!components) return false;

  for (const component of Object.values(components)) {
    const propSource = component as PropSourceComponent;
    const slots = propSource?.metadata?.slots;
    if (slots) {
      for (const slot of Object.values(slots)) {
        const allowedIds = getSlotAllowedComponentIds(
          slot,
          components as Record<string, any>,
        );
        if (allowedIds?.includes(componentId)) {
          return true;
        }
      }
    }
  }
  return false;
}

/**
 * Hook to determine if the currently dragged component can be dropped into
 * a specific parent slot based on the slot's expected/allowedComponents configuration.
 * Supports tag-based matching per Drupal core #3514072.
 *
 * @param parentSlot - The slot where the drop would occur
 * @param parentComponentType - The type of the parent component (e.g., 'sdc.y_canvas.y-accordion@version')
 * @returns boolean - true if drop is allowed, false otherwise
 */
export function useIsDropAllowed(
  parentSlot: SlotNode | undefined,
  parentComponentType: string | undefined,
): boolean {
  const { active } = useDndContext();
  const { data: components } = useGetComponentsQuery();

  // If not dragging from library, allow the drop (it's a reorder operation)
  const origin = active?.data?.current?.origin;
  if (origin !== 'library') {
    return true;
  }

  // Get the dragged item's ID
  const draggedItem = active?.data?.current?.item;
  const draggedItemId = draggedItem?.id;
  if (!draggedItemId) {
    return true;
  }

  // Check if this is a restricted component (Molecule that belongs in specific slots)
  const isRestricted = isRestrictedComponent(draggedItemId, components);

  // If no parent slot or parent component type, this is a region drop
  if (!parentSlot || !parentComponentType) {
    // Restricted components (Molecules) cannot be dropped on regions
    // They can only be dropped inside their parent's slots
    return !isRestricted;
  }

  // Strip version suffix from parent component type (e.g., '@abc123')
  const [cleanParentType] = parentComponentType.split('@');

  // Get parent component's metadata to find slot's expected/allowedComponents
  const parentComponent = components?.[cleanParentType] as
    | PropSourceComponent
    | undefined;

  if (!parentComponent?.metadata?.slots) {
    // Parent has no slots defined
    // Restricted items cannot drop here; unrestricted items can
    return !isRestricted;
  }

  // Find the slot definition by matching slot name or id
  const slotName = parentSlot.name || parentSlot.id;
  const slotDefinition = parentComponent.metadata.slots[slotName];

  // Resolve expected entries (IDs + tags) to concrete component IDs
  const allowedInSlot = getSlotAllowedComponentIds(slotDefinition, components);

  if (!allowedInSlot) {
    // No expected/allowedComponents restriction on this slot
    // Restricted items cannot drop here; unrestricted items can
    return !isRestricted;
  }

  // Check if the dragged item is in the resolved allowed list
  return allowedInSlot.includes(draggedItemId);
}

/**
 * Hook to check if any drop zones should be disabled for the current drag.
 * Returns whether the dragged item has slot restrictions (is a Molecule).
 * Supports tag-based matching.
 *
 * @returns object with draggedItemId and whether it has restrictions
 */
export function useDraggedItemInfo(): {
  draggedItemId: string | undefined;
  hasSlotRestrictions: boolean;
  allowedParentTypes: string[];
} {
  const { active } = useDndContext();
  const { data: components } = useGetComponentsQuery();

  const origin = active?.data?.current?.origin;
  if (origin !== 'library') {
    return {
      draggedItemId: undefined,
      hasSlotRestrictions: false,
      allowedParentTypes: [],
    };
  }

  const draggedItem = active?.data?.current?.item;
  const draggedItemId = draggedItem?.id;
  if (!draggedItemId || !components) {
    return {
      draggedItemId,
      hasSlotRestrictions: false,
      allowedParentTypes: [],
    };
  }

  // Find which parent components have this item in their resolved expected/allowedComponents
  const allowedParentTypes: string[] = [];
  Object.entries(components).forEach(([componentId, component]) => {
    const propSource = component as PropSourceComponent;
    const slots = propSource?.metadata?.slots;
    if (slots) {
      Object.values(slots).forEach((slot) => {
        const allowedIds = getSlotAllowedComponentIds(slot, components);
        if (allowedIds?.includes(draggedItemId)) {
          allowedParentTypes.push(componentId);
        }
      });
    }
  });

  return {
    draggedItemId,
    hasSlotRestrictions: allowedParentTypes.length > 0,
    allowedParentTypes,
  };
}
