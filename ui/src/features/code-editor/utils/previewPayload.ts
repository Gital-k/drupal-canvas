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
