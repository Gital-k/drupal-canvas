import fs from 'fs/promises';
import { loadComponentsMetadata } from '@drupal-canvas/discovery';

import { authoredElementMapToComponentTree } from './authored-elements';
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
  operation: 'Created' | 'Updated' | 'Deleted';
}

export interface PreparedRegion {
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
  status?: boolean;
  elements?: AuthoredSpecElementMap;
}

/**
 * Reads each region file and converts the authored elements to a component
 * tree. The region's machine name is sourced from the filename; the theme is
 * implicit and resolved by the server from the site's default theme.
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

    const pendingMediaReconciliations = collectUnreconciledMediaProps(
      spec.elements ?? {},
      componentMetadata,
    );
    const elements = serializeElementMapForServer(
      spec.elements ?? {},
      componentMetadata,
    );
    const components = authoredElementMapToComponentTree(
      elements,
      componentVersions,
    );

    return {
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
 * Sync prepared regions with the server: create new ones, update existing
 * ones, and delete remote regions that are absent locally.
 */
export async function pushRegions(
  preparedRegions: Array<{ index: number; result: PreparedRegion }>,
  remoteIdsByShortName: Map<string, string>,
  apiService: Pick<
    ApiService,
    'createRegion' | 'updateRegion' | 'deleteRegion'
  >,
  remoteIdsToDelete: string[] = [],
): Promise<
  Array<{
    success: boolean;
    result?: RegionPushResult;
    error?: Error;
    index: number;
  }>
> {
  const upsertResults = await processInPool(preparedRegions, async (entry) => {
    const region = entry.result;
    const remoteId = remoteIdsByShortName.get(region.region);

    const component_tree = stripNullableKeysForRegion(region.components);

    if (remoteId) {
      await apiService.updateRegion(remoteId, {
        status: region.status,
        component_tree,
      });
      return { id: remoteId, operation: 'Updated' as const };
    }

    // Omit `theme`: the server fills it in from the site's default theme.
    const created = await apiService.createRegion({
      region: region.region,
      status: region.status,
      component_tree,
    });
    return { id: created.id, operation: 'Created' as const };
  });

  if (remoteIdsToDelete.length === 0) {
    return upsertResults;
  }

  const deleteResults = await processInPool(remoteIdsToDelete, async (id) => {
    await apiService.deleteRegion(id);
    return { id, operation: 'Deleted' as const };
  });

  return [
    ...upsertResults,
    ...deleteResults.map((r) => ({
      ...r,
      result:
        r.result ??
        ({
          id: remoteIdsToDelete[r.index],
          operation: 'Deleted' as const,
        } satisfies RegionPushResult),
    })),
  ];
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
      const id =
        result.result?.id ??
        discoveredRegions[result.index]?.region ??
        'unknown';
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
