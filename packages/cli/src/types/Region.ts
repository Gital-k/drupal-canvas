import type { CanvasComponentTree } from 'drupal-canvas/json-render-utils';

/**
 * Wire shape returned by the Drupal config CRUD endpoints for a region.
 * Mirrors `\Drupal\canvas\Entity\PageRegion::normalizeForClientSide()`.
 */
export interface Region {
  id: string;
  theme: string;
  region: string;
  status: boolean;
  component_tree: CanvasComponentTree;
}

/**
 * The list endpoint returns the same shape as get for each entry, so list
 * items already carry the component tree.
 */
export type RegionListItem = Region;

/**
 * Wire-format component tree node for POSTing/PATCHing a region.
 *
 * PageRegion is a config entity; its component_tree config schema rejects
 * null values for `parent_uuid`, `slot`, and `label` (it tolerates the keys
 * being absent, but not present-but-null). Omit them rather than sending
 * null.
 */
export interface RegionComponentTreeNodePayload {
  uuid: string;
  component_id: string;
  component_version: string;
  inputs: Record<string, unknown>;
  parent_uuid?: string;
  slot?: string;
  label?: string;
}

export type RegionComponentTreePayload = RegionComponentTreeNodePayload[];
