# @drupal-canvas/headless-angular

Angular 21/22 bindings for the Drupal Canvas Headless SDK. The library is built
with Angular 21/TypeScript 5.9 partial compilation; the same packaged artifact
is linked and tested independently with Angular 21/TS 5.9 and Angular 22/TS 6.
Angular 22 is the intended starter default. See `compat/README.md` in the Canvas
repository for pinned versions, Node requirements, and executable proofs.

## Component authoring

```ts
import { Component, input } from '@angular/core';
import { CanvasSlot } from '@drupal-canvas/headless-angular';

@Component({
  selector: 'app-section',
  host: { style: 'display: contents' },
  imports: [CanvasSlot],
  template: `<section [class.wide]="width() === 'wide'">
    <canvas-slot name="content" />
  </section>`,
})
export default class Section {
  readonly width = input('normal');
}
```

Keep `component.yml` and mocks unchanged. Implementations are standalone Angular
components with normal inputs, default-exported from `index.ts`. Registry keys
remain YAML machine names. Canvas UUID metadata is stripped from component
inputs. `<canvas-slot />` renders the default slot; `name` selects a named slot.
Slots use a node-scoped injector and ordinary Angular views, not manually
projected DOM. Render each slot once, inside a registry component's view.

Render
`<canvas-component-tree [tree]="page.content" [components]="components" />`,
importing `components` from `./canvas-components.generated`. Import
`@drupal-canvas/headless/preview.css` in the global stylesheet. Draft trees emit
shared editor boundaries/drop targets; published trees do not.

Angular hosts remain in the DOM even with `display: contents`. Account for this
in direct-child CSS selectors. Use `host: { style: 'display: contents' }` when a
port must not introduce a box, or style the host deliberately; default inline
hosts can produce incorrect editor geometry. No private Angular APIs or
`ngSkipHydration`.

### Trusted HTML is not stateful Angular content

Only pass trusted Drupal-rendered trees. Raw HTML deliberately bypasses Angular
sanitization, including trust in attributes and URLs. Do not pass user input.
Angular reassigns `[innerHTML]` during hydration and when the binding changes,
so raw descendants do **not** retain DOM identity. The proof preserves ordinary
HTML content and styles; no ordinary-rich-text regression has been observed.

Renderer raw tree strings/`drupal-markup` are distinct from HTML-valued
component props. The existing Text, Hero, Card, Blockquote and Two Column Text
components own the latter; Angular ports can use `CanvasMarkup` (`[html]`) for
trusted HTML props. Video, accordion interactions and draft-exit forms are
component markup, not raw renderer HTML. No Drupal behavior/lifecycle machinery
is added here.

## Discovery and build

Configure `canvas.config.json` with `componentDir`, `globalCssPath`, and normal
shared discovery options. Add these application scripts:

```json
{
  "scripts": {
    "start": "canvas-angular --watch -- ng serve",
    "build": "canvas-angular -- ng build"
  }
}
```

The public Node command writes two concrete modules before Angular CLI starts:

- `src/canvas-components.generated.ts`: static component imports; browser-safe.
- `src/canvas-manifest.generated.ts`: metadata; import **only from server.ts**.

Ignore both generated files in Git. The watcher tracks component files and
config, including additions, renames, deletions and YAML changes. It
serializes/debounces regeneration, writes only changed content atomically, and
stops with the owned CLI process. Invalid initial metadata fails the build;
development errors are reported and recover on the next valid edit. Angular CLI
watches the generated source using its normal builder/live-reload pipeline;
registry structure changes trigger page reloads. No private Vite configuration
or plugin hook. Production metadata is embedded in the server bundle and needs
no component source files or runtime filesystem discovery. The older
`tools/generate-registry.mjs` command is registry-only; use `canvas-angular` for
integrated applications.

## Server wiring (Node, server.ts only)

After mounting static assets and any app-owned routes, adapt the standard
Angular SSR server using its public APIs:

```ts
import {
  AngularNodeAppEngine,
  createWebRequestFromNodeRequest,
  writeResponseToNodeResponse,
} from '@angular/ssr/node';
import { createCanvasHandler } from '@drupal-canvas/headless-angular/server';

import manifest from './canvas-manifest.generated';

const angular = new AngularNodeAppEngine();
const canvas = createCanvasHandler({ manifest }); // CANVAS_SITE_URL on the server
app.use((req, res, next) => {
  canvas(createWebRequestFromNodeRequest(req), (request, context) =>
    angular.handle(request, context),
  )
    .then((response) => writeResponseToNodeResponse(response, res))
    .catch(next);
});
```

Keep the CLI-generated `createNodeRequestHandler(app)` export and listen setup.
Configure actual trusted hostnames in Angular's `security.allowedHosts`; do not
accept arbitrary forwarded host headers. Use on-demand SSR (`RenderMode.Server`)
for Canvas routes, never prerender draft content. The standard
`provideServerRendering(withRoutes(...))` configuration is app-owned.

This handler mounts shared SDK flows at:

| Endpoint                                      | Method       | Purpose                                      |
| --------------------------------------------- | ------------ | -------------------------------------------- |
| `/api/draft`                                  | GET          | Assertion redemption and activation redirect |
| `/api/draft/renew`                            | POST         | PKCE-bound, identity-pinned renewal          |
| `/api/disable-draft`                          | POST         | Same-origin exit and 303 redirect            |
| `/api/canvas/components`                      | GET, OPTIONS | Shared assertion-authenticated metadata/CORS |
| `/api/canvas/page?path=…`                     | GET          | Same-origin draft-aware page/session data    |
| `/api/canvas/entity?type=…&id=…&viewMode=…`   | GET          | Same-origin draft-aware entity render        |
| `/api/canvas/component-preview?componentId=…` | GET          | SSR preview under the current draft context  |

Other GET/HEAD routes resolve through Drupal before SSR. Drupal redirects retain
their status/location; missing or denied pages render the app's not-found UI
with HTTP 404. Rendered page route/entity identity is preserved. Entity and
component preview requests reuse the shared content clients and signed context
semantics.

Each request gets its own server/cookie context. Cookie encoding, shared CHIPS
attributes (HttpOnly, Secure, SameSite=None, Partitioned), and matching
expiry-based deletions are preserved. HTTPS is required in deployed cross-site
previews. CSP merges the signed editor's `frame-ancestors` without weakening
app-owned policies. Responses are private/no-store, including public pages,
redirects and data routes. There is no new authentication protocol or
configurable shared bearer credential.

For custom mounting, `createCanvasRequest(request, options)` exposes `server`,
`session`, `loadPage`, `handle`, and `finalize`. Always finalize responses so
cookies, CSP and cache policy are applied. The server accessor exposes shared
`getClient`, `fetchPage`, `fetchEntity`, and `fetchComponentPreview`; never pass
it to TransferState.

## Angular routing, data and session lifecycle

Add `provideCanvas()` to both browser and server bootstrap providers, alongside
`provideClientHydration()` and `provideRouter(routes)`. A minimal route:

```ts
import { canvasPageResolver } from '@drupal-canvas/headless-angular';

export const routes = [
  {
    path: '**',
    component: CanvasPage,
    resolve: { canvas: canvasPageResolver },
    runGuardsAndResolvers: 'always',
  },
];
```

`CanvasPage` injects `CanvasPageStore`. Its `page()` signal is a rendered page
or null (show your not-found UI); `data()` also exposes the public session
projection. Render the tree with the generated registry. The resolver handles
client redirects and updates title, meta, links and inert JSON-LD via
`CanvasDocumentHead`, owning only tags marked `data-canvas-head`. External
redirects use document navigation.

SSR uses Angular's request-scoped `REQUEST_CONTEXT` supplied by the handler.
Only `{ page, session }` enters TransferState; the context and its entity-fetch
callback do not. Session data is an explicit allowlist: enabled, expiry, expired
state, renew URL and editor origin. Tokens, PKCE verifier and server
configuration stay server-only. Browser navigation/refresh/entity requests go to
the fixed same-origin endpoints with cookies, never directly to Drupal with a
bearer token. Do not import the `/server` entry or generated manifest into
browser code.

`CanvasPageStore.refresh()` uses the shared serialized/coalescing queue and
waits for pending router navigation (including redirect chains). Refresh never
aborts a resolver. `path()` is the committed route; `pendingPath()` is the
pending destination or null. Page data/head commit on successful navigation, not
while a resolver is still running. Refresh redirects use the same
internal-router versus external-document behavior as resolver redirects,
retaining the current page until navigation completes. Superseded requests are
aborted and generation-guarded; teardown aborts outstanding page and entity
requests. `fetchEntity({ type, id, viewMode })` works through the request-scoped
callback in SSR and the same-origin endpoint in the browser.

`provideCanvas()` starts `CanvasDraftSession` **after hydration**. It owns the
shared session machine, height reporter and geometry bridge, updates the current
router path without resetting the epoch, re-arms after renewal, and destroys its
listeners/timers/observers with the Angular application. It uses the unchanged
`@drupal-canvas/headless-host` protocol. Inject it for `embedded()`, `expired()`
and `renewState()` signals. `embedded()` is null until hydration; `expired()`
uses the server's expiry state for the initial render. The app owns banner
markup and the signed standalone renew link. Show banners only when
`store.session()?.enabled`; embedded active previews normally hide the banner.
Like the existing framework templates, exit uses an ordinary form, with no
client-side cookie or navigation machinery:

```html
<form ngNoForm method="POST" action="/api/disable-draft">
  <button type="submit">Exit draft</button>
</form>
```

## Ownership and next milestone

Canvas owns this adapter, shared contracts/protocol, generated-source command,
and compatibility fixtures. The template owns Angular CLI configuration,
bootstrap, SSR server mounting, route/page/banner presentation, CSS, assets and
component implementations. No template sibling has been edited. Template authors
can now use the wiring above without duplicating cookies, OAuth, metadata
discovery, refresh queues, geometry, or host messaging. No Workbench; the 18
component ports and CLI starter selection remain subsequent work.

Build from the Canvas workspace with
`npm run build -w @drupal-canvas/headless-angular`. Pack **dist**, not the
source directory: `npm pack ./packages/headless-angular/dist`. See
`compat/README.md` for actual validation and limitations; mock-transport proofs
are not live Drupal auth tests.
