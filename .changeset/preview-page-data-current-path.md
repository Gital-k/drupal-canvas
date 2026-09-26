---
"drupal-canvas": minor
---

Add `currentPath` to `getPageData()`, and fix `getPageData()`/`getSiteData()` silently failing to report their data fetches to the parent window.

- `getPageData()` now returns a `currentPath` field: the current page's path, or `null` where there is no real current page, such as in the code editor's own preview.
- `getPageData()` and `getSiteData()` previously omitted `postMessage()`'s target origin, which the browser silently drops when called from a sandboxed, opaque-origin context (such as the code editor's preview iframe). Both now explicitly target `'*'`.
