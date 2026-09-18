# 21. Structured translation links in the Headless content API

<!-- cspell:words hreflang contacto -->

Date: 2026-09-18

## Status

Accepted

## Context

A multilingual headless frontend needs to know which translations exist and how to request them. Drupal owns translation availability, access, aliases, and language negotiation; the frontend owns public URLs (ADR 18). HTML alternate links alone cannot describe availability or distinguish the negotiated language from the rendered fallback.

The code-component contract serves a different switcher policy: it lists every enabled language, supplies localized and native names, marks availability, and provides fallback URLs for unavailable translations. Its `current` marker follows the requested language. A list of actual accessible translations instead lets a headless frontend offer only content it can retrieve, without interpreting fallback entries.

## Decision

The routed content API adds required `route.negotiatedLanguage` and `route.translations` fields:

- `negotiatedLanguage` is the negotiated content-language ID.
- `translations` contains accessible existing translations, including the rendered one. Missing and inaccessible translations have no entries. Monolingual sites and routes without a canonical content entity return an empty array.
- Each entry has `langcode`, `current`, `url`, and `external`. `current` matches the rendered `route.entity.langcode`, not necessarily the negotiated language.
- Non-external URLs are site-relative Drupal request URIs with the installation base path removed. Language prefixes and query parameters are preserved. External URLs remain absolute and are **not valid `fetchPage` input**; exposing them does not imply SDK domain-negotiation support.

For example, when French is requested but Drupal renders English, the language fields can be:

```json
{
  "negotiatedLanguage": "fr",
  "translations": [
    { "langcode": "en", "current": true, "url": "/contact", "external": false },
    { "langcode": "es", "current": false, "url": "/es/contacto", "external": false }
  ]
}
```

The frontend supplies display names and maps Drupal URIs to public URLs: `/es/contacto` might become `https://example.es/contacto` or `/es-ES/contacto`. The original `/es/contacto` remains the input for fetching that translation from Drupal. With query negotiation, `/contact?language=en` explicitly selects English even when it is the current language; no Drupal session is assumed.

Links use Drupal's language-switch options in content-negotiation order, following interface negotiation where content delegates to it. Internal preview selectors are excluded. Every translation access decision, including denials, and every generated URL contributes cacheability. Responses also depend on language configuration, negotiated content/interface/URL languages, request URI, and preview account access. An authorized preview can therefore list a translation omitted from the public response.

Metatag-provided head links pass through unchanged. This contract does not add hreflang generation, display-name fields, fallback entries, prefix-free alias fields, non-entity language links, entity-endpoint translation metadata, or SDK mapping helpers/templates.

## Consequences

Every entry denotes content the requester can view. Frontends that want an all-languages switcher must separately define how to present unavailable languages. Matching the code-component contract would reduce differences between consumers, but would change this invariant and the meaning of `current`; adding fallback entries is not necessarily compatible with existing consumers.

Display names are a separate payload choice, not a reason to adopt fallback behavior. Even with matching availability semantics, headless URLs need the request-URI/base-path/external distinction because Drupal URLs and frontend public URLs are not interchangeable.
