---
"@drupal-canvas/headless": minor
---

Add a `language` option and `negotiatedLanguage`/`translations` result fields to `fetchEntity()`. A type/id lookup has no routed URL to negotiate a language from, so `language` selects a translation directly; framework wrappers inherit it automatically. Translation entries share the fields and switcher behavior of `fetchPage()`'s `route.translations`, built from the entity's own canonical URL.
