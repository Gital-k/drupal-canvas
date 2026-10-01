# 22. Entity API language selection and translation metadata

Date: 2026-09-30

Issue: <https://www.drupal.org/project/canvas/issues/3592098>

## Status

Accepted

## Context

ADR 21 added `route.negotiatedLanguage` and `route.translations` to `fetchPage()`, deferring the same data for `fetchEntity()`. A type/id lookup has no routed URL: Drupal's language negotiation plugins (URL prefix, domain, session) resolve against a request path, which a direct entity fetch does not have. Without an explicit selector, a frontend fetching an embedded entity (a related article, a referenced teaser) cannot request a specific translation; it only receives whichever language Drupal's ambient negotiation happens to resolve, typically the site's default.

The routed content endpoint already accepts a `language` parameter, but only for preview-scoped tokens selecting among a page's own auto-saved translations — a different concern from selecting a stored entity's language for an ordinary fetch.

## Decision

`fetchEntity()` accepts a `language` option, honored unconditionally rather than gated to preview-scoped requests: a type/id lookup has no other way to select a translation. Given and available, it determines the rendered content and the reported `entity.langcode`; omitted, Drupal's ambient negotiated content language applies, unchanged from prior behavior. An unavailable translation falls back through the same language-fallback candidates Drupal negotiation itself uses.

The result includes `negotiatedLanguage` and `translations`, reusing the shared translation-metadata contract from ADR 21 rather than its routed-page implementation. `negotiatedLanguage` reports the language actually rendered — reflecting an explicit `language` option — rather than the ambient negotiated content language `fetchPage()` reports independent of the entity's own fallback. Each translation entry's URL is the entity's own canonical URL, since a type/id lookup has no routed page URL to build a language switch link from; the `language` selector itself is not part of that URL, mirroring how the routed endpoint excludes its own preview-only parameters from translation links.

Framework wrappers inherit the option and result fields through the shared `fetchEntity()` contract without maintaining a parallel language-selection mechanism.

## Consequences

A frontend can fetch a specific translation of an embedded entity directly, and can build a language switcher for it using the same fields and fallback semantics as `fetchPage()`'s translations.

`negotiatedLanguage` diverges in meaning from `fetchPage()`'s: rendered language here, ambient negotiated language there. Frontends sharing translation-rendering logic across both compare against `entity.langcode` rather than assuming the two fields are interchangeable.
