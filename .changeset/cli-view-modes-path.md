---
"@drupal-canvas/cli": patch
---

Follow the renamed content template view modes endpoint

`listViewModes()` requested `/canvas/api/v0/ui/content_template/view_modes/node`, which no longer
exists: the route is now `/canvas/api/v0/ui/content_template/view_modes` and returns view modes for
every entity type that supports content templates. The return type is unchanged.
