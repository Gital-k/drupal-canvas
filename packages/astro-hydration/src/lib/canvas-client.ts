/**
 * @file
 * The Canvas client renderer for Code Component islands.
 *
 * Wraps Astro's Preact client renderer so every island, including islands
 * nested through slots, renders inside the shared `CanvasContextProvider` and
 * `JsonApiClientProvider` from `drupal-canvas`. The providers add no DOM
 * wrapper, and component authors do not change their exports.
 *
 * `drupal-canvas` and `preact` are imported through the page's import map
 * (see astro.config.mjs), so the providers here and the hooks a component
 * imports share one context instance.
 *
 * @see docs/adr/0021-code-component-runtime-compatibility-across-frontend-modes.md
 * @see \Drupal\canvas\Element\AstroIsland
 */

import {
  CanvasContextProvider,
  createJsonApiClient,
  drupalSettingsToCanvasContext,
  drupalSettingsToJsonApiRuntimeConfig,
  JsonApiClientProvider,
  readCanvasDataV0,
} from 'drupal-canvas';
import { h } from 'preact';
import { useEffect, useReducer } from 'preact/hooks';
import preactRenderer from '@astrojs/preact/client.js';

import type { CanvasContext, CanvasJsonApiClient } from 'drupal-canvas';
import type { ComponentType, VNode } from 'preact';

// The providers are typed against React; Drupal maps `react` to
// `preact/compat`, so they render as Preact components here.
const ContextProvider = CanvasContextProvider as unknown as ComponentType<{
  context: CanvasContext;
}>;
const ClientProvider = JsonApiClientProvider as unknown as ComponentType<{
  client: CanvasJsonApiClient;
}>;

type ClientRenderer = (
  element: HTMLElement,
) => (
  Component: ComponentType<Record<string, unknown>>,
  props: Record<string, unknown>,
  slots: Record<string, string>,
  metadata: { client: string },
) => Promise<void>;

/** The attribute Drupal sets on preview islands. */
const PREVIEW_ATTRIBUTE = 'data-canvas-preview';

/**
 * One client per configuration and preview state, shared by every island on
 * the page while those stay unchanged.
 */
const clients = new Map<string, CanvasJsonApiClient>();

function getClient(preview: boolean): CanvasJsonApiClient | null {
  const config = drupalSettingsToJsonApiRuntimeConfig(readCanvasDataV0(), {
    preview,
  });
  if (config === null) {
    return null;
  }
  const key = JSON.stringify(config);
  let client = clients.get(key);
  if (!client) {
    // Drupal previews read working copies through the editor's own session:
    // same-origin requests carry the Drupal session cookie.
    client = createJsonApiClient({ ...config, credentials: 'same-origin' });
    clients.set(key, client);
  }
  return client;
}

/**
 * The event that announces updated `drupalSettings`; mounted islands re-read
 * the page and site data when it fires. Drupal's own settings updates (an
 * AJAX response merging settings and attaching behaviors) trigger it through
 * the behavior registered below; other code dispatches it on `window`.
 */
export const CANVAS_SETTINGS_UPDATED_EVENT = 'drupal-canvas:settings-updated';

const settingsListeners = new Set<() => void>();

/** Notifies every mounted island that `drupalSettings` changed. */
export function notifyCanvasSettingsUpdated(): void {
  for (const listener of settingsListeners) {
    listener();
  }
}

function subscribeToSettings(listener: () => void): () => void {
  settingsListeners.add(listener);
  return () => {
    settingsListeners.delete(listener);
  };
}

if (typeof window !== 'undefined') {
  window.addEventListener(CANVAS_SETTINGS_UPDATED_EVENT, () =>
    notifyCanvasSettingsUpdated(),
  );
  // Drupal attaches behaviors after every AJAX response, once the response's
  // settings are merged into `drupalSettings`: that is the settings update
  // signal on a Drupal page.
  const drupal = (
    globalThis as {
      Drupal?: { behaviors?: Record<string, { attach?: () => void }> };
    }
  ).Drupal;
  if (drupal?.behaviors && !drupal.behaviors.canvasIslandSettings) {
    drupal.behaviors.canvasIslandSettings = {
      attach: () => notifyCanvasSettingsUpdated(),
    };
  }
}

/**
 * Wrapped component types, one per island element and Code Component: the
 * wrapper reads the island's own preview state, so islands of the same
 * component on one page never share a wrapper, while a rerender of one
 * island keeps its component identity.
 */
const wrapped = new WeakMap<
  HTMLElement,
  WeakMap<
    ComponentType<Record<string, unknown>>,
    ComponentType<Record<string, unknown>>
  >
>();

/** Wraps a Code Component in the providers for one island element. */
export function wrapIslandComponent(
  Component: ComponentType<Record<string, unknown>>,
  element: HTMLElement,
): ComponentType<Record<string, unknown>> {
  let byComponent = wrapped.get(element);
  if (!byComponent) {
    byComponent = new WeakMap();
    wrapped.set(element, byComponent);
  }
  let Wrapped = byComponent.get(Component);
  if (Wrapped) {
    return Wrapped;
  }
  Wrapped = (props: Record<string, unknown>): VNode<unknown> => {
    // Re-render on settings updates, and read the settings on every render,
    // so a mounted island receives the current page and site data.
    const [, rerender] = useReducer((version: number) => version + 1, 0);
    useEffect(() => subscribeToSettings(() => rerender(undefined)), []);
    const context = drupalSettingsToCanvasContext(readCanvasDataV0());
    const client = getClient(element.hasAttribute(PREVIEW_ATTRIBUTE));
    const component = h(Component, props);
    return h(
      ContextProvider,
      { context },
      client === null ? component : h(ClientProvider, { client }, component),
    ) as VNode<unknown>;
  };
  Wrapped.displayName = `CanvasIsland(${Component.displayName ?? Component.name ?? 'Component'})`;
  byComponent.set(Component, Wrapped);
  return Wrapped;
}

const render: ClientRenderer = (element) => {
  const renderIsland = (preactRenderer as ClientRenderer)(element);
  return (Component, props, slots, metadata) =>
    renderIsland(
      wrapIslandComponent(Component, element),
      props,
      slots,
      metadata,
    );
};

export default render;
