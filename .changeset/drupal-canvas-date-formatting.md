---
"drupal-canvas": minor
---

Add `canvasFormatDate`, `canvasFormatDateTime`, `canvasFormatTime`, and `canvasFormatDateRange` utilities for displaying Canvas date props in the active Drupal locale.

- Each function reads `drupalSettings.langcode` and formats an ISO date/time string using `Intl.DateTimeFormat`.
- Dates are rendered in UTC to prevent timezone-offset shifts on the viewer's device.
- An optional `options` argument (`CanvasDateFormatOptions`) overrides the default `'short'` style for the date and/or time portion.
