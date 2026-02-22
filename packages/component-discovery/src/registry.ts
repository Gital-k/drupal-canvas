import type { ComponentRegistry } from 'drupal-canvas/json-render-utils';
import type { DiscoveredComponent, DiscoveryResult } from './types';

/**
 * Builds a component registry from discovery results by dynamically importing
 * each discovered component's JavaScript entry file.
 *
 * @param discoveryResult - Discovery result from `discoverCodeComponents()`
 * @returns A component registry that can be passed to `renderCanvasTree()` from drupal-canvas/json-render-utils
 */
export async function buildComponentRegistry(
  discoveryResult: DiscoveryResult,
): Promise<ComponentRegistry> {
  const registry: ComponentRegistry = {};

  const entries = await Promise.all(
    discoveryResult.components
      .filter(
        (component): component is DiscoveredComponent & { jsEntryPath: string } =>
          component.jsEntryPath !== null,
      )
      .map(async (component) => {
        const mod: Record<string, unknown> = await import(component.jsEntryPath);
        const renderFn = mod.default;
        if (typeof renderFn !== 'function') {
          return null;
        }
        return { name: component.name, renderFn } as const;
      }),
  );

  for (const entry of entries) {
    if (entry !== null) {
      registry[entry.name] = entry.renderFn as ComponentRegistry[string];
    }
  }

  return registry;
}
