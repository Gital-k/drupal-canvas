import type { CanvasComponent, ComponentsList } from '@/types/Component';

/**
 * Determines if an expected entry is a component ID or a tag.
 *
 * Per Drupal core issue #3514072 / MR !13548:
 * - Entries containing ':' are component IDs (e.g., "sdc.y_canvas.y-accordion-item")
 * - Entries without ':' are tags (e.g., "card-content")
 */
function isComponentId(entry: string): boolean {
  return entry.includes('.');
}

/**
 * Resolves an array of expected entries (component IDs and/or tags) into
 * a set of concrete component IDs.
 *
 * Tags are matched against the `tags` array in each component's metadata.
 * Component IDs are passed through directly.
 *
 * @param expected - Array of component IDs or tags from slot definition
 * @param components - Full components list to resolve tags against
 * @returns Array of resolved component IDs
 */
export function resolveExpectedToComponentIds(
  expected: string[],
  components: ComponentsList | undefined,
): string[] {
  if (!components) return [];

  const resolvedIds = new Set<string>();

  for (const entry of expected) {
    if (isComponentId(entry)) {
      // Direct component ID
      if (components[entry]) {
        resolvedIds.add(entry);
      }
    } else {
      // Tag — find all components with this tag
      for (const [id, component] of Object.entries(components)) {
        const tags = (
          component as CanvasComponent & { metadata?: { tags?: string[] } }
        )?.metadata?.tags;
        if (tags?.includes(entry)) {
          resolvedIds.add(id);
        }
      }
    }
  }

  return Array.from(resolvedIds);
}

/**
 * Gets the resolved allowed component IDs for a slot, supporting both
 * direct component IDs and tag-based matching.
 *
 * @param slot - Slot definition with expected/allowedComponents
 * @param components - Full components list for tag resolution
 * @returns Array of resolved component IDs, or undefined if no restrictions
 */
export function getSlotAllowedComponentIds(
  slot:
    | { expected?: string[]; allowedComponents?: string[]; [key: string]: any }
    | undefined,
  components: ComponentsList | undefined,
): string[] | undefined {
  const expected = slot?.expected || slot?.allowedComponents;
  if (!expected || !Array.isArray(expected) || expected.length === 0) {
    return undefined;
  }
  return resolveExpectedToComponentIds(expected, components);
}
