# 14. Per-entity asymmetric translation forks

Date: 2026-07-04

Issues: <https://www.drupal.org/project/canvas/issues/3571130>, <https://www.drupal.org/project/canvas/issues/3583945>

## Status

Proposed

## Context

[ADR #6](0006-One-field-row-per-component-instance.md) deliberately stored the `tree` columns (`uuid`, `parent_uuid`, `slot`, `component_id`, `component_version`) separately from `inputs` so that the tree column group could later be translated per language (asymmetric translation), and [ADR #12](0012-symmetric-content-translation-store-all-inputs-validate-at-write-time.md) established the shipped symmetric model: every translation stores full field rows, core's `FieldTranslationSynchronizer` keeps the tree columns aligned, and Canvas's decorator re-syncs non-translatable input keys.

Two gaps remain:

1. Whether a `component_tree` field is symmetric or asymmetric is decided by content_translation's `translation_sync` settings, a per-field, site-wide choice (a site-wide asymmetric switch of this kind is also what [ADR #10](0010-dynamic-config-schema-for-component-tree-translatability.md) anticipated). The product requirement is a per-entity decision: symmetric by default, with an explicit author choice to fork one entity's translation into an independent tree. This applies to content entities (Canvas Pages, nodes with Canvas fields) and to config-defined component trees (Page Regions, Content Templates) alike: one content template's translation may need its own structure while its siblings stay symmetric.
2. Nothing guarantees the symmetric configuration: `translation_sync` must be configured per field, per site. A site that translates Canvas fields without configuring it gets no synchronization at all, so "symmetric by default" is a convention, not an invariant.
3. Config-defined component trees translate through sparse language overrides: only translatable input values may be overridden, so a translation can never own its structure.

## Decision

1. **For content entities, fork state is a translatable, revisionable boolean base field** (`canvas_component_tree_fork`, default `FALSE`, no form/view displays), added to every translatable content entity type when content_translation is installed (the same pattern as content_translation's own metadata base fields). The default translation is never forked. One flag covers all `component_tree` fields on the entity. The fork is explicit user intent and must be stored: deriving it from tree divergence cannot represent "forked but not yet diverged" (the synchronizer would immediately re-sync the identical copy away) and makes accidental divergence indistinguishable from intent.

2. **Granularity is per translation.** Each translation forks individually: one language can own its tree while siblings stay synchronized with the default translation. The same model applies to content entities and config entities.

3. **For config entities (Page Regions, Content Templates), fork state is the language override's shape; no flag is stored.** Config translations are sparse overrides, so symmetric is the natural default: keys that are not overridden fall through to the base config, and structure follows the base automatically with no write-time synchronization. A symmetric translation overrides only translatable input values; a forked translation overrides the full `component_tree`. "Forked but not yet diverged" is representable (a full-tree override identical to the base) and stable, because nothing ever re-syncs override contents. The config schema for these entity types marks `component_tree` overridable per language; the translations-are-valid constraint validates each override merged onto the base, whether sparse or full-tree.

4. **The symmetric configuration becomes guaranteed for content.** Canvas ships the `translation_sync` values for the canvas Page `components` field and normalizes them on presave of any `component_tree` field config; `translation_sync` stops being a per-site knob for this field type. A post-update marks translations whose tree columns already diverged as forked, so the newly guaranteed sync cannot clobber pre-existing data.

5. **Forked content translations are excluded from synchronization by snapshot/restore, not by re-implementing sync.** The existing decorator snapshots each forked translation's raw `component_tree` values before delegating to core and restores them after; when the saved translation is itself forked, all translations' values are snapshot/restored so fork edits never propagate outward. Core's merge and pending-revision semantics stay authoritative for non-forked translations. The symmetric validation constraint and component instance version updates skip forked translations; a forked host's tree is reconciled in place. Config entities need no synchronization counterpart: sparse overrides never receive base-config writes, and a full-tree override is untouched by base-config changes by construction.

6. **Fork seeds from the current symmetric state; unfork destructively re-syncs.** Content: forking needs no tree copy, because symmetric mode already keeps every translation's stored tree rows identical while carrying that translation's translated inputs; forking only flips the flag. Config: forking expands the language override to a full `component_tree` (base structure with that language's translated input values applied). Unforking is the mirror image: content replaces the translation's tree with the default translation's current values; config strips the override back to only translatable input values. Both re-apply the fork's translatable input values for component UUIDs that still exist and discard fork-only components; the UI requires explicit destructive confirmation.

7. **Fork and unfork are auto-save draft operations** exposed as HTTP API endpoints, so they are previewable and discardable, and only become permanent on publish. Content drafts are per-langcode auto-save entries; config drafts are staged language config overrides.

8. **Consumers degrade gracefully.** The layout API exposes per-language fork state and action links for both content and config entities. The string-based translation UI (canvas_translate) detects forked translations in both its content and config paths, points translators at the full Canvas editor for that language, and refuses to publish string drafts onto forked translations.

## Consequences

In order of importance, with the following markers:
- positives (`+`) vs negatives (`-`) vs status quo (`≃`)
- impact types: technical (`T`) vs operational (`O`) vs business (`B`)

1. `+B` **Symmetric by default, asymmetric by choice, per entity.** Authors keep the low-maintenance symmetric model everywhere and pay the divergence cost only on the pages, page regions, or content templates that need it, in only the languages that need it. The granular per-config-entity choice means one content template can be forked while its siblings stay symmetric.

2. `+T` **No storage or data-model change beyond one boolean.** The field schema, ADR #12's store-all-inputs model, and every read path are untouched; a forked content translation is just a translation whose rows are no longer overwritten at write time, and a forked config translation is just an override that covers the whole tree.

3. `+T` **Fork state is ordinary data.** For content it is field data: revisions, Workspaces, JSON:API, default_content, and TMGMT handle it without special casing. For config it is the override itself: config export/import, deployment, and config synchronization carry it with no extra state to migrate or reconcile.

4. `+T` **Core synchronization is composed with, not duplicated.** Snapshot/restore is a no-op whenever core did not touch the values, so core's pending-revision and merge subtleties remain core's responsibility.

5. `-O` **Behavior change for previously unconfigured sites.** Sites that translated Canvas fields without `translation_sync` had no sync; the always-on symmetric default would clobber divergent trees on first save. The post-update that marks divergent translations as forked is mandatory mitigation.

6. `-B` **Forked translations stop receiving structural updates from the default translation** (content) **or the base configuration** (config), including component instances added later; that is the point, but authors must understand the trade-off before forking (hence explicit confirmation in the UI).

7. `-T` **Making `component_tree` overridable in config schema widens the config translation surface.** Core's Config Translation UI and TMGMT config jobs could now present the whole tree, not just translatable inputs; Canvas's config translation form elements must keep constraining what non-Canvas UIs offer.

8. `≃T` **Publish granularity is unchanged.** Forked drafts still publish and discard with the whole entity translation group; per-language publish remains a separate decision.

9. `-T` **The string-based translation UI cannot translate forked trees.** Forked translations are handed to the full Canvas editor instead; a string workflow over asymmetric trees would need UUID-independent mapping and is explicitly out of scope.
