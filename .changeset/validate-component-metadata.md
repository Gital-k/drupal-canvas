---
"@drupal-canvas/cli": minor
"@drupal-canvas/eslint-config": minor
"@drupal-canvas/workbench": patch
---

Validate authored Code Component metadata with the Canvas JSON Schema contract.

- The CLI validates raw `component.yml` files, uses the authenticated target site's schema and non-mutating validation operations when available, and warns when validation falls back offline.
- Push preflights every complete Code Component payload before mutation when the target supports it.
- ESLint keeps project-convention rules and leaves metadata acceptance to JSON Schema and Drupal.
- Workbench schemas use the shared Canvas draft-07 dialect.
