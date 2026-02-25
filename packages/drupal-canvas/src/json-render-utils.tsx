import type React from 'react';
import type { Spec, UIElement } from '@json-render/core';
import type { ComponentMetadata } from '@drupal-canvas/component-discovery';
import { defineCatalog } from '@json-render/core';
import { schema } from '@json-render/react/schema';
import { fromJSONSchema } from 'zod';

/**
 * Drupal Canvas component tree node.
 * @see canvas.component_tree_node in config/schema/canvas.schema.yml
 */
export interface CanvasComponentTreeNode {
  parent_uuid: string | null;
  slot: string | null;
  uuid: string;
  component_id: string;
  component_version: string | null;
  inputs: Record<string, unknown>;
  label: string | null;
}

/**
 * Drupal Canvas component tree. A flat sequence of component tree nodes linked by parent_uuid.
 * @see canvas.component_tree in config/schema/canvas.schema.yml
 */
export type CanvasComponentTree = CanvasComponentTreeNode[];

/**
 * Converts an array of Drupal Canvas components to json-render spec format.
 *
 * @param components - Flat array of Canvas component tree nodes
 * @returns json-render spec
 */
export function canvasTreeToSpec(components: CanvasComponentTree): Spec {
  const elements: Record<string, UIElement> = {};
  const rootUuids: string[] = [];

  for (const component of components) {
    // Parse inputs if the API returned it as a JSON string rather than an object.
    let inputs: Record<string, unknown>;
    if (typeof component.inputs === 'string') {
      try {
        inputs = JSON.parse(component.inputs) as Record<string, unknown>;
      } catch {
        throw new Error(
          `Component "${component.uuid}" has malformed JSON inputs: ${component.inputs}`,
        );
      }
    } else {
      inputs = component.inputs;
    }

    // @todo: Convert Canvas content template prop expressions to json-render data bindings and reverse in specToCanvasTree.

    if (component.parent_uuid === null) {
      elements[component.uuid] = {
        type: component.component_id,
        props: inputs,
      };
      rootUuids.push(component.uuid);
    } else {
      if (component.slot === null) {
        throw new Error(
          `Component "${component.uuid}" has a parent_uuid but no slot.`,
        );
      }
      // Canvas component tree should always have parents precede their children.
      const parent = elements[component.parent_uuid];
      if (!parent) {
        throw new Error(
          `Component "${component.uuid}" references unknown or out-of-order parent "${component.parent_uuid}".`,
        );
      }

      elements[component.uuid] = {
        type: component.component_id,
        props: inputs,
      };

      if (component.slot === 'children') {
        // In React, children is a special prop that acts as a default slot — json-render keeps it separately from named slots.
        if (!parent.children) {
          parent.children = [];
        }
        parent.children.push(component.uuid);
      } else {
        if (!parent.slots) {
          parent.slots = {};
        }
        if (!parent.slots[component.slot]) {
          parent.slots[component.slot] = [];
        }
        parent.slots[component.slot].push(component.uuid);
      }
    }
  }

  if (rootUuids.length === 0) {
    throw new Error(
      'Canvas component tree has no root component (no component with null parent_uuid).',
    );
  }

  // A canvas component tree may have multiple top-level components.
  // Wrap them in a synthetic wrapper element so the spec has a single root.
  // @see renderSpec
  if (rootUuids.length > 1) {
    elements['canvas:component-tree'] = {
      type: 'canvas:component-tree',
      props: {},
      children: rootUuids,
    };
    return { root: 'canvas:component-tree', elements };
  }

  return {
    root: rootUuids[0],
    elements,
  };
}

/**
 * Converts a json-render spec subtree rooted at key to flat Canvas nodes,
 * appending each node to result.
 */
function convertSpecElement(
  key: string,
  elements: Record<string, UIElement>,
  result: CanvasComponentTree,
  parentUuid: string | null,
  slot: string | null,
): void {
  const element = elements[key];
  if (!element) {
    throw new Error(`Element key "${key}" not found in elements map.`);
  }

  // Use the spec element key as the UUID if it is already a valid UUID
  // (e.g. when the spec was produced by canvasTreeToSpec). Fall back to
  // a fresh UUID otherwise.
  const UUID_PATTERN =
    /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
  const uuid = UUID_PATTERN.test(key) ? key : crypto.randomUUID();

  result.push({
    uuid,
    parent_uuid: parentUuid,
    slot,
    component_id: element.type,
    // Component version has no equivalent in the json-render spec - set to null.
    component_version: null,
    inputs: element.props,
    label: null,
  });

  // json-render's children array has no Canvas equivalent — map to 'children' slot.
  for (const childKey of element.children ?? []) {
    convertSpecElement(childKey, elements, result, uuid, 'children');
  }

  for (const [slotName, childKeys] of Object.entries(element.slots ?? {})) {
    for (const childKey of childKeys) {
      convertSpecElement(childKey, elements, result, uuid, slotName);
    }
  }
}

/**
 * Converts a json-render spec to Drupal Canvas component tree format.
 *
 * @param jsonRenderSpec - json-render spec
 * @returns Flat array of Canvas component tree nodes
 */
export function specToCanvasTree(jsonRenderSpec: Spec): CanvasComponentTree {
  const result: CanvasComponentTree = [];
  const rootElement = jsonRenderSpec.elements[jsonRenderSpec.root];

  if (!rootElement) {
    throw new Error(
      `Root element "${jsonRenderSpec.root}" not found in elements map.`,
    );
  }

  // Unwrap the synthetic canvas:component-tree wrapper added by canvasTreeToSpec
  // for multi-root trees — it has no Canvas equivalent and must not appear in output.
  if (rootElement.type === 'canvas:component-tree') {
    for (const childKey of rootElement.children ?? []) {
      convertSpecElement(childKey, jsonRenderSpec.elements, result, null, null);
    }
    return result;
  }

  convertSpecElement(
    jsonRenderSpec.root,
    jsonRenderSpec.elements,
    result,
    null,
    null,
  );
  return result;
}

/**
 * Registry of components keyed by component_id.
 */
export type ComponentRegistry = Record<string, (props: Record<string, unknown>) => React.ReactNode>;

/**
 * Renders a single element from a json-render spec, recursively resolving
 * children and named slots.
 */
function renderSpecElement(
  key: string,
  elements: Spec['elements'],
  registry: ComponentRegistry,
): React.ReactNode {
  const element = elements[key];
  if (!element) {
    throw new Error(`Element key "${key}" not found in elements map.`);
  }

  // Transparent passthrough for the synthetic multi-root wrapper.
  // @see renderSpec
  if (element.type === 'canvas:component-tree') {
    const children = (element.children ?? []).map((k) =>
      renderSpecElement(k, elements, registry),
    );
    return <>{children}</>;
  }

  const component = registry[element.type];
  if (!component) {
    return null;
  }

  const children = (element.children ?? []).map((k) =>
    renderSpecElement(k, elements, registry),
  );

  const slots: Record<string, React.ReactNode[]> = {};
  for (const [slotName, childKeys] of Object.entries(element.slots ?? {})) {
    slots[slotName] = childKeys.map((k) =>
      renderSpecElement(k, elements, registry),
    );
  }

  return component({
    ...element.props,
    ...slots,
    ...(children.length > 0 ? { children } : {}),
  });
}

/**
 * Renders a json-render spec using the given component registry.
 *
 * @param spec - json-render spec to render
 * @param registry - Component registry to use for rendering.
 * @see {@link defineComponentRegistry}
 */
export function renderSpec(
  spec: Spec,
  registry: ComponentRegistry,
): React.ReactNode {
  return renderSpecElement(spec.root, spec.elements, registry);
}

/**
 * Renders a Drupal Canvas component tree.
 *
 * @param components - Canvas component tree to render
 * @param registry - Component registry to use for rendering.
 * @see {@link defineComponentRegistry}
 */
export function renderCanvasTree(
  components: CanvasComponentTree,
  registry: ComponentRegistry,
): React.ReactNode {
  const spec = canvasTreeToSpec(components);
  return renderSpec(spec, registry);
}

function metadataToCatalogEntry(metadata: ComponentMetadata) {
  const jsonSchema: Record<string, unknown> = {
    type: 'object',
    ...metadata.props,
  };

  if (metadata.required.length > 0) {
    jsonSchema.required = metadata.required;
  }

  return {
    props: fromJSONSchema(jsonSchema),
    slots: Object.keys(metadata.slots),
    description: metadata.description,
  };
}

/**
 * Defines a component registry by dynamically importing each component's
 * JavaScript entry file from the discovery results.
 *
 * @param components - Array of discovered components from `discoverCodeComponents()`
 * @returns A component registry that can be passed to `renderCanvasTree()`
 */
export async function defineComponentRegistry(
  components: { name: string; jsEntryPath: string | null }[],
): Promise<ComponentRegistry> {
  const registry: ComponentRegistry = {};

  const entries = await Promise.all(
    components
      .filter(
        (c): c is { name: string; jsEntryPath: string } =>
          c.jsEntryPath !== null,
      )
      .map(async (c) => {
        const mod: Record<string, unknown> = await import(c.jsEntryPath);
        const renderFn = mod.default;
        if (typeof renderFn !== 'function') {
          return null;
        }
        return { name: c.name, renderFn } as const;
      }),
  );

  for (const entry of entries) {
    if (entry !== null) {
      registry[entry.name] = entry.renderFn as ComponentRegistry[string];
    }
  }

  return registry;
}

/**
 * Defines a complete json-render catalog from component metadata using the
 * `@json-render/react` schema. Converts props from JSON Schema (as defined
 * in component.yml) to Zod schemas.
 *
 * @param metadata - Array of component metadata from `loadComponentsMetadata()`
 * @returns A json-render `Catalog` instance
 */
export function defineComponentCatalog(metadata: ComponentMetadata[]) {
  const components = Object.fromEntries(
    metadata.map((m) => [m.name, metadataToCatalogEntry(m)]),
  );

  return defineCatalog(schema, { components, actions: {} });
}
