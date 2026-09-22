# Angular renderer compatibility proof — milestone 1

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

For an already-running pair of fixture servers:

```sh
node packages/headless-angular/compat/verify.mjs
```

## Checks and evidence

Generated, ignored artifacts are under `compat/artifacts/`:

- `drupal-canvas-headless-angular-0.0.0.tgz`: the **same** partial artifact for
  both majors.
- `drupal-canvas-headless-0.7.0.tgz`: the checkout's shared core.
- `browser-proof.json`: per-major artifact hashes/integrities, real hydration
  diagnostics, captured-node identity, and shared geometry measurements.
- `angular{21,22}-{draft,published,empty,empty-top}-ssr.html`: actual HTTP SSR
  responses, not browser-serialized post-hydration DOM.

Both consumers must pass all assertions:

- SSR response really has `ng-server-context="ssr"`; no CSR fallback.
- Partial Angular 21 declarations are present in each installed package; tarball
  integrity and FESM hashes match between consumers.
- Real browser hydration reports zero skipped components and no page/console
  errors.
- SSR component hosts, interactive buttons, marker elements and markup hosts
  survive hydration by reference identity. Angular's normal `[innerHTML]`
  binding replaces the **raw markup descendants**; this is recorded explicitly,
  not misreported as full DOM identity preservation. Trusted markup tags and
  inline styles survive.
- Nested/default/named slots and exact balanced UUID/component/slot/region
  markers.
- Shared geometry measures actual button bounds and nonzero empty-slot/region
  targets.
- Published SSR and hydrated/refreshed DOM have no editor markers or drop
  targets.
- A real server-data fetch changes inputs, prepends a new UUID, and updates
  nested children without replacing existing UUID-backed components or losing
  click state.
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

Angular CLI doesn't expose a public Vite plugin hook. A small Node prebuild
command uses the shared discovery/registry source generator, normalizing
TypeScript import extensions for the Angular compiler. Adding/removing/renaming
a component currently requires rerunning it; a watch/build integration is future
work.

**Not tested or implemented in this milestone:** Drupal OAuth
activation/renewal, CHIPS cookies, authenticated metadata endpoints, embedded
host-driven refresh, thumbnail routes, catch-all Drupal resolution/redirect/head
mapping, CLI scaffolding, and all starter component ports. The fixture's refresh
endpoint returns deterministic render trees; it is not a claim of authenticated
Drupal integration. Request isolation proof covers rendering/TransferState, not
a future server adapter. Those integrations must reuse shared server
flows/cookies and client draft lifecycle/host protocol rather than invent new
ones. No Workbench. No sibling edits. Stop here for review.
