---
'drupal-canvas': minor
---

Add the shared runtime APIs for React Code Components that run in Drupal and in
React-based headless frontends.

- `usePageContext()` and `useSiteContext()` replace `getPageData()` and
  `getSiteData()`; `CanvasContextProvider` supplies the values, and the
  `PageContext`, `SiteContext`, and `CanvasContext` types describe them.
- `useJsonApiClient()` and `JsonApiClientProvider` replace `new JsonApiClient()`
  in components; `createJsonApiClient()` (from `drupal-canvas/jsonapi-client`)
  is the shared, framework-agnostic client with draft reads at a resource
  version, same-origin proxy mapping, `DraftSessionError`, and scoped caches.
- `getPageData()`, `getSiteData()`, and `new JsonApiClient()` are deprecated.
  They keep working in Drupal-rendered Code Components and Canvas Workbench
  previews and throw an actionable migration error elsewhere.
- `Region` and `RegionsProvider` are deprecated in favor of page variants.
