---
"@drupal-canvas/cli": minor
"@drupal-canvas/eslint-config": minor
"@drupal-canvas/headless": patch
"@drupal-canvas/workbench": patch
---

Validate authored Code Component metadata with the Canvas JSON Schema contract.

- The CLI validates raw `component.yml` envelopes and directly resolvable prop schemas locally. It uses the authenticated target site's non-mutating validation operation when available and warns when target acceptance was not validated.
- Push preflights every complete Code Component payload before mutation when the target supports it.
- The required ESLint config validates portable authoring rules and project conventions, while Drupal owns Canvas-specific metadata acceptance.
- Discovery, Workbench, and headless consumers validate authored metadata envelopes with the shared schema, while Workbench surfaces source-located metadata errors and content entity reference previews derive targets from `dataDependencies.entityFields`.
