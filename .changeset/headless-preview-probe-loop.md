---
"@drupal-canvas/headless": patch
---

Stop re-probing elements a height probe has already ruled out as
viewport-relative. Previews no longer resize and flash continuously when the
app writes to the DOM shortly after a window resize, as MUI's `Drawer` and
`Popover` do.
