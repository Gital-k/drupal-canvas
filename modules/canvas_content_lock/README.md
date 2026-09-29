# Drupal Canvas Content Lock

Right now, two people can open the same Canvas page and edit it at the same time
without knowing about each other. Canvas will stop the second save from
overwriting the first, but by then someone has already lost their work.

This module lets people know up front. When you open a page in the Canvas
editor, you get a lock on it. If a colleague opens the same page while you're
working on it, they see a dialog telling them you're editing it, and they can go
back. Users with the right permission can also take over editing.

It's a soft lock. It doesn't prevent saving, and it doesn't replace the conflict
detection in Canvas. It just makes the situation visible before anyone starts
editing.

## Requirements

Drupal Canvas. Nothing else.

## Installation

Enable the module like any other Drupal module:

```
drush en canvas_content_lock
```

There is nothing to configure.

## Permissions

Anyone who can edit a Canvas page can hold a lock on it, so there is no extra
permission for that.

There is one new permission, **Break Canvas content locks**
(`break canvas content lock`). Users with it get a "Take over editing" button in
the dialog. Give it to site editors or admins only, because it lets someone take
over a page that another user is working on.

## How it works

- Opening `/canvas/editor/canvas_page/{id}` acquires the lock, unless someone
  else already has it.
- While the page stays open, the browser refreshes the lock every 30 seconds.
- The lock is released when you move to another page in Canvas, close the tab,
  or publish your changes. It is also removed when the page is deleted.
- If a browser crashes or loses its connection, the lock isn't released right
  away. After 90 seconds without a refresh, it is treated as expired and the page
  is free again.
- While the dialog is open, the browser keeps checking. As soon as the other
  person leaves, the dialog closes and you get the lock.

A few things to be aware of:

- Locks are per user, not per tab. If you open the same page in two tabs
  yourself, you won't get a warning.
- Only Canvas pages are covered. Nodes edited with Canvas are not locked.
- After you publish, your lock is released. If you keep editing, the next
  refresh picks it up again.

## API

The dialog uses four JSON endpoints. You shouldn't need to call them yourself,
but they're useful for debugging:

```
GET  /canvas/api/v0/content-lock/{canvas_page}/check
POST /canvas/api/v0/content-lock/{canvas_page}/acquire
POST /canvas/api/v0/content-lock/{canvas_page}/release
POST /canvas/api/v0/content-lock/{canvas_page}/break
```

You need edit access to the page to use any of them. The POST requests also need
an `X-CSRF-Token` header, and `break` needs the permission described above. Only
logged-in browser sessions are accepted.

Each one returns the current lock status, for example:

```json
{
  "locked": true,
  "isOwner": false,
  "owner": { "uid": 4, "name": "editor" },
  "updated": 1790681394,
  "canBreak": false
}
```

Note that `acquire` returns 200 even when someone else holds the lock. Check
`isOwner` to see whether you got it.

## Where things are

- `src/ContentLockManager.php` holds the lock logic. Locks are stored in
  SharedTempStore (collection `canvas_content_lock`), so the module adds no
  database tables.
- `src/Controller/ContentLockController.php` holds the endpoints.
- `src/EventSubscriber/ContentLockAssetsSubscriber.php` adds the JavaScript and
  settings to the Canvas editor.
- `src/Hook/ContentLockHooks.php` releases locks when a page is published or
  deleted.
- `js/canvas-content-lock.js` tracks navigation in the editor, refreshes the
  lock and shows the dialog.

To inspect or clear a lock by hand while debugging:

```
drush php:eval "print_r(\Drupal::service('tempstore.shared')->get('canvas_content_lock')->getMetadata('8'));"
drush php:eval "\Drupal::service('tempstore.shared')->get('canvas_content_lock')->delete('8');"
```

Replace `8` with the page ID.

## Tests

```
phpunit modules/canvas_content_lock/tests/src/Kernel/ContentLockTest.php
```
