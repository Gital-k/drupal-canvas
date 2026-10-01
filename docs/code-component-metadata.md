# Code Component metadata

Code Component and Canvas Headless codebases contain an authored `component.yml` file. Canvas publishes a hand-authored
JSON Schema for the current document envelope at
<https://git.drupalcode.org/project/canvas/-/raw/1.x/component-metadata.schema.json>.

This is a rolling schema, not a versioned compatibility contract. Copies found in release tags are historical source
snapshots; Canvas does not promise that current targets accept metadata described by older copies.

The schema uses JSON Schema draft-07. Prop definitions under `props.properties` are themselves JSON Schema and are checked
directly rather than generated from Drupal config schema. Metadata files do not include `$schema` or a schema version
because the Drupal site targeted by a CLI operation provides the authoritative contract.

## Authored fields

Only `name` and `machineName` are required. Authors may also provide:

- `status`;
- `required`;
- `props`;
- `slots`; and
- `dataDependencies.entityFields`.

Canvas CLI validates the raw YAML before applying defaults. It rejects unknown keys. Authors must not provide:

- `type`, which Canvas derives from the component and project mode;
- `dataDependencies.urls` or `dataDependencies.drupalSettings`, which Canvas derives from source analysis; or
- `x-allowed-entity-type-id` and `x-allowed-bundle`, which Drupal projects from `dataDependencies.entityFields` when it
  constructs the SDC definition.

`canvas pull` omits projected keys, preserves `dataDependencies.entityFields`, and writes the optional authored fields
explicitly.

## HTTP API payloads

The Code Component HTTP endpoints (`POST` and `PATCH` on `/canvas/api/v0/config/js_component`, and
`/canvas/api/v0/code-components/validate`) accept a looser payload than the config schema stores. A client may omit a
property, or send it empty; the server normalizes both to the single value the config schema requires. Clients therefore
never have to distinguish "omitted" from "empty".

Required on create: `machineName`, `name`, and `status`. `status` is required because omitting it would publish the Code
Component to the component library. The four code properties — `sourceCodeJs`, `sourceCodeCss`, `compiledJs` and
`compiledCss` — are required for every component except an `external` one, which has no source code in Canvas.

Optional, and accepted empty: `type` (an omitted value means `react`), `required`, `props`, `slots`,
`dataDependencies`, each of `dataDependencies.urls`, `dataDependencies.drupalSettings` and
`dataDependencies.entityFields`, and a slot definition's `description` and `examples`.

Two empty values remain validation errors, because omitting them is not equivalent to sending them empty:

- `type: ''` is not one of its choices — omit it to get `react`; and
- an empty `enum` on a prop leaves that prop with no valid value at all.

In a `PATCH` payload an omitted property still means "leave unchanged", so emptying a stored value requires sending it
empty.

## Local and target validation

The CLI uses the bundled schema to validate the authored envelope. It also checks each prop definition as JSON Schema and
validates its first example, which Canvas uses as the default, when the prop schema is locally resolvable. Canvas-owned
`$ref` definitions from `schema.json` are bundled with the CLI. References provided by other target extensions may not be
resolvable locally.

The required ESLint configuration checks portable authoring rules that cross metadata fields or extend JSON Schema:
required names must identify defined props, required props must provide a default example, and string examples must not be
empty. Drupal owns Canvas-specific metadata policy, including array cardinality, content entity reference relationships,
and image URL restrictions.

`canvas validate` and `canvas push` submit normalized payloads to the authenticated Drupal site for non-mutating
validation. The target runs the same metadata constraints used for create and update operations, so only its result is
authoritative for that installed Canvas version and site configuration.

If authentication, authorization, connectivity, or the target operation is unavailable, the CLI reports that only local
checks were completed. During push, older sites retain per-component save-time validation, so a partial push remains
possible.

`canvas build` performs only local checks. Component discovery, Workbench, and headless consumers remain offline and do
not become target-aware.
