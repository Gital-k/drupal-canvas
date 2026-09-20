---
'@drupal-canvas/headless-next': minor
---

`CanvasComponentTree` from `@drupal-canvas/headless-next/CanvasComponentTree`
is now a server component that supplies the request's JSON:API runtime
configuration automatically; render it from server components. Client
components use the new `CanvasComponentTreeClient` export and pass `jsonApi`
themselves.
