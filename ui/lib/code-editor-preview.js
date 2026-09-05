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

import { h, render } from 'preact';

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

// Guards against a stale response: a newer message may arrive and finish
// importing while an older message's imports are still in flight.
let latestRequestId = 0;

window.addEventListener('message', async (event) => {
  // The parent window is the only legitimate sender.
  if (!isFromParentWindow(event)) {
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

    render(
      h(mainModule.default, propsAndSlots),
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
