---
'@drupal-canvas/headless-next': minor
'@drupal-canvas/headless-tanstack-start': minor
'@drupal-canvas/headless-nuxt': minor
'@drupal-canvas/headless-astro': minor
---

Mount the same-origin JSON:API proxy for portable Code Components and expose
the JSON:API runtime configuration (`getJsonApiRuntimeConfig()`) browser
clients are created from. The React renderers accept `context={page.context}`
so `usePageContext()` and `useSiteContext()` work in headless components.
In TanStack Start, server rendering uses a request-aware JSON:API client: the
adapter's `CanvasComponentTree` answers the client's proxy-mapped requests
in-process through the proxy handler with the current request's draft session,
so `useJsonApiClient()` is the same draft-aware client on both sides of
hydration and SWR fallback data renders into the initial HTML.

The TanStack Start server transport honors explicit `redirect` modes and
re-sends `Request` bodies on redirects that keep the method.
