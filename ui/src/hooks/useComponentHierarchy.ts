import { useMemo } from 'react';

import type { CanvasComponent, ComponentsList, PropSourceComponent } from '@/types/Component';

/**
 * A component that may have child components attached (from allowedComponents in slots).
 * Uses intersection type since CanvasComponent is a union type.
 */
export type ComponentWithChildren = CanvasComponent & {
  childComponents?: CanvasComponent[];
};

export interface HierarchyResult {
  /** Components with their child components attached (for Organisms with slots) */
  componentsWithHierarchy: Record<string, ComponentWithChildren>;
  /** Set of component IDs that are children of other components (should be hidden from flat list) */
  childComponentIds: Set<string>;
}

/**
 * Builds a hierarchy of components based on allowedComponents in slot definitions.
 * Organisms that have slots with allowedComponents will have their allowed children
 * attached as childComponents property.
 *
 * @param components - The full list of components from the API
 * @returns HierarchyResult with hierarchy-enriched components and set of child IDs
 */
export function useComponentHierarchy(
  components: ComponentsList | undefined,
): HierarchyResult {
  return useMemo(() => {
    if (!components) {
      return { componentsWithHierarchy: {}, childComponentIds: new Set() };
    }

    const childComponentIds = new Set<string>();
    const componentsWithHierarchy: Record<string, ComponentWithChildren> = {};

    // First pass: identify all parent-child relationships
    Object.entries(components).forEach(([id, component]) => {
      const propSourceComponent = component as PropSourceComponent;
      const slots = propSourceComponent?.metadata?.slots;

      if (slots) {
        const children: CanvasComponent[] = [];

        Object.values(slots).forEach((slot) => {
          const allowedComponents = slot.allowedComponents;
          if (allowedComponents && Array.isArray(allowedComponents)) {
            allowedComponents.forEach((allowedId) => {
              // allowedId format: "sdc.y_canvas.y-accordion-item"
              // components key format: "sdc.y_canvas.y-accordion-item"
              if (components[allowedId]) {
                childComponentIds.add(allowedId);
                children.push(components[allowedId]);
              }
            });
          }
        });

        if (children.length > 0) {
          componentsWithHierarchy[id] = {
            ...component,
            childComponents: children,
          } as ComponentWithChildren;
        } else {
          componentsWithHierarchy[id] = component as ComponentWithChildren;
        }
      } else {
        componentsWithHierarchy[id] = component as ComponentWithChildren;
      }
    });

    return { componentsWithHierarchy, childComponentIds };
  }, [components]);
}

/**
 * Filters out components that are children of other components.
 * Used to prevent Molecules from appearing in both their parent's hierarchy
 * AND in their own Molecules folder.
 *
 * @param components - The full list of components
 * @param childComponentIds - Set of component IDs that are children
 * @returns Filtered components list without the children
 */
export function filterOutChildComponents(
  components: ComponentsList | undefined,
  childComponentIds: Set<string>,
): ComponentsList | undefined {
  if (!components || childComponentIds.size === 0) {
    return components;
  }

  const filtered: ComponentsList = {};
  Object.entries(components).forEach(([id, component]) => {
    if (!childComponentIds.has(id)) {
      filtered[id] = component;
    }
  });

  return filtered;
}
