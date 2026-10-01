---
"@drupal-canvas/types": patch
---

Add `contentTemplateEntityTypes` to `DrupalSettings` to support content templates for any entity type with a `field_ui_base_route`, not just nodes.

Narrow `entityTypeLabels` in `DrupalSettings` to a bundle-keyed map: `CanvasController` now always emits a map of bundle labels, so the `string` half of the union is unreachable.
