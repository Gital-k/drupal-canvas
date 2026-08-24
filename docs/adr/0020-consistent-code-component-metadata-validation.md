# 20. Provide consistent Code Component metadata validation across the CLI and Drupal

Date: 2026-08-23

## Status

Proposed

## Context

Code Component developers author metadata in `component.yml`. This Canvas-specific exchange format reuses parts of the
Single-Directory Component prop and slot vocabulary, but it is not itself an SDC definition or a JSON Schema document.
Canvas moves required prop names to the document's top level, derives some values during push, and projects the result
into an SDC definition for Drupal validation.

The definitions under `props.properties` are different: each prop definition is JSON Schema. Drupal uses that schema to
validate example and runtime prop values. Drupal config schema separately describes which JSON Schema vocabulary Canvas
can store, and entity constraints determine which valid JSON Schema shapes Canvas and the target site can support.
Drupal config schema is not JSON Schema, and translating it would require reproducing dynamic config schema types,
inheritance, custom data classes, and Symfony constraints.

The metadata acceptance contract spans several kinds of rules:

- the authored document envelope, including its required and allowed fields;
- whether each prop definition is valid JSON Schema and its examples conform to it;
- relationships between authored values, such as every name in `required` identifying a prop in `props.properties` and
  every required prop providing a default example;
- project conventions, such as `machineName` matching the component directory or metadata filename, prop names matching
  their titles, component directories having the expected hierarchy, imports using supported paths, and component source
  files providing a default export; and
- site-dependent rules involving installed field types, widgets, the entity model, and alter hooks.

No offline validator can reproduce the final category or provide an authoritative target-site verdict. Canvas needs useful
local feedback without maintaining a second implementation of Drupal's validation model.

## Decision

### Validation overview

```text
component.yml
  ├─ JSON Schema → envelope, prop schemas, and default examples
  ├─ ESLint → portable authoring and project rules
  └─ Drupal target → authoritative acceptance
```

The CLI bundles the repository's envelope schema and Canvas-owned prop definitions. The target uses its installed code and
configuration.

### Publish a hand-authored metadata schema

Canvas will publish a hand-authored `component-metadata.schema.json` in the module root. It will use JSON Schema draft-07
and describe the stable authored `component.yml` envelope, including:

- required `name` and `machineName` fields;
- optional `status`, `required`, `props`, `slots`, and `dataDependencies` fields;
- `dataDependencies.entityFields` as the only authored data dependency;
- no authored `type`, URL dependencies, Drupal settings dependencies, or projected `x-allowed-*` keys; and
- unknown-key rejection at fully defined metadata levels.

The schema will not be generated from Drupal config schema. Each value under `props.properties` serves two roles: it is a
JSON Schema for that prop's values, and it carries metadata Canvas requires to present the prop. Validation against the
draft-07 meta-schema checks that JSON Schema keywords such as `type`, `enum`, `items`, and `properties` are used
correctly. The authored-envelope schema adds Canvas's portable requirements, including a nonempty `title` and an explicit
`type`. Neither check decides whether a site can store that prop shape, map it to an installed field and widget, resolve an
extension-provided `$ref`, or accept it after site-specific alterations; Drupal determines those constraints.

Canvas will publish one rolling schema for the current authored format from the latest development branch. It will not
define independently versioned contracts or promise that a current target accepts metadata described by an older copy.
Schema files present in release tags are historical source snapshots, not supported schema versions.

Publication and bundling serve different consumers but use one source file. The repository root
[`component-metadata.schema.json`](../../component-metadata.schema.json) has a rolling repository URL as its `$id`, so
editors and other external tools can use the published file. The CLI source statically imports that same file, and the CLI
package build embeds it for reproducible offline validation. The CLI therefore does not maintain a second schema or fetch
the rolling URL at runtime. Metadata files will not declare `$schema` or a schema version because the target site remains
authoritative and the same component may be used with different sites.

Canvas-owned JSON schemas consumed by TypeScript will use draft-07 consistently. A private workspace package will provide
a strict Ajv factory with the standard format validators and the additional string formats Canvas supports, including
`idn-email`, `idn-hostname`, `iri`, `iri-reference`, and `duration`. These additional validators come from the
`ajv-formats-draft2019` package, but registering them does not change the JSON Schema dialect from draft-07. The UI and CLI
will share this factory, and the published CLI bundle will include its implementation rather than depending on the
private package at runtime.

### Validate prop schemas directly

After validating the authored envelope, the CLI will check each definition under `props.properties` as JSON Schema
directly. This catches malformed schemas, such as an invalid `type` value or a string used for a numeric constraint. It will also
validate the first example value, which Canvas uses as the default, against its containing prop schema. For example, it can
reject a string default for an integer prop or an image default missing the `src` required by the Canvas image definition.

The CLI also statically imports the Canvas module root [`schema.json`](../../schema.json), and the package build embeds it.
At runtime, the CLI registers each entry under that file's `$defs` at its
`json-schema-definitions://canvas.module/<name>` URI. Ajv can then resolve Canvas-owned references in prop schemas without
network access. References provided by target extensions are not in the bundled file and may remain unresolved locally;
the CLI does not reject unknown extension vocabulary, and Drupal validates it authoritatively. Drupal combines these
definitions with the authored `required` names when it projects the normalized payload into the complete SDC `props`
schema.

The required ESLint configuration will validate portable authoring rules that JSON Schema cannot express cleanly:
required names must identify defined props, required props must provide a default example, and string examples must not
be empty. These checks describe authored source rather than target acceptance.

Passing local validation means that the metadata is structurally usable and its resolvable prop schemas and defaults are
internally consistent. It does not mean that Canvas can store the shape or map it to an installed field and widget. Drupal
also owns policies such as array cardinality and content entity reference relationships. For example, a relative image URL
can conform to the bundled `uri-reference` schema while Drupal's Code Component policy requires a fully qualified URL.

### Define the authored metadata boundary

Only `name` and `machineName` are required in an authored metadata document. The CLI validates raw parsed YAML before
normalizing optional values. Valid documents then receive explicit defaults for internal processing:

- `status` defaults to `true`;
- `required` defaults to an empty array;
- `props` defaults to an object containing an empty `properties` object;
- `slots` defaults to an empty object; and
- `dataDependencies` defaults to an empty object.

`type`, URL dependencies, and Drupal settings dependencies are derived during push. Projected
`x-allowed-entity-type-id` and `x-allowed-bundle` values are not valid authored metadata; in accordance with
[ADR 11](0011-content-entity-reference-props-in-code-components.md), `dataDependencies.entityFields` remains their source
of truth.

`pull` continues to emit optional fields explicitly, omits projected `x-allowed-*` values, and preserves
`dataDependencies.entityFields`.

### Make target validation authoritative

An authenticated Canvas site will expose a non-mutating Code Component validation operation. It accepts one normalized
Code Component API payload, constructs an in-memory create or update candidate, and runs the same entity and metadata
constraint chain used by create and update. It returns `204` when valid or the existing structured constraint-violation
response when invalid. It never saves or otherwise mutates stored configuration.

`canvas validate` will:

1. validate raw YAML against the bundled authored-envelope schema;
2. check prop definitions and locally resolvable examples;
3. run ESLint authoring and project rules and aggregate their diagnostics;
4. normalize locally valid metadata and construct the complete Code Component payload; and
5. submit that payload to the target operation when authenticated access is available.

If the target cannot be reached or does not provide the operation, the CLI will clearly report that only local checks
were completed and that target acceptance was not validated. Local checks may succeed without a target.

`push` will validate every complete component payload against the target before the first create, update, or delete. If an
older site does not provide the operation, the CLI will warn and retain per-component save-time Drupal validation; a
partial push therefore remains possible on older sites.

`build` remains offline. Discovery, Workbench, and headless consumers retain defensive metadata parsing rather than
enforcing target acceptance because they do not know the target site's contract.

### Keep ESLint focused on authoring rules

ESLint remains authoritative for portable authoring rules and project conventions, including required-prop relationships,
nonempty string examples, prop names, imports, exports, directory names, and component hierarchy. It does not determine
whether target metadata is accepted. Schema and target diagnostics should not be duplicated as blocking ESLint
diagnostics.

## Alternatives considered

- **Generate JSON Schema from Drupal config schema.** Rejected because the formats describe different contracts and do
  not map mechanically. The resulting translator would be a second, incomplete implementation of Drupal config schema.
- **Use only a bundled metadata schema.** Rejected because it cannot account for target version, extensions, field types,
  widgets, or alter hooks.
- **Use only remote Drupal validation.** Rejected because YAML, envelope, and directly resolvable prop-schema errors can
  be reported quickly and precisely without Drupal.
- **Download a target-specific schema.** Rejected because a schema still cannot express all target constraints; the
  non-mutating validation operation already provides the authoritative verdict without schema translation or caching.
- **Declare a contract version in each `component.yml`.** Rejected because a site accepts its installed contract rather
  than retaining validators for every historical version. The same component may also be used across sites running
  different Canvas versions, so one declared contract version would not describe every target.
- **Make ESLint the complete metadata contract.** Rejected because Canvas-specific rules can disagree with the target and
  envelope and prop-schema validation should remain reusable by standard JSON Schema tools. ESLint is limited to portable
  authoring rules and project conventions.

## Consequences

- The authored envelope and the prop schemas have readable, explicit validation paths.
- The repository does not maintain a Drupal-config-schema-to-JSON-Schema translator.
- Editors and offline CLI workflows receive useful structural feedback, but offline success does not guarantee target
  acceptance.
- A reachable target site provides the authoritative verdict without mutating component configuration.
- The hand-authored envelope schema must be updated deliberately when the authored format changes.
- Canvas-owned `$ref` values are resolved against the definitions bundled with the CLI, which may differ from the target's
  installed Canvas version; extension-provided references remain target-only.
- Older sites retain save-time validation but cannot provide an all-component remote preflight.
- Migrating Canvas-owned schemas and validators to draft-07 remains part of the change and establishes one TypeScript
  JSON Schema dialect and one tested Ajv configuration.
