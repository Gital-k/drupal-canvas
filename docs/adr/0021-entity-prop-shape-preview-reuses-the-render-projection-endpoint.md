# 21. Entity reference prop shape preview reuses the render projection endpoint

Date: 2026-07-10

Issue: https://git.drupalcode.org/project/canvas/-/work_items/3591805

## Status

Accepted

## Context

[ADR #11](0011-content-entity-reference-props-in-code-components.md) introduced `content-entity-reference` (CER) props for code components: the developer picks a target entity type, bundle, and fields, and at render time `JsComponent::buildReferencePayload()` turns the referenced entity into the object the component receives (`{ __type: '<bundle>', <key>: <value|nested object>, ... }`, or `null` when nothing is referenced).

The code editor gave the developer no view of that shape. CER props deliberately have no `example` (ADR #11 forbids it, since a static example would misrepresent a value that is always sourced live from an entity), so the in-editor preview passed `undefined` for CER props. Developers had to read PHP or `console.log` a placed instance to learn the shape, defeating the "author entirely in the browser" promise of the code editor.

Two endpoints already existed, consumed only by the workbench and CLI, not by `ui/src`:
- `POST /canvas/api/v0/ui/content-entity-reference/preview/{entity_type}/{entity}`: given a real entity plus a prop's expression strings, returns the exact `buildReferencePayload()` output. Enforces `_entity_access: 'entity.view'`.
- `GET /canvas/api/v0/ui/content_template/suggestions/preview/{entity_type_id}/{bundle}`: returns up to 10 `{id, label}` candidates, each filtered by `$entity->access('view')`.

## Decision

### The code editor calls the existing render projection endpoint; it does not reimplement value resolution

The right-hand prop editor panel resolves a selected preview entity's values through the same `POST .../content-entity-reference/preview/...` endpoint the render path uses. There is a single normalizer (`buildReferencePayload()`); the editor preview and production rendering cannot drift, by construction. A parallel client-side resolver was rejected: it would duplicate `buildReferencePayload()`'s semantics (including its nested-reference and null-handling rules) and inevitably drift from them.

### The suggestions endpoint gains a `search` parameter; access filtering is unchanged

The content-template suggestions endpoint already returned access-filtered `{id, label}` candidates for an entity type + bundle: exactly what an entity picker needs. It gained one extension: an optional `?search=` query parameter that narrows `ContentTemplate::getPreviewSuggestionQuery()` by a label substring (`CONTAINS`), because 10 unsearchable candidates is not usable on real sites. The per-entity `access('view')` filtering that runs after the query is unchanged: search narrows the *candidates*, access decides what is *returned*. A new CER-namespaced suggestions route was rejected as pure duplication of a data need the content-template endpoint already serves.

### The shape display is skeleton-first, values when an entity is selected

The CER prop widget shows a collapsible "Delivered shape" panel:
- **No entity selected:** a skeleton derived client-side from the prop's selected fields: developer-facing keys (grouped by the field they belong to, matching how `buildReferencePayload()` keys the payload), each field's typed-data value type (from `listFields()`, which gained a `type` key per property for this), the constant `__type` key, and the fact that the whole prop can be `null`. This is best-effort display only; it never feeds the live preview.
- **Entity selected:** the actual resolved payload, pretty-printed. This is the source of truth and doubles as documentation of real values.

A server-side "shape only" endpoint was rejected: the client-derived skeleton (plus the real payload once an entity is picked) already covers the need. Promote to a server endpoint only if the client skeleton proves too lossy for exotic field types or deep nesting in practice.

### The selected preview entity is ephemeral editor state, never persisted

The selection (`{entityTypeId, entityId}`) lives in the code editor's Redux slice, alongside existing per-prop state but structurally excluded from `serializeProps()`: it therefore never reaches the `JavaScriptComponent` config entity and has no effect on placed instances. This is enforced by never including it in the serialization whitelist, not by filtering it out at save time. Clearing the field selection or changing the target entity type/bundle resets the selection, since a previously selected entity may no longer match. The resolved payload used to drive the live preview iframe is cached the same way (also Redux, also excluded from serialization), re-resolved whenever the selected entity or the field selection changes.

### Access enforcement stays server-side on every endpoint involved

Entity search (suggestions endpoint), selection, and value resolution (preview endpoint) all enforce access server-side: `access('view')` filtering in the suggestions query, `_entity_access: 'entity.view'` on the preview route. The UI never relies on client-side filtering alone: it only ever renders what the server already decided the current user may see.

## Consequences

1. `+T` **No possibility of preview/production drift.** The editor preview and placed-instance rendering share one code path (`buildReferencePayload()`), reached through the same HTTP endpoint the render path already used via the workbench and CLI.
2. `+O` **Developers get real feedback without leaving the browser.** The shape panel and live preview replace reading PHP or `console.log`-ing a placed instance.
3. `-T` **The client-derived skeleton is an approximation.** It groups picked properties by their owning field to match `buildReferencePayload()`'s top-level keys, but does not replicate deeper nesting rules (e.g. multi-level reference chains render as a single `object` type rather than a fully nested shape). Selecting a real entity always shows the authoritative payload, bounding the impact of this approximation to the pre-selection skeleton only.
4. `≃T` **One additional query parameter and one additional response field, not new endpoints.** `search` on the suggestions endpoint and `type` on `listFields()`'s properties are additive; both are backward compatible for the workbench, CLI, and OpenAPI-validated consumers already using these endpoints.
5. `-O` **Preview resolution is a real, developer-initiated HTTP request per entity selection.** Entities with heavy fields could make it slow. Accepted: it uses the same code path the workbench already exercises, and is user-initiated rather than automatic.
