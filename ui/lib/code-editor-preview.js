/**
 * @file
 * Renders a code editor preview: used inside the code editor preview iframe.
 *
 * This script runs inside a sandboxed iframe (sandbox="allow-scripts", no
 * allow-same-origin) with an opaque origin, loaded from a fixed Drupal route
 * (@see \Drupal\canvas\Controller\CodeEditorPreviewController). The parent
 * window sends the dynamic per-render payload via `postMessage()` instead of
 * embedding it in the document, since the opaque origin cannot authenticate a
 * network request back to the parent's site.
 *
 * The payload is an object with the following shape:
 *
 * @code
 * {
 *   compiledJs: '...',
 *   compiledJsForSlots: '...',
 *   otherComponents: { [machineName]: { compiledJs: '...', compiledCss: '...' } },
 *   previewCss: '...',
 *   previewGlobalCss: '...',
 *   previewGlobalColorCss: '...',
 *   previewGlobalFontCss: '...',
 *   previewGlobalFontPreloads: [{ href: '...', type: '...' }],
 *   propValues: {...},
 *   slotNames: [...],
 *   drupalSettings: {...},
 * }
 * @endcode
 *
 * `otherComponents` covers every other auto-saved code component: `compiledJs`
 * may `import` any of them via a `@/components/{machineName}` bare specifier,
 * which this script rewrites to a same-document blob: URL before importing it
 * (see `rewriteComponentImports()` below), rather than relying on the import
 * map's network-backed `@/components/` entry, which an opaque origin can't
 * authenticate.
 *
 * This file is copied to the dist directory by Vite when the app is built.
 * @see ui/vite.config.ts
 * @see ui/src/features/code-editor/Preview.tsx
 */

import {
  createJsonApiClient,
  declareCanvasRuntime,
  drupalSettingsToCanvasContext,
  drupalSettingsToJsonApiRuntimeConfig,
  readCanvasDataV0,
} from 'drupal-canvas';
import {
  CanvasContextProvider,
  JsonApiClientProvider,
} from 'drupal-canvas/react';
import { h, render } from 'preact';

// The code editor preview is a Drupal environment for the legacy runtime APIs
// (`getPageData()`, `getSiteData()`, `new JsonApiClient()`). Declare it before
// the component modules are imported below.
declareCanvasRuntime('drupal');

/**
 * Gets (creating if needed) a `<style>` element in `<head>` with the given id.
 *
 * @param {string} id
 *   The element's id.
 *
 * @return {HTMLStyleElement}
 *   The style element.
 */
function getOrCreateStyleElement(id) {
  let element = document.getElementById(id);
  if (!(element instanceof HTMLStyleElement)) {
    element = document.createElement('style');
    element.id = id;
    document.head.appendChild(element);
  }
  return element;
}

/**
 * Replaces the font preload `<link>` elements with the given definitions.
 *
 * @param {Array<{href: string, type: string}>} fontPreloads
 *   Font preload definitions.
 */
function setFontPreloads(fontPreloads) {
  document
    .querySelectorAll('link[data-canvas-font-preload]')
    .forEach((element) => element.remove());
  for (const { href, type } of fontPreloads ?? []) {
    const link = document.createElement('link');
    link.rel = 'preload';
    link.as = 'font';
    link.type = type;
    link.href = href;
    link.crossOrigin = 'anonymous';
    link.dataset.canvasFontPreload = '';
    document.head.appendChild(link);
  }
}

/**
 * Replaces the other-components' CSS `<style>` elements with the given ones.
 *
 * @param {Record<string, {compiledCss: string}>} otherComponents
 *   Other auto-saved code components, keyed by machine name.
 */
function setOtherComponentsCss(otherComponents) {
  document
    .querySelectorAll('style[data-canvas-other-component-css]')
    .forEach((element) => element.remove());
  for (const [name, { compiledCss }] of Object.entries(otherComponents ?? {})) {
    if (!compiledCss) {
      continue;
    }
    const style = document.createElement('style');
    style.dataset.canvasOtherComponentCss = name;
    style.textContent = compiledCss;
    document.head.appendChild(style);
  }
}

/**
 * Rewrites `@/components/{name}` import specifiers to blob: URLs.
 *
 * @param {string} js
 *   Compiled JS source text.
 * @param {Record<string, string>} otherComponentUrls
 *   Blob URLs for other components' compiled JS, keyed by machine name.
 *
 * @return {string}
 *   The rewritten JS source text.
 */
export function rewriteComponentImports(js, otherComponentUrls) {
  return Object.entries(otherComponentUrls).reduce(
    (code, [name, url]) =>
      code
        .replaceAll(`'@/components/${name}'`, `'${url}'`)
        .replaceAll(`"@/components/${name}"`, `"${url}"`),
    js,
  );
}

/**
 * Whether a `message` event actually came from the parent window.
 *
 * This holds regardless of this document's own opaque origin, since
 * `window.parent` is one of the few properties accessible across an
 * opaque/cross-origin boundary.
 *
 * @param {MessageEvent} event
 *   The `message` event to check.
 *
 * @return {boolean}
 *   Whether the event's source is `window.parent`.
 */
export function isFromParentWindow(event) {
  return event.source === window.parent;
}

/**
 * Extracts the preview payload from a `message` event, if it's complete.
 *
 * @param {MessageEvent} event
 *   The `message` event to read.
 *
 * @return {?object}
 *   The event's `data`, or `null` if a required property is missing.
 */
export function getCompletePreviewPayload(event) {
  const data = event.data ?? {};
  const {
    compiledJs,
    compiledJsForSlots,
    propValues,
    slotNames,
    drupalSettings,
  } = data;
  if (
    !compiledJs ||
    compiledJsForSlots === undefined ||
    !propValues ||
    !slotNames ||
    !drupalSettings
  ) {
    return null;
  }
  return data;
}

/**
 * Builds the outgoing message for a proxied `fetch()` request.
 *
 * @param {number} id
 *   A unique id to correlate the eventual response.
 * @param {string} url
 *   The requested URL.
 * @param {string} method
 *   The requested HTTP method.
 *
 * @return {object}
 *   The message to `postMessage()` to the parent.
 */
export function buildPreviewFetchRequestMessage(id, url, method) {
  return { type: '_canvas_preview_fetch_request', id, url, method };
}

/**
 * Reconstructs a `Response` from a proxied fetch's relayed payload.
 *
 * @param {{status?: number, headers?: Record<string, string>, body?: string}} payload
 *   The relayed response data.
 *
 * @return {Response}
 *   A `Response` equivalent to what the real, parent-side fetch received.
 */
export function reconstructPreviewFetchResponse(payload) {
  return new Response(payload.body ?? '', {
    status: payload.status ?? 502,
    headers: payload.headers ?? {},
  });
}

/**
 * Builds the message announcing this document's listener is registered.
 *
 * A sandboxed, opaque-origin iframe's navigation to the shell URL can make
 * the browser swap it through more than one internal frame before settling,
 * each running its own copy of this script. Rather than have the parent
 * infer readiness from the iframe element's `onload` DOM event, which fires
 * once per swap and can't tell which firing was the last one, each instance
 * announces its own readiness; whichever instance survives is also the last
 * to announce itself, so the parent always ends up targeting a live window.
 *
 * @return {object}
 *   The message to `postMessage()` to the parent.
 *
 * @see ui/src/features/code-editor/Preview.tsx
 */
export function buildPreviewReadyMessage() {
  return { type: '_canvas_preview_ready' };
}

// This document's opaque origin means `fetch()` can never carry the parent
// site's session cookie, so a compiled component's own `fetch()` calls (e.g.
// JSON:API lookups) would otherwise always fail with a CORS error. Proxy them
// through the parent instead: the parent performs the real, authenticated
// fetch (subject to its own allowlist) and relays the result back.
// @see ui/src/features/code-editor/Preview.tsx
const PREVIEW_FETCH_TIMEOUT_MS = 15_000;
const pendingPreviewFetches = new Map();
let latestPreviewFetchRequestId = 0;

window.fetch = (input, init) => {
  const url =
    typeof input === 'string' || input instanceof URL
      ? String(input)
      : input.url;
  const method = (
    init?.method ??
    (typeof input === 'object' && 'method' in input ? input.method : null) ??
    'GET'
  ).toUpperCase();
  const id = ++latestPreviewFetchRequestId;
  return new Promise((resolve, reject) => {
    const timeoutId = setTimeout(() => {
      pendingPreviewFetches.delete(id);
      reject(
        new TypeError(
          `Preview fetch proxy timed out waiting for a response to ${url}`,
        ),
      );
    }, PREVIEW_FETCH_TIMEOUT_MS);
    pendingPreviewFetches.set(id, { resolve, timeoutId });
    window.parent.postMessage(
      buildPreviewFetchRequestMessage(id, url, method),
      '*',
    );
  });
};

window.addEventListener('message', (event) => {
  if (
    !isFromParentWindow(event) ||
    event.data?.type !== '_canvas_preview_fetch_response'
  ) {
    return;
  }
  const pending = pendingPreviewFetches.get(event.data.id);
  if (!pending) {
    return;
  }
  clearTimeout(pending.timeoutId);
  pendingPreviewFetches.delete(event.data.id);
  pending.resolve(reconstructPreviewFetchResponse(event.data));
});

// Guards against a stale response: a newer message may arrive and finish
// importing while an older message's imports are still in flight.
let latestRequestId = 0;

window.addEventListener('message', async (event) => {
  // The parent window is the only legitimate sender.
  if (!isFromParentWindow(event)) {
    return;
  }

  // Handled by the fetch-proxy listener above.
  if (event.data?.type === '_canvas_preview_fetch_response') {
    return;
  }

  const payload = getCompletePreviewPayload(event);
  if (!payload) {
    console.error('Received incomplete code editor preview data.', event.data);
    return;
  }

  const {
    compiledJs,
    compiledJsForSlots,
    otherComponents,
    previewCss,
    previewGlobalCss,
    previewGlobalColorCss,
    previewGlobalFontCss,
    previewGlobalFontPreloads,
    propValues,
    slotNames,
    drupalSettings,
  } = payload;

  const requestId = ++latestRequestId;

  window.drupalSettings = drupalSettings;

  getOrCreateStyleElement('canvas-preview-global-font-css').textContent =
    previewGlobalFontCss ?? '';
  getOrCreateStyleElement('canvas-preview-global-color-css').textContent =
    previewGlobalColorCss ?? '';
  getOrCreateStyleElement('canvas-preview-global-css').textContent =
    previewGlobalCss ?? '';
  getOrCreateStyleElement('canvas-preview-css').textContent = previewCss ?? '';
  setFontPreloads(previewGlobalFontPreloads);
  setOtherComponentsCss(otherComponents);

  const otherComponentUrls = {};
  for (const [name, other] of Object.entries(otherComponents ?? {})) {
    if (!other.compiledJs) {
      continue;
    }
    otherComponentUrls[name] = URL.createObjectURL(
      new Blob([other.compiledJs], { type: 'text/javascript' }),
    );
  }

  const compiledJsUrl = URL.createObjectURL(
    new Blob([rewriteComponentImports(compiledJs, otherComponentUrls)], {
      type: 'text/javascript',
    }),
  );
  const compiledJsForSlotsUrl = URL.createObjectURL(
    new Blob(
      [rewriteComponentImports(compiledJsForSlots, otherComponentUrls)],
      { type: 'text/javascript' },
    ),
  );

  try {
    const [mainModule, slotsModule] = await Promise.all([
      import(compiledJsUrl),
      import(compiledJsForSlotsUrl),
    ]);

    // A newer message arrived while the above was in flight: let its render
    // win instead of stomping on it with this stale one.
    if (requestId !== latestRequestId) {
      return;
    }

    const propsAndSlots = {
      ...propValues,
      ...slotNames.reduce((acc, name) => {
        acc[name] = h(slotsModule[name]);
        return acc;
      }, {}),
    };

    // Render the component inside the same context providers Drupal islands
    // use, with the editor's preview data (see CanvasController) as the page
    // context. The client reads working copies through the editor's session,
    // via the parent-side `fetch()` proxy above.
    const canvasData = readCanvasDataV0(drupalSettings);
    const jsonApiConfig = drupalSettingsToJsonApiRuntimeConfig(canvasData, {
      preview: true,
    });
    let component = h(mainModule.default, propsAndSlots);
    if (jsonApiConfig) {
      component = h(
        JsonApiClientProvider,
        {
          client: createJsonApiClient({
            ...jsonApiConfig,
            credentials: 'same-origin',
          }),
        },
        component,
      );
    }
    render(
      h(
        CanvasContextProvider,
        { context: drupalSettingsToCanvasContext(canvasData) },
        component,
      ),
      document.getElementById('canvas-code-editor-preview-root'),
    );
  } finally {
    URL.revokeObjectURL(compiledJsUrl);
    URL.revokeObjectURL(compiledJsForSlotsUrl);
    Object.values(otherComponentUrls).forEach((url) =>
      URL.revokeObjectURL(url),
    );
  }
});

// Both `message` listeners above are now registered: announce readiness.
// @see buildPreviewReadyMessage()
window.parent.postMessage(buildPreviewReadyMessage(), '*');
