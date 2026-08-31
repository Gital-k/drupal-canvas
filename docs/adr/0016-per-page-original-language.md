# 16. Per-page original language

Date: 2026-07-04

Issue: TBD (to be filed against 1.x)

## Status

Proposed

## Context

Every Canvas page is created in the site default language. This is not an explicit decision anywhere in the code base: `ApiContentControllers::post()` and `AddPageController` call `$storage->create()` without a `langcode`, and Drupal core fills in the site default (`\Drupal\Core\Field\Plugin\Field\FieldType\LanguageItem::applyDefaultValue()`, or `\Drupal\language\DefaultLanguageItem` honoring `ContentLanguageSettings` when the Language module is installed). The `canvas_page` entity type is fully translatable (`translatable: TRUE`, translatable base fields, data and revision data tables), so the storage layer has no such limitation.

The consequence: on a site whose default language is English, an author cannot create a page whose original language is German. The original language matters because Canvas only allows editing the default translation in the editor (non-default languages are read-only previews), because the symmetric translation model (ADR [#12](0012-symmetric-content-translation-store-all-inputs-validate-at-write-time.md), [#13](0013-symmetric-translation-propagation-on-component-instance-update.md)) synchronizes non-translatable inputs *from* the default translation, and because translation workflows treat the original language as the source.

Drupal core already provides the mechanics this feature needs:

- Per-bundle default language and language selector visibility are governed by the `ContentLanguageSettings` config entity (`default_langcode`, `language_alterable`). With the Language module installed, `DefaultLanguageItem` resolves the creation default from it, and `language_alterable` gates `edit` access to the `langcode` field via `hook_entity_field_access()`.
- Changing an existing entity's original language is supported by `ContentEntityBase::onChange()`: allowed only on the default translation and only to a language with no existing translation; on success, `updateFieldLangcodes()` retags the default translation's field data in place and other translations are untouched.

One correctness gap becomes reachable once original languages can differ from the site default: the editor loads `/canvas/api/v0/layout/{type}/{id}` with no language path prefix, and core's `EntityConverter` upcasts via `EntityRepository::getTranslationFromContext()`, which negotiates to the *current content language*. Today the default translation and the negotiated language coincide (both are the site default), so this is harmless. With a German-original page that has an English translation, an unprefixed request on an English-default site would silently upcast the English translation, and the editor would edit the wrong translation.

## Decision

### 1. Creation accepts an explicit `langcode`; the default remains core-determined

`POST /canvas/api/v0/content/{entity_type}` accepts an optional `langcode` request body key, validated against the configured languages (`LanguageManagerInterface::getLanguages()`, 400 on unknown). When omitted, behavior is unchanged: core determines the language, which means `ContentLanguageSettings.default_langcode` governs it when the Language module is installed. The duplication path ignores `langcode`; a duplicate keeps its source entity's language. `AddPageController` is unchanged for the same reason: site builders control its default through `ContentLanguageSettings`.

In the editor UI, the language selection in the existing language switcher is the creation language: creating a page while viewing language X passes `langcode: X`.

### 2. Changing the original language reuses core semantics, exposed through the existing PATCH endpoint

`PATCH /canvas/api/v0/content/canvas_page/{id}` accepts a `langcode` key. Canvas adds no bespoke retagging logic; it delegates to core's langcode-change semantics and guards the cases core reports as errors, so they surface as structured HTTP responses instead of exceptions:

- 422 when the upcast entity is not the default translation (core forbids re-languaging translations).
- 400 when the langcode is not a configured language.
- 409 when a translation already exists in the target language. The composable alternative (delete that translation, then change the language) is already available through existing endpoints; no destructive "swap" is offered.
- 409 when any auto-save entries exist in the entity's translation group. Auto-save entries are keyed `{entity_type}:{id}:{langcode}` and embed both field data and `is_default_translation` flags; rekeying them is complex and lossy to get wrong, while "publish or discard first" costs the author one existing action.

After the guards, `langcode` joins the PATCH field whitelist, flowing through the same per-field `access('edit')` check, `validate()`, and `save()` as every other field.

### 3. Access control is core's `language_alterable`, and Canvas ships the config

The per-field `access('edit')` check on `langcode` is the access gate: with the Language module installed, it enforces `ContentLanguageSettings.language_alterable`. Because core's fallback is non-alterable, Canvas ships `config/optional/language.content_settings.canvas_page.canvas_page.yml` (`language_alterable: true`, `default_langcode: site_default`) and an update hook that creates it on existing sites only when the Language module is installed and no such config exists yet. Sites can opt out by setting `language_alterable: false`, which removes both the affordance and the API capability at once.

### 4. Editor requests carry the entity's original-language URL prefix

The client learns the entity's original language (from the layout response and boot-time settings) and includes its URL prefix on all editor layout, preview, and auto-save requests whenever it differs from the site default (and has a prefix configured). This pins `EntityConverter` upcasting to the original language regardless of negotiation, closing the wrong-translation gap described in Context. This is the load-bearing piece of the feature: without it, choosing a non-default original language would corrupt editing sessions on pages that also have a site-default-language translation.

### 5. The layout API exposes original-language metadata and the change affordance

`ApiLayoutController::get()` adds `translations.defaultLangcode` (explicit, instead of "first item in `available`" being implicit) and a per-language `set-default-language` link (the PATCH URL) for each configurable language that is not the current original language, has no existing translation, and passes the `langcode` field `edit` access check. The client renders the affordance purely from link presence, keeping policy on the server.

## Consequences

In order of importance, with the following markers:
- positives (`+`) vs negatives (`-`) vs status quo (`≃`)
- impact types: technical (`T`) vs operational (`O`) vs business (`B`)

1. `+B` **Authoring is decoupled from the site default language.** Multilingual teams can create a page in any configured language and correct a page created in the wrong language, without site-wide configuration changes or workarounds.

2. `+T` **No parallel language machinery.** Creation defaults, the access gate, and retagging semantics are all core mechanisms (`ContentLanguageSettings`, `DefaultLanguageItem`, field access, `ContentEntityBase::onChange()`). Canvas adds validation, guards, and UI plumbing only, so core fixes and improvements apply automatically.

3. `+T` **The editor becomes correct for entities whose original language differs from the site default**, including entities created by other means (JSON:API, migrations, custom code) that were already reachable before this feature.

4. `-T` **Every editor request builder must be language-prefix aware.** A missed URL construction site produces split-brain auto-save drafts (an entry keyed to the wrong langcode). Mitigation: audit all `/canvas/api/v0` URL builders in the client, plus a possible follow-up server-side assertion rejecting layout writes against a non-default translation that was not explicitly requested.

5. `-O` **`language_alterable: true` also exposes core's language selector on any entity form that renders the `langcode` widget** for `canvas_page`. Acceptable: those forms are administrative, and the setting is site-configurable.

6. `≃T` **Language changes are blocked while drafts exist** (409). Authors publish or discard first, using existing actions. No draft data is ever migrated or dropped implicitly.

7. `≃T` **Consumers observing per-langcode state must treat a language change as an entity update.** The in-house canvas_translate module stores per-langcode drafts outside entity storage; a draft for the language that just became the original is meaningless and must be discarded by its own update hook. Canvas core communicates the change as a normal entity save; no new event is introduced.

8. `≃O` **`content_translation_source` on pre-existing translations is not rewritten.** Core does not update it on retag; the staleness is cosmetic.
