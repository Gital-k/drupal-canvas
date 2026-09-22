# Angular renderer and adapter compatibility proof

## Tested matrix

| Role                                           | Angular framework | CLI/build/SSR     | TypeScript |
| ---------------------------------------------- | ----------------- | ----------------- | ---------- |
| Partial library compiler                       | 21.2.23           | ng-packagr 21.2.7 | 5.9.3      |
| Independent consumer                           | 21.2.23           | 21.2.24           | 5.9.3      |
| Independent consumer (future template default) | 22.1.7            | 22.1.8            | 6.0.2      |

Node used: **24.21.0**. Verified npm package metadata, not assumed
compatibility: Angular 21 supports `^20.19.0 || ^22.12.0 || >=24.0.0`; Angular
22 requires `^22.22.3 || ^24.15.0 || >=26.0.0`. Canvas itself has a higher Node
floor than Angular 21. Angular 22 compiler-cli requires TypeScript `>=6.0 <6.1`.
The current Angular 21 patch allows `>=5.9 <6.1`, but this library and its
independent 21 consumer deliberately use **5.9.3**. Root TypeScript stays 5.9.3.

## Reproduce

From the Canvas checkout, after `npm ci`:

```sh
# Preserve the harness-provided AGENT_BROWSER_SESSION, or set your own task session.
node packages/headless-angular/compat/run.mjs
```

This builds shared dependencies and the Angular 21 partial library, packs once,
installs the same tarball in two independent dependency trees, generates
concrete registry source, builds both SSR applications, starts only owned
loopback servers on ports 4221/4222, and drives Chromium using the globally
installed `agent-browser`. It aborts if either port is occupied, and closes the
browser and owned servers after testing. No Drupal installation or fixture-root
workaround is needed. There is no assumed external preview hostname.

After intentionally changing the library or packed core, refresh the two local
tarball lock entries and run the same proof with:

```sh
node packages/headless-angular/compat/run.mjs --update-locks
```

The default run uses `npm ci`; it does not silently accept a stale artifact.
`angular21/package.json` and `angular22/package.json` are independently pinned
configurations, not npm workspaces. Generated `src` directories are disposable
copies of `shared`. The fixture contains only two purpose-built components, not
ports of the starter's 18 components. `index.ts` default exports and ordinary
Angular inputs are discovered through the existing shared generator and YAML.

After the dual-major build, verify development discovery against both actual
Angular CLI dev servers (ports 4321/4322):

```sh
node packages/headless-angular/compat/dev-proof.mjs
npm test -w @drupal-canvas/headless-angular
```

This adds, renames and removes a temporary component in each generated app and
checks SSR, authenticated metadata and browser live reload. It uses only public
`ng serve` and the packaged `canvas-angular` watcher. It closes its owned
process groups and browser, and removes temporary fixture components.

For an already-running pair of fresh fixture servers:

```sh
node packages/headless-angular/compat/verify.mjs
```

## Checks and evidence

Generated, ignored artifacts are under `compat/artifacts/`:

- `drupal-canvas-headless-angular-0.0.0.tgz`: the **same** partial artifact for
  both majors.
- `drupal-canvas-headless-0.7.0.tgz`: the checkout's shared core.
- `browser-proof.json`: per-major artifact hashes/integrities, real hydration
  diagnostics, captured-node identity, shared geometry and integration results.
- `dev-proof.json` and `angular{21,22}-dev.log`: Angular CLI watcher/live-reload
  evidence.
- `angular{21,22}-{draft,published,empty,empty-top}-ssr.html`: actual HTTP SSR
  responses, not browser-serialized post-hydration DOM.

Both consumers must pass all assertions:

- SSR response really has `ng-server-context="ssr"`; no CSR fallback.
- Partial Angular 21 declarations are present in each installed package; tarball
  integrity and FESM hashes match between consumers.
- Real browser hydration reports zero skipped components and no page/console
  errors.
- SSR frame and slot hosts, interactive buttons, marker elements and markup
  hosts survive hydration by reference identity. Angular's normal `[innerHTML]`
  binding replaces the **raw markup descendants**; this is recorded explicitly,
  not misreported as full DOM identity preservation. Trusted markup tags and
  inline styles survive.
- Nested/default/named slots and exact expected marker tuples (position, type,
  UUID/region ID, slot name), not just balanced counts.
- Shared geometry measures actual button bounds and nonzero empty-slot/region
  targets.
- Published SSR and hydrated/refreshed DOM have no editor markers or drop
  targets.
- A real server-data fetch changes inputs, prepends a new UUID, explicitly
  reverses two existing siblings, and updates nested children without replacing
  existing UUID-backed components/frame/slot hosts or losing click state.
- Concurrent SSR requests carry different labels and synthetic private cookies;
  neither label leaks to the other response, and cookie values never enter HTML.
- Explicit empty content-region and implicit top-level empty-region behavior.

## Architecture and stopping point

Public app API: `CanvasComponentTree` with `tree`/`components` inputs, plus
`CanvasSlot` (`name` defaults to `default`). Slots are ordinary Angular views
under a node-scoped injector, not `projectableNodes` constructed from detached
DOM. There are no private Angular APIs or `ngSkipHydration` escape hatches.

Angular custom-element hosts remain in the DOM, even with `display: contents`.
Future component ports must account for direct-child CSS selectors and
intentionally style their hosts. This is the architectural difference from
renderless React/Vue fragments. Template markers use the shared geometry
protocol, unchanged.

## Integrated server/session proof

The fixture has a deliberately fake, loopback-only Drupal transport. It returns
fixture assertions/tokens and content, exercising the **real shared SDK**
exchange, cookie, metadata, draft lifecycle, geometry and host implementations.
It does not implement or claim Drupal JWT verification/permissions. No fixture
endpoints are part of the packaged adapter.

Both majors additionally verify:

- HTTP SSR redirects/404, server-only credentials, canonical/alternate links and
  safely serialized JSON-LD; client redirects/404 and head replacement.
- Actual shared host activation, HttpOnly cookie invisibility, same-origin
  draft-aware Angular navigation with query strings and router path reporting.
- Host refresh retaining component state, shared geometry exchange, and explicit
  three-request refresh-queue coalescing into two serialized passes.
- Timer-driven in-place renewal from a 20-second fixture token to a 900-second
  token, without reloading the iframe or losing its state.
- Actual Angular application destruction removing shared protocol listeners,
  canceling queued work and removing owned head nodes.
- Expired-cookie anonymous fallback with visible expiry, POST exit and cookie
  deletion, then public same-origin data access.
- Entity fetching under the draft session and authenticated metadata with the
  generated source directories **physically moved away** during the test.
  Runtime metadata is embedded in the server bundle, not discovered from source
  files.

Unit tests additionally cover request isolation, identity-pinned renewal/PKCE,
CHIPS serialization/deletion attributes, CSP preservation, method/origin checks,
component-preview context, malformed metadata and watcher recovery.

The package README is the stable adapter/template authoring contract. No
template sibling or shared auth/protocol package was changed. No Workbench.
Remaining work: 18 template component ports and starter CLI selection; live
Drupal end-to-end validation; HTTPS cross-site CHIPS/renewal testing across the
supported browser matrix (the current real-browser fixture uses same-origin
loopback Chromium). Stop at this integration milestone for review.
