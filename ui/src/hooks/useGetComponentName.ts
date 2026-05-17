import { useCallback } from 'react';

import { useAppSelector } from '@/app/hooks';
import { selectLayout } from '@/features/layout/layoutModelSlice';
import { getDisplayNameForNode } from '@/features/layout/layoutUtils';
import { useGetComponentsQuery } from '@/services/componentAndLayout';

import type {
  ComponentNode,
  LayoutChildNode,
  LayoutNode,
  SlotNode,
} from '@/features/layout/layoutModelSlice';
import type { ComponentsList } from '@/types/Component';

const useGetComponentName = (
  node: LayoutChildNode | null,
  parentNode?: LayoutNode | null,
) => {
  const { data: components } = useGetComponentsQuery();
  // Layout is read so the unnamed-component fallback can append a per-page
  // positional index (e.g. "Section Wrapper 1"). See #3527952.
  const layout = useAppSelector(selectLayout);

  // Helper to cast parentNode to ComponentNode if needed
  const getParentComponentNode = (
    parent: LayoutNode | null | undefined,
  ): ComponentNode | null => {
    if (parent && 'type' in parent) {
      return parent as ComponentNode;
    }
    return null;
  };

  // Helper to cast node to SlotNode if needed
  const getSlotNode = (n: LayoutChildNode | null): SlotNode | null => {
    if (n && n.nodeType === 'slot') {
      return n as SlotNode;
    }
    return null;
  };

  // Helper to cast node to ComponentNode if needed
  const getComponentNode = (
    n: LayoutChildNode | null,
  ): ComponentNode | null => {
    if (n && n.nodeType === 'component') {
      return n as ComponentNode;
    }
    return null;
  };

  const getName = useCallback(() => {
    if (!node || !components) return '';
    if (node.nodeType === 'component') {
      return getDisplayNameForNode(
        getComponentNode(node),
        null,
        components as ComponentsList,
        layout,
      );
    } else if (node.nodeType === 'slot') {
      return getDisplayNameForNode(
        getSlotNode(node),
        getParentComponentNode(parentNode),
        components as ComponentsList,
        layout,
      );
    }
    return '';
  }, [node, parentNode, components, layout]);

  return getName();
};

export default useGetComponentName;
