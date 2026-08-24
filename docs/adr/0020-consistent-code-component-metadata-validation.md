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
- relationships between metadata values;
- project conventions involving names, directories, imports, and exports; and
- site-dependent rules involving installed field types, widgets, the entity model, and alter hooks.

No offline validator can reproduce the final category or provide an authoritative target-site verdict. Canvas needs useful
local feedback without maintaining a second implementation of Drupal's validation model.

## Decision

### Publish a hand-authored metadata schema

Canvas will publish a hand-authored `component-metadata.schema.json` in the module root. It will use JSON Schema draft-07
and describe the stable authored `component.yml` envelope, including:

- required `name` and `machineName` fields;
- optional `status`, `required`, `props`, `slots`, and `dataDependencies` fields;
- `dataDependencies.entityFields` as the only authored data dependency;
- no authored `type`, URL dependencies, Drupal settings dependencies, or projected `x-allowed-*` keys; and
- unknown-key rejection at fully defined metadata levels.

The schema will not be generated from Drupal config schema. A prop definition will be checked against the draft-07
meta-schema because it is already JSON Schema. The published schema will require the Canvas metadata needed around that
schema, such as a prop title and type, but it will not attempt to duplicate which otherwise valid JSON Schema shapes a
particular Canvas site supports.

Canvas will publish one rolling schema for the current authored format from the latest development branch. It will not
define independently versioned contracts or promise that a current target accepts metadata described by an older copy.
Schema files present in release tags are historical source snapshots, not supported schema versions. The CLI will bundle
its current schema for local envelope validation. Metadata files will not declare a schema URL or schema version because
the target site remains authoritative and the same component may be used with different sites.

Canvas-owned JSON schemas consumed by TypeScript will use draft-07 consistently. A private workspace package will provide
a strict Ajv factory with the standard and draft-2019 string formats Canvas supports. The UI and CLI will share this
factory, and the published CLI bundle will include its implementation rather than depending on the private package at
runtime.

### Validate prop schemas directly

After validating the authored envelope, the CLI will check each definition under `props.properties` as JSON Schema
directly. It will validate the first example value, which Canvas uses as the default, against its containing prop schema
where that schema can be resolved locally. The CLI will bundle and register Canvas-owned definitions from `schema.json`.
References provided by other target extensions may not be resolvable offline and will remain the target site's
responsibility. Drupal combines these definitions with the authored `required` names when it projects the normalized
payload into the complete SDC `props` schema.

The CLI will also validate portable metadata invariants that JSON Schema cannot express cleanly: required names must
identify defined props, required props must provide a default example, and string examples must not be empty.
Canvas-specific metadata policy, including array cardinality, content entity reference relationships, and image URL
restrictions, remains Drupal's responsibility to avoid duplicating implementation details in the CLI.

These local checks establish only that the document is structurally usable and that its directly resolvable prop schemas,
default examples, and generic relationships are internally consistent. They do not establish that Canvas can map a prop
shape to a field and widget or that a target site's extensions accept it.

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
2. check prop definitions, locally resolvable examples, and portable generic metadata relationships;
3. run project-oriented ESLint rules and aggregate their diagnostics;
4. normalize structurally valid metadata and construct the complete Code Component payload; and
5. submit that payload to the target operation when authenticated access is available.

If the target cannot be reached or does not provide the operation, the CLI will clearly report that only local checks
were completed and that target acceptance was not validated. Local checks may succeed without a target.

`push` will validate every complete component payload against the target before the first create, update, or delete. If an
older site does not provide the operation, the CLI will warn and retain per-component save-time Drupal validation; a
partial push therefore remains possible on older sites.

`build` remains offline. Discovery, Workbench, and headless consumers retain defensive metadata parsing rather than
enforcing target acceptance because they do not know the target site's contract.

### Keep ESLint focused on project conventions

ESLint remains authoritative for project conventions, including prop names, imports, exports, directory names, and
component hierarchy. It does not determine whether target metadata is accepted. Schema and target diagnostics should not
be duplicated as blocking ESLint diagnostics.

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
  than retaining validators for every historical version.
- **Make ESLint the metadata contract.** Rejected because unconditional CLI rules can disagree with the target and are not
  reusable by standard JSON Schema tools.

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
