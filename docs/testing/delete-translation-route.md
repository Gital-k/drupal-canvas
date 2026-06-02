# Testing the Delete Translation API Route

This document covers manual testing steps for the API route introduced in
[SCP-538](https://project.atlassian.net/browse/SCP-538):

```
DELETE /canvas/api/v0/content/canvas_page/{canvas_page}/translations
```

The route lives in `canvas.routing.yml` and is always active (no feature-flag
module required). The **language to delete** is determined by the URL language
prefix — e.g. `DELETE /fr/canvas/api/v0/content/canvas_page/1/translations`
deletes the French translation.

> **Browser UI:** A confirmation form is provided automatically by Drupal
> core's `content_translation` module at
> `/page/{id}/translations/delete/{lang}`.

---

## Prerequisites

1. A running DDEV site:
   ```shell
   ddev start
   ddev xb-site-install   # fresh install recommended
   ```

2. Add French as a content language:
   - Go to **Admin → Configuration → Regional and language → Languages**
     (`/admin/config/regional/language`)
   - Click **Add language**, choose **French**, and save.

3. Enable translation for the *Page* content type:
   - Go to **Admin → Configuration → Regional and language → Content language
     and translation** (`/admin/config/regional/content-language`)
   - Expand **Page**, tick **Enable translation**, and save.

4. Create a Canvas page and add a French translation:
   ```shell
   ddev drush php:eval "
     \$page = \Drupal\canvas\Entity\Page::load(1);
     \$fr = \$page->addTranslation('fr');
     \$fr->set('title', 'Page de test');
     \$fr->save();
     echo 'French translation created for page ' . \$page->id() . PHP_EOL;
   "
   ```

5. Note the page ID (used as `{id}` below).

---

## Obtain a session cookie

```shell
ddev drush uli
# Open that URL in a browser, then copy the SSESS... cookie from DevTools
```

```shell
COOKIE="SSESS...=..."
PAGE_ID=1
```

---

## Scenario 1 — Delete an existing non-default translation → `204`

The language is specified via the URL prefix (`/fr/`):

```shell
curl -X DELETE \
  "https://canvas-dev.ddev.site/fr/canvas/api/v0/content/canvas_page/${PAGE_ID}/translations" \
  -H "Cookie: ${COOKIE}" \
  -v 2>&1 | grep "< HTTP"
```

**Expected:** `< HTTP/2 204` with an empty body.

Verify via Drush:

```shell
ddev drush php:eval "
  \$p = \Drupal\canvas\Entity\Page::load(${PAGE_ID});
  var_dump(\$p->hasTranslation('fr'), \$p->hasTranslation('en'));
"
# Expected: bool(false) bool(true)
```

---

## Scenario 2 — Attempt to delete the default language → `400`

No language prefix means the request resolves to the default (English)
translation:

```shell
curl -X DELETE \
  "https://canvas-dev.ddev.site/canvas/api/v0/content/canvas_page/${PAGE_ID}/translations" \
  -H "Cookie: ${COOKIE}" \
  -v 2>&1 | grep "< HTTP"
```

**Expected:** `< HTTP/2 400` with a JSON body:
```json
{
  "message": "Cannot delete the default translation for canvas_page 1. Use the entity delete endpoint to remove the whole entity."
}
```

---

## Scenario 3 — Language prefix for a translation that no longer exists → `400`

(Run after Scenario 1 so the French translation is gone.)

Drupal's language negotiation falls back to the default translation when the
requested language has no translation, so the endpoint returns `400` (default
translation guard):

```shell
curl -X DELETE \
  "https://canvas-dev.ddev.site/fr/canvas/api/v0/content/canvas_page/${PAGE_ID}/translations" \
  -H "Cookie: ${COOKIE}" \
  -v 2>&1 | grep "< HTTP"
```

**Expected:** `< HTTP/2 400`

---

## Scenario 4 — Unauthenticated request → `401`

```shell
curl -X DELETE \
  "https://canvas-dev.ddev.site/fr/canvas/api/v0/content/canvas_page/${PAGE_ID}/translations" \
  -v 2>&1 | grep "< HTTP"
```

**Expected:** `< HTTP/2 401`

---

## Scenario 5 — User without delete permission → `403`

1. Create a user without the *Administer Canvas pages* permission and log in.
2. Copy that session cookie, then:

```shell
curl -X DELETE \
  "https://canvas-dev.ddev.site/fr/canvas/api/v0/content/canvas_page/${PAGE_ID}/translations" \
  -H "Cookie: ${LIMITED_COOKIE}" \
  -v 2>&1 | grep "< HTTP"
```

**Expected:** `< HTTP/2 403`

---

## Automated Tests

```shell
ddev exec --dir /var/www/html/web/modules/contrib/canvas \
  bash -c 'SIMPLETEST_BASE_URL=http://canvas-dev.ddev.site \
           SIMPLETEST_DB=mysql://db:db@db/db \
           /var/www/html/vendor/bin/phpunit \
           tests/src/Functional/TranslationTest.php \
           --filter testDeleteTranslation \
           --no-coverage'
```

| Test method | Scenarios covered |
|---|---|
| `testDeleteTranslation` | 204 delete via language prefix, 400 for default language, 400 when translation no longer exists |

