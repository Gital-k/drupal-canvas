# Code Component metadata

Code Component source directories contain an authored `component.yml` file. Canvas publishes a JSON Schema for this
format:

- Latest development schema:
  <https://git.drupalcode.org/project/canvas/-/raw/1.x/component-metadata.schema.json>
- Immutable release schema, replacing `<release-tag>` with a Canvas tag such as `v1.10.1`:
  `https://git.drupalcode.org/project/canvas/-/raw/<release-tag>/component-metadata.schema.json`

The schema uses JSON Schema draft-07. Metadata files do not include `$schema` or a schema version because the Drupal site
targeted by a CLI operation provides the authoritative contract.

## Authored fields

Only `name` and `machineName` are required. Authors may also provide:

- `status`;
- `required`;
- `props`;
- `slots`; and
- `dataDependencies.entityFields`.

Canvas validates the raw YAML before applying defaults. It rejects unknown keys. Authors must not provide:

- `type`, which Canvas derives from the component and project mode;
- `dataDependencies.urls` or `dataDependencies.drupalSettings`, which Canvas derives from source analysis; or
- `x-allowed-entity-type-id` and `x-allowed-bundle`, which Drupal projects from `dataDependencies.entityFields` when it
  constructs the SDC definition.

`canvas pull` omits projected keys, preserves `dataDependencies.entityFields`, and writes the optional authored fields
explicitly.

## Offline and target validation

`canvas validate` and `canvas push` authenticate to the configured Drupal site, request its schema, and submit normalized
payloads for non-mutating validation. The target site runs the same metadata constraints used for create and update
operations, so its result is authoritative for that site's installed Canvas version and configuration at validation time.

Target schemas are cached by normalized site URL under `.canvas/schema-cache/`. The CLI requests the schema on every run
with `If-None-Match` and uses the cache after a `304 Not Modified` response.

If authentication, authorization, or connectivity fails, the CLI uses a cached target schema or its bundled schema and
prints a warning. Offline validation can succeed, but that result is not authoritative. Older sites without the schema
and validation operations also produce a warning; during push, they retain per-component save-time validation, so a
partial push remains possible.

`canvas build` uses the bundled schema. Component discovery, Workbench, and headless consumers remain offline and do not
become target-aware.
