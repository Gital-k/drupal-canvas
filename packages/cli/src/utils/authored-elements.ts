import { randomUUID } from 'crypto';

import { isRecord } from './utils';

import type {
  AuthoredSpecElementMap,
  CanvasComponentTree,
  canvasTreeToSpec,
} from 'drupal-canvas/json-render-utils';

const UUID_RE =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/**
 * Converts a json-render spec to an authored element map suitable for page
 * and region spec files.
 *
 * The authored format differs from the json-render spec in two ways:
 * 1. The synthetic `canvas:component-tree` wrapper is stripped.
 * 2. `children` is merged into `slots.children` so all slot references are
 *    in a single map.
 */
export function specToAuthoredElementMap(
  spec: ReturnType<typeof canvasTreeToSpec>,
): AuthoredSpecElementMap {
  const elements: AuthoredSpecElementMap = {};

  for (const [key, element] of Object.entries(spec.elements)) {
    if (element.type === 'canvas:component-tree') continue;

    const slots: Record<string, string[]> = {};
    if (element.children && element.children.length > 0) {
      slots.children = [...element.children];
    }
    if (element.slots) {
      for (const [slotName, childKeys] of Object.entries(element.slots)) {
        slots[slotName] = [...childKeys];
      }
    }

    elements[key] = {
      type: element.type,
      props: isRecord(element.props) ? element.props : {},
      ...(Object.keys(slots).length > 0 ? { slots } : {}),
    };
  }

  return elements;
}

/**
 * Converts an authored element map back to the flat CanvasComponentTreeNode[]
 * array expected by the Canvas API. Rebuilds parent_uuid and slot
 * relationships by scanning each element's slots map.
 */
export function authoredSpecToComponentTree(
  elements: AuthoredSpecElementMap,
  componentVersions?: Map<string, string>,
): CanvasComponentTree {
  const keyToUuid = new Map<string, string>();
  for (const key of Object.keys(elements)) {
    keyToUuid.set(key, UUID_RE.test(key) ? key : randomUUID());
  }

  const childToParent = new Map<string, { parentKey: string; slot: string }>();

  for (const [key, element] of Object.entries(elements)) {
    if (!element.slots) continue;
    for (const [slotName, childKeys] of Object.entries(element.slots)) {
      for (const childKey of childKeys) {
        childToParent.set(childKey, { parentKey: key, slot: slotName });
      }
    }
  }

  const components: CanvasComponentTree = [];
  for (const [key, element] of Object.entries(elements)) {
    const parent = childToParent.get(key);
    components.push({
      uuid: keyToUuid.get(key)!,
      component_id: element.type,
      component_version: componentVersions?.get(element.type) ?? '',
      inputs: isRecord(element.props)
        ? (element.props as Record<string, unknown>)
        : {},
      parent_uuid: parent ? (keyToUuid.get(parent.parentKey) ?? null) : null,
      slot: parent?.slot ?? null,
      label: null,
    });
  }

  return components;
}
