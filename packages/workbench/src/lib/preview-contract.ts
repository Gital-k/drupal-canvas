import {
  isSupportedPreviewModulePath,
  toViteFsUrl,
} from '@drupal-canvas/vite-compat/runtime';

import type { Spec } from '@json-render/core';
import type { DiscoveryResult, DiscoveryWarning } from './discovery-client';

export type PreviewIneligibilityReason =
  | 'missing_js_entry'
  | 'unsupported_js_extension';

export interface PreviewManifestComponent {
  id: string;
  kind: 'named' | 'index';
  name: string;
  relativeDirectory: string;
  metadataPath: string;
  jsEntryPath: string | null;
  cssEntryPath: string | null;
  previewable: boolean;
  ineligibilityReason: PreviewIneligibilityReason | null;
  moduleUrl: string | null;
  cssUrl: string | null;
  exampleProps: Record<string, unknown>;
}

export interface PreviewManifest {
  scanRoot: string;
  components: PreviewManifestComponent[];
  warnings: DiscoveryWarning[];
  globalCssUrl: string | null;
}

export interface PreviewRenderRequest {
  source: 'canvas-workbench-parent';
  type: 'preview:render';
  payload: {
    renderId: string;
    spec: Spec;
    registrySources: Array<{
      name: string;
      jsEntryUrl: string;
    }>;
    cssUrls: string[];
  };
}

export interface PreviewFrameReady {
  source: 'canvas-workbench-frame';
  type: 'preview:ready';
}

export interface PreviewFrameRendered {
  source: 'canvas-workbench-frame';
  type: 'preview:rendered';
  payload: {
    type: 'component' | 'page';
    renderId: string;
  };
}

export interface PreviewFrameError {
  source: 'canvas-workbench-frame';
  type: 'preview:error';
  payload: {
    renderId: string | null;
    message: string;
  };
}

export type PreviewFrameEvent =
  | PreviewFrameReady
  | PreviewFrameRendered
  | PreviewFrameError;

export function toPreviewManifestComponent(component: {
  id: string;
  kind: 'named' | 'index';
  name: string;
  relativeDirectory: string;
  metadataPath: string;
  jsEntryPath: string | null;
  cssEntryPath: string | null;
}): PreviewManifestComponent {
  if (!component.jsEntryPath) {
    return {
      ...component,
      previewable: false,
      ineligibilityReason: 'missing_js_entry',
      moduleUrl: null,
      cssUrl: null,
      exampleProps: {},
    };
  }

  if (!isSupportedPreviewModulePath(component.jsEntryPath)) {
    return {
      ...component,
      previewable: false,
      ineligibilityReason: 'unsupported_js_extension',
      moduleUrl: null,
      cssUrl: null,
      exampleProps: {},
    };
  }

  return {
    ...component,
    previewable: true,
    ineligibilityReason: null,
    moduleUrl: toViteFsUrl(component.jsEntryPath),
    cssUrl: component.cssEntryPath ? toViteFsUrl(component.cssEntryPath) : null,
    exampleProps: {},
  };
}

export function buildPreviewManifest(
  discoveryResult: DiscoveryResult,
): PreviewManifest {
  return {
    scanRoot: discoveryResult.scanRoot,
    components: discoveryResult.components.map(toPreviewManifestComponent),
    warnings: discoveryResult.warnings,
    globalCssUrl: null,
  };
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

export function isPreviewRenderRequest(
  value: unknown,
): value is PreviewRenderRequest {
  if (!isRecord(value)) {
    return false;
  }

  if (
    value.source !== 'canvas-workbench-parent' ||
    value.type !== 'preview:render'
  ) {
    return false;
  }

  if (!isRecord(value.payload)) {
    return false;
  }

  const { renderId, spec, registrySources, cssUrls } = value.payload;
  return (
    typeof renderId === 'string' &&
    isRecord(spec) &&
    typeof spec.root === 'string' &&
    isRecord(spec.elements) &&
    Array.isArray(registrySources) &&
    registrySources.every(
      (source) =>
        isRecord(source) &&
        typeof source.name === 'string' &&
        typeof source.jsEntryUrl === 'string',
    ) &&
    Array.isArray(cssUrls) &&
    cssUrls.every((url) => typeof url === 'string')
  );
}

export function isPreviewFrameEvent(
  value: unknown,
): value is PreviewFrameEvent {
  if (!isRecord(value) || value.source !== 'canvas-workbench-frame') {
    return false;
  }

  if (value.type === 'preview:ready') {
    return true;
  }

  if (value.type === 'preview:rendered') {
    return (
      isRecord(value.payload) &&
      (value.payload.type === 'component' || value.payload.type === 'page') &&
      typeof value.payload.renderId === 'string'
    );
  }

  if (value.type === 'preview:error') {
    return (
      isRecord(value.payload) &&
      (typeof value.payload.renderId === 'string' ||
        value.payload.renderId === null) &&
      typeof value.payload.message === 'string'
    );
  }

  return false;
}
