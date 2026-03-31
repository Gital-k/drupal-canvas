import { useMemo } from 'react';

import { useAppSelector } from '@/app/hooks';
import { selectTargetSlotInfo } from '@/features/ui/uiSlice';
import { useGetComponentsQuery } from '@/services/componentAndLayout';
import { getSlotAllowedComponentIds } from '@/utils/slot-utils';

import type { PropSourceComponent } from '@/types/Component';

/**
 * Hook to get the resolved allowed component IDs for the current target slot.
 * Supports both Drupal core 'expected' and legacy 'allowedComponents' properties,
 * including tag-based matching per Drupal core #3514072.
 *
 * Returns undefined if:
 * - No slot is being targeted (not dragging over a slot)
 * - The target slot doesn't have expected/allowedComponents restrictions
 * - The parent component doesn't have metadata with slot restrictions
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
    const parentComponent = components?.[componentType] as
      | PropSourceComponent
      | undefined;

    if (!parentComponent?.metadata?.slots) {
      return undefined;
    }

    // Get the slot definition and resolve expected entries (IDs + tags)
    const slotDefinition =
      parentComponent.metadata.slots[targetSlotInfo.slotId];
    return getSlotAllowedComponentIds(slotDefinition, components);
  }, [targetSlotInfo, components]);
}

/**
 * Filters a component list based on the current target slot's allowedComponents.
 * Supports tag-based matching: tags in 'expected' are resolved to concrete component IDs.
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

    // Filter items to only those in resolved allowedComponents
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
