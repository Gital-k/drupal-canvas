import { useMemo } from 'react';

import { useAppSelector } from '@/app/hooks';
import { selectTargetSlotInfo } from '@/features/ui/uiSlice';
import { useGetComponentsQuery } from '@/services/componentAndLayout';

import type { CanvasComponent, ComponentsList, PropSourceComponent } from '@/types/Component';

/**
 * Hook to get the allowedComponents for the current target slot.
 *
 * Returns undefined if:
 * - No slot is being targeted (not dragging over a slot)
 * - The target slot doesn't have allowedComponents restrictions
 * - The parent component doesn't have metadata with allowedComponents
 */
export function useTargetSlotAllowedComponents(): string[] | undefined {
  const targetSlotInfo = useAppSelector(selectTargetSlotInfo);
  const { data: components } = useGetComponentsQuery();

  return useMemo(() => {
    if (!targetSlotInfo?.parentComponentType || !targetSlotInfo?.slotId) {
      return undefined;
    }

    // Strip version suffix from component type (e.g., "y_canvas:y-accordion@version" -> "y_canvas:y-accordion")
    const [componentType] = targetSlotInfo.parentComponentType.split('@');

    // Look up the parent component from the components list
    const parentComponent = components?.[componentType] as PropSourceComponent | undefined;

    if (!parentComponent?.metadata?.slots) {
      return undefined;
    }

    // Get the slot definition with allowedComponents
    const slotDefinition = parentComponent.metadata.slots[targetSlotInfo.slotId];

    return slotDefinition?.allowedComponents;
  }, [targetSlotInfo, components]);
}

/**
 * Filters a component list based on the current target slot's allowedComponents.
 *
 * If no restrictions exist, returns all components unchanged.
 */
export function useSlotFilteredComponents<T extends { id: string }>(
  items: Record<string, T> | undefined,
): Record<string, T> | undefined {
  const allowedComponents = useTargetSlotAllowedComponents();

  return useMemo(() => {
    if (!items) {
      return items;
    }

    // No restrictions - return all items
    if (!allowedComponents?.length) {
      return items;
    }

    // Filter items to only those in allowedComponents
    const filtered: Record<string, T> = {};
    for (const [key, item] of Object.entries(items)) {
      if (allowedComponents.includes(item.id)) {
        filtered[key] = item;
      }
    }

    return filtered;
  }, [items, allowedComponents]);
}

export default useSlotFilteredComponents;
