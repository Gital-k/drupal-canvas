---
"@drupal-canvas/types": patch
---

Narrow `entityTypeLabels` in `DrupalSettings` to a bundle-keyed map

`CanvasController` now always emits a map of bundle labels, for entity types with and without a bundle entity type alike, so the `string` half of the union is unreachable.
