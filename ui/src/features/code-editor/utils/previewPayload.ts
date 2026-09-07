/**
 * Safely serializes a value for `postMessage()` to the preview iframe.
 *
 * The caller may pass live references to `window`-owned objects (e.g.
 * `drupalSettings`). Other code on the page (e.g. Drupal core's jQuery UI
 * dialog behaviors) can attach non-serializable values to such objects, such
 * as a live close-handler function, which would otherwise make
 * `postMessage()` throw a `DataCloneError`. A JSON round-trip strips anything
 * that can't be structured-cloned; if the value can't even be JSON-serialized
 * (e.g. a circular reference), an empty object is sent instead.
 *
 * @see ui/src/features/code-editor/Preview.tsx
 * @see ui/lib/code-editor-preview.js
 */
export function safeSerializeDrupalSettings<T extends object>(value: T): T {
  try {
    return JSON.parse(JSON.stringify(value));
  } catch {
    return {} as T;
  }
}

// Path patterns the preview's proxied `fetch()` requests may target. The
// preview iframe's opaque origin means it can never authenticate its own
// requests, so the parent performs them instead, with its own real session —
// this allowlist is what stops that from becoming a proxy for arbitrary
// admin-authenticated requests on a compiled component's behalf.
// @see ui/lib/code-editor-preview.js
const ALLOWED_PREVIEW_FETCH_PATTERNS = [
  /^\/jsonapi\//,
  /^\/system\/menu\/[^/]+\/linkset$/,
];

/**
 * Whether a URL requested by the preview iframe is safe to fetch on its behalf.
 *
 * @param url
 *   The requested URL, as sent by the preview iframe.
 *
 * @return
 *   Whether the URL's path matches an allowed pattern.
 */
export function isAllowedPreviewFetchUrl(url: string): boolean {
  let pathname;
  try {
    pathname = new URL(url, window.location.origin).pathname;
  } catch {
    return false;
  }
  return ALLOWED_PREVIEW_FETCH_PATTERNS.some((pattern) =>
    pattern.test(pathname),
  );
}
