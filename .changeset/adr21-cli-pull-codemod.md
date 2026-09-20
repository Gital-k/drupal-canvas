---
'@drupal-canvas/cli': minor
---

`canvas pull` migrates safe `getPageData()` and `getSiteData()` calls in
pulled components to the `usePageContext()` and `useSiteContext()` hooks from
`drupal-canvas`, when the connected site advertises context-hook support and
the project's installed `drupal-canvas` package exports both hooks. The
migration is all-or-nothing per file: a call must be an imported binding
called without arguments at an unconditional hook position in a component or
custom hook, with no possible earlier return and no identifier conflicts.
Planned conversions show before the pull is confirmed and honor `--yes` and
`--skip-overwrite`; remaining cases, `new JsonApiClient()` construction, and
helper modules are reported with an AI-agent migration prompt.
