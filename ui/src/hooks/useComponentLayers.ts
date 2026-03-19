import { useMemo } from 'react';

import { useAppSelector } from '@/app/hooks';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { getComponentLayersFromUuid } from '@/features/layout/layoutUtils';
import { useGetComponentsQuery } from '@/services/componentAndLayout';

import type { ComponentLayerItem } from '@/features/layout/layoutUtils';

/**
 * Returns the list of component layers (root to target) for a given component UUID.
 * Used to render the parent breadcrumb badges in the overlay.
 */
export function useComponentLayers(
  componentUuid: string | undefined,
): ComponentLayerItem[] {
  const layout = useAppSelector(selectLayout);
  const { data: components } = useGetComponentsQuery();

  return useMemo(() => {
    if (!componentUuid) return [];
    return getComponentLayersFromUuid(layout, componentUuid, components);
  }, [layout, componentUuid, components]);
}
