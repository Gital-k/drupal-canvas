import fs from 'fs/promises';
import { loadComponentsMetadata } from '@drupal-canvas/discovery';

import { authoredSpecToComponentTree } from './pages';
import {
  collectUnreconciledMediaProps,
  serializeElementMapForServer,
} from './prop-transforms';
import { processInPool } from './request-pool';

import type {
  DiscoveredRegion,
  DiscoveryResult,
} from '@drupal-canvas/discovery';
import type {
  AuthoredSpecElementMap,
  CanvasComponentTree,
} from 'drupal-canvas/json-render-utils';
import type { ApiService } from '../services/api';
import type { Region, RegionComponentTreeNodePayload } from '../types/Region';
import type { Result } from '../types/Result';

/**
 * PageRegion is a config entity; its component_tree schema rejects null
 * values for `parent_uuid`, `slot`, and `label` (unlike the page content
 * entity, which tolerates nulls). Omit these keys when they have no value
 * so the server-side config schema validation accepts the payload.
 */
export function stripNullableKeysForRegion(
  components: CanvasComponentTree,
): RegionComponentTreeNodePayload[] {
  return components.map((node) => {
    const result: RegionComponentTreeNodePayload = {
      uuid: node.uuid,
      component_id: node.component_id,
      component_version: node.component_version ?? '',
      inputs: node.inputs,
    };
    if (node.parent_uuid !== null && node.parent_uuid !== undefined) {
      result.parent_uuid = node.parent_uuid;
    }
    if (node.slot !== null && node.slot !== undefined) {
      result.slot = node.slot;
    }
    if (node.label !== null && node.label !== undefined && node.label !== '') {
      result.label = node.label;
    }
    return result;
  });
}

export interface RegionPushResult {
  id: string;
  operation: 'Created' | 'Updated';
}

export interface PreparedRegion {
  id: string;
  theme: string;
  region: string;
  status: boolean;
  components: Region['component_tree'];
  filePath: string;
  pendingMediaReconciliations: Array<{
    elementId: string;
    propName: string;
    src: string;
    mediaType: string;
  }>;
}

interface AuthoredRegionFile {
  theme?: string;
  region?: string;
  status?: boolean;
  elements?: AuthoredSpecElementMap;
}

/**
 * Reads each region file, validates that the filename matches the spec's
 * region, and converts the authored elements to a component tree.
 */
export async function prepareRegions(
  discoveredRegions: DiscoveredRegion[],
  componentVersions: Map<string, string>,
  discoveryResult: DiscoveryResult,
): Promise<{
  valid: Array<{ index: number; result: PreparedRegion }>;
  failed: Array<{ index: number; error: Error }>;
}> {
  const componentMetadata = await loadComponentsMetadata(discoveryResult);

  const results = await processInPool(discoveredRegions, async (discovered) => {
    const fileContent = await fs.readFile(discovered.path, 'utf-8');
    const spec = JSON.parse(fileContent) as AuthoredRegionFile;

    if (!spec.theme) {
      throw new Error(
        `Missing "theme" in ${discovered.relativePath}: the region file must include a theme key.`,
      );
    }
    if (spec.region !== undefined && spec.region !== discovered.region) {
      throw new Error(
        `Region mismatch in ${discovered.relativePath}: filename declares "${discovered.region}" but spec declares "${spec.region}".`,
      );
    }

    const pendingMediaReconciliations = collectUnreconciledMediaProps(
      spec.elements ?? {},
      componentMetadata,
    );
    const elements = serializeElementMapForServer(
      spec.elements ?? {},
      componentMetadata,
    );
    const components = authoredSpecToComponentTree(elements, componentVersions);

    return {
      id: `${spec.theme}.${discovered.region}`,
      theme: spec.theme,
      region: discovered.region,
      status: spec.status ?? true,
      components,
      filePath: discovered.path,
      pendingMediaReconciliations,
    };
  });

  return {
    valid: results
      .filter((r) => r.success && r.result)
      .map((r) => ({ index: r.index, result: r.result! })),
    failed: results
      .filter((r) => !r.success)
      .map((r) => ({ index: r.index, error: r.error! })),
  };
}

/**
 * Sync prepared regions with the server: create new ones, update existing ones.
 *
 * Regions are not deleted: the set of regions is determined by the theme,
 * not by which files happen to live locally. To stop Canvas from managing a
 * region, set its `status` to false in the local file.
 */
export async function pushRegions(
  preparedRegions: Array<{ index: number; result: PreparedRegion }>,
  remoteIds: Set<string>,
  apiService: Pick<ApiService, 'createRegion' | 'updateRegion'>,
): Promise<
  Array<{
    success: boolean;
    result?: RegionPushResult;
    error?: Error;
    index: number;
  }>
> {
  return processInPool(preparedRegions, async (entry) => {
    const region = entry.result;
    const isRemote = remoteIds.has(region.id);

    const component_tree = stripNullableKeysForRegion(region.components);

    if (isRemote) {
      await apiService.updateRegion(region.id, {
        status: region.status,
        component_tree,
      });
      return { id: region.id, operation: 'Updated' as const };
    }

    await apiService.createRegion({
      theme: region.theme,
      region: region.region,
      status: region.status,
      component_tree,
    });
    return { id: region.id, operation: 'Created' as const };
  });
}

export function collectRegionResults(
  pushResults: Array<{
    success: boolean;
    result?: RegionPushResult;
    error?: Error;
    index: number;
  }>,
  failedPreps: Array<{ index: number; error: Error }>,
  discoveredRegions: DiscoveredRegion[],
): Result[] {
  const results: Result[] = [];

  for (const result of pushResults) {
    if (result.success && result.result) {
      results.push({
        itemName: result.result.id,
        success: true,
        details: [{ content: result.result.operation }],
      });
    } else {
      const id = discoveredRegions[result.index]?.region || 'unknown';
      results.push({
        itemName: id,
        success: false,
        details: [{ content: result.error?.message || 'Unknown error' }],
      });
    }
  }

  for (const failedPrep of failedPreps) {
    const id = discoveredRegions[failedPrep.index]?.region || 'unknown';
    results.push({
      itemName: id,
      success: false,
      details: [
        {
          content: failedPrep.error?.message || 'Failed to prepare region',
        },
      ],
    });
  }

  return results;
}
