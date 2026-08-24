---
"@drupal-canvas/cli": minor
"@drupal-canvas/eslint-config": minor
"@drupal-canvas/workbench": patch
---

Validate authored Code Component metadata with the Canvas JSON Schema contract.

- The CLI validates raw `component.yml` envelopes and directly resolvable prop schemas locally. It uses the authenticated target site's non-mutating validation operation when available and warns when target acceptance was not validated.
- Push preflights every complete Code Component payload before mutation when the target supports it.
- The required ESLint config validates portable authoring rules and project conventions, while Drupal owns Canvas-specific metadata acceptance.
- Workbench schemas use the shared Canvas draft-07 dialect.
