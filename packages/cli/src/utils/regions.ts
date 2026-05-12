import { canvasTreeToSpec } from 'drupal-canvas/json-render-utils';

import { specToAuthoredElementMap } from './authored-elements';
import { isRecord } from './utils';

import type {
  AuthoredSpecElementMap,
  CanvasComponentTree,
} from 'drupal-canvas/json-render-utils';
import type { Region } from '../types/Region';

/**
 * On-disk shape of a region JSON file.
 *
 * Mirrors the authored spec used by pages, with `theme`/`region` taking the
 * place of `path`/`title` since regions are theme-scoped, not URL-scoped.
 */
export interface AuthoredRegionSpec {
  uuid?: string;
  theme: string;
  region: string;
  status?: boolean;
  elements: AuthoredSpecElementMap;
}

/**
 * Convert a wire-format Region (from the Drupal API) to its authored spec
 * form for writing to disk.
 */
export function regionToAuthoredSpec(region: Region): AuthoredRegionSpec {
  const meta = {
    theme: region.theme,
    region: region.region,
    status: region.status,
  };

  if (region.component_tree.length === 0) {
    return { ...meta, elements: {} };
  }

  // The PageRegion config schema omits `parent_uuid`, `slot`, and `label`
  // when they have no value, so the server returns root-level components
  // with those keys absent. canvasTreeToSpec requires explicit `null` to
  // recognize root components, so normalize back here.
  const components = region.component_tree.map((node) => ({
    ...node,
    parent_uuid: node.parent_uuid ?? null,
    slot: node.slot ?? null,
    label: node.label ?? null,
    inputs: isRecord(node.inputs) ? node.inputs : {},
  }));

  const spec = canvasTreeToSpec(components);
  const elements = specToAuthoredElementMap(spec);

  return { ...meta, elements };
}

/**
 * Convert an authored region spec (loaded from disk) to a wire-format
 * payload suitable for POST/PATCH to `/canvas/api/v0/config/page_region`.
 */
export function authoredRegionToPayload(
  spec: AuthoredRegionSpec,
  componentTree: CanvasComponentTree,
): {
  theme: string;
  region: string;
  status: boolean;
  component_tree: CanvasComponentTree;
} {
  return {
    theme: spec.theme,
    region: spec.region,
    status: spec.status ?? true,
    component_tree: componentTree,
  };
}
