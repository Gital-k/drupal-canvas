# Canvas API Documentation

## PHP API

Canvas exposes a public PHP API via hooks. These are documented in
[`canvas.api.php`](../canvas.api.php) at the module root, following the
standard Drupal convention for hook documentation files.

### hook_canvas_storable_prop_shape_alter()

Allows modules to alter how a JSON schema prop shape is mapped to a Drupal
field type, field widget, and field instance settings before the field is
created or matched.

This is the primary extension point for adding support for custom JSON schema
shapes (e.g. `format: duration`) or overriding the default field type/widget
chosen by Canvas for a known shape (e.g. switching `format: uri` from a plain
URI field to a link field).

See [`canvas.api.php`](../canvas.api.php) for a fully annotated example
covering common use cases:

- Overriding the widget for `enum` shapes.
- Remapping `format: uri` to the `link` field type.
- Adding support for a contrib `format: duration` field type.
- Customizing which media types are allowed for `image` object shapes.

Further background on how prop shape matching works is in
[`docs/shape-matching.md`](shape-matching.md).

### hook_canvas_importmap_alter()

Allows modules and themes to add, remove, or modify entries in the JavaScript
[import map](https://developer.mozilla.org/en-US/docs/Web/HTML/Element/script/type/importmap)
used by Canvas code components.

Use this hook to make a third-party JS package globally available to all code
components, or to replace a bundled package with a custom build.

See [`canvas.api.php`](../canvas.api.php) for an annotated example.

## HTTP API

Canvas does not expose any public HTTP API. All HTTP endpoints are internal
implementation details and may change without notice.

## Stability

Public APIs follow [semantic versioning](https://semver.org/). Breaking changes
to hooks documented in `canvas.api.php` will not be made in patch releases.
Internal classes, interfaces, and traits are annotated with `@internal` and are
not covered by this guarantee.
