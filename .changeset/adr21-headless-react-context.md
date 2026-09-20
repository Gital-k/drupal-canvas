---
'@drupal-canvas/headless-react': minor
---

`CanvasComponentTree` accepts `context` (from `fetchPage()`'s `page.context`)
and `jsonApi` (from the SDK's `getJsonApiRuntimeConfig()`) props and mounts
the `drupal-canvas` context and JSON:API client providers for registered
components. An explicit `context` prop takes precedence over an outer
`CanvasContextProvider`; without either, the hooks report missing context.
The renderer also accepts `jsonApiFetch`, a transport for the client created
from `jsonApi`, so framework adapters can supply a request-aware server-side
transport without the generic renderer importing server code.
