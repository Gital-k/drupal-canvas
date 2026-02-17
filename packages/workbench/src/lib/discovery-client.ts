export type {
  DiscoveredComponent,
  DiscoveryResult,
  DiscoveryWarning,
} from '@drupal-canvas/component-discovery';
import type { DiscoveryResult } from '@drupal-canvas/component-discovery';
import type { PreviewManifest } from './preview-contract';

export async function fetchDiscoveryResult(): Promise<DiscoveryResult> {
  const response = await fetch('/__canvas/discovery');

  if (!response.ok) {
    throw new Error(`Discovery request failed with status ${response.status}.`);
  }

  const data = (await response.json()) as DiscoveryResult;
  return data;
}

export async function fetchPreviewManifest(): Promise<PreviewManifest> {
  const response = await fetch('/__canvas/preview-manifest');

  if (!response.ok) {
    throw new Error(
      `Preview manifest request failed with status ${response.status}.`,
    );
  }

  const data = (await response.json()) as PreviewManifest;
  return data;
}
