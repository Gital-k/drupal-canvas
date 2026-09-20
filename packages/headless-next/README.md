# @drupal-canvas/headless-next

Next.js adapter for the Drupal Canvas Headless SDK.

It gives a Next.js app draft preview bound to the editing user, in-place session
renewal inside the Canvas editor frame, and the component metadata endpoint
Drupal Canvas registers the app's components from.

## Installation

```bash
npm install @drupal-canvas/headless-next
```

Set the `CANVAS_SITE_URL` environment variable to your Drupal site URL.

## Usage

**1. next.config.ts** — the config wrapper generates the component manifest at
build time, adds the SDK packages to `transpilePackages`, and sends a
session-aware CSP `frame-ancestors` header:

```ts
import { withCanvas } from '@drupal-canvas/headless-next/config';

export default withCanvas();
```

**2. Route files** — mount the handlers, one file per route:

```ts
// app/api/draft/route.ts
import { createDraftRouteHandlers } from '@drupal-canvas/headless-next';

export const GET = createDraftRouteHandlers().draft.GET;
```

```ts
// app/api/draft/renew/route.ts
import { createDraftRouteHandlers } from '@drupal-canvas/headless-next';

export const POST = createDraftRouteHandlers().draftRenew.POST;
```

```ts
// app/api/disable-draft/route.ts
import { createDraftRouteHandlers } from '@drupal-canvas/headless-next';

export const POST = createDraftRouteHandlers().disableDraft.POST;
```

```ts
// app/api/canvas/components/route.ts
import { createComponentMetadataHandler } from '@drupal-canvas/headless-next';

export const runtime = 'nodejs';
export const dynamic = 'force-dynamic';
export const { GET, OPTIONS } = createComponentMetadataHandler();
```

```tsx
// app/api/canvas/component-preview/page.tsx
export { default } from '@drupal-canvas/headless-next/ComponentPreviewPage';
```

```ts
// app/api/canvas/jsonapi/[[...path]]/route.ts
import { createDraftRouteHandlers } from '@drupal-canvas/headless-next';

export const { GET, HEAD, POST, PATCH, DELETE, OPTIONS } =
  createDraftRouteHandlers().jsonApiProxy;
```

The last route is the same-origin JSON:API proxy portable Code Components reach
Drupal through (see `@drupal-canvas/headless`); mount it at the path configured
by `CANVAS_JSONAPI_PROXY_PATH` (default `/api/canvas/jsonapi`).

**2b. Root layout** — the `CanvasRuntime` server component supplies the
request's nonsecret JSON:API runtime configuration to every
`CanvasComponentTree` below it, so `useJsonApiClient()` works in registered
components:

```tsx
// app/layout.tsx
import { CanvasRuntime } from '@drupal-canvas/headless-next/CanvasRuntime';

<CanvasRuntime>{children}</CanvasRuntime>;
```

**3. Session banner** — a server component gathers the session state
(`getDraftData()`, `getDraftEditorOrigin()`, `isDraftSessionExpired()`) and
renders `<DraftSession>` from `@drupal-canvas/headless-next/client` with a
render prop that owns the banner markup.

**4. Component tree** — pass the structured content returned by `fetchPage()` to
`<CanvasComponentTree>`:

```tsx
import { CanvasComponentTree } from '@drupal-canvas/headless-next/CanvasComponentTree';

<CanvasComponentTree tree={page.content} context={page.context} />;
```

`withCanvas()` generates a registry of every discovered component
implementation, and the renderer consumes it automatically. During development
the registry updates when components are added, removed, or renamed.

`CanvasComponentTree` keeps its `'use client'` boundary: registered components
render on the server for the initial HTML and hydrate in the browser, where
hooks and interactivity run. `context={page.context}` lets components read the
page and site data through `usePageContext()` and `useSiteContext()`; an
explicit `jsonApi` prop replaces the configuration `CanvasRuntime` supplies.
Server rendering a component does not run its SWR fetcher: prefetch data with
`getClient()` in the page and supply it through a client `SWRConfig` wrapper
(`fallback`) so the initial HTML contains it — see `@drupal-canvas/headless`.
During server rendering the hook's client is the same draft-aware client as in
the browser, so fallback data renders and hydration matches, but it performs no
network requests in a draft session (`ServerRenderingDraftFetchError` names the
fix); public pages get a direct client.

## Data access

`getClient()` returns the draft-aware JSON:API client; `fetchPage()` fetches
Canvas-rendered content when available, plus route and document-head data, for a
path resolved through Drupal routing. Both are draft-session-aware. Render
`page.content` directly. Use `toNextMetadata(page.head)` from
`@drupal-canvas/headless-next` in `generateMetadata()`. Handle `PageRedirect`
before page rendering with `permanentRedirect()` for permanent redirects and
`redirect()` for other redirects.

The client's JSON:API prefix is resolved from the site's public site-data
endpoint (fetched once per server instance), so sites serving JSON:API from a
non-default prefix (e.g. `/api`) work without configuration. When that endpoint
is unreachable, the `CANVAS_JSONAPI_PREFIX` environment variable applies, then
the `/jsonapi` default; `CANVAS_JSONAPI_URL` sets a full upstream URL that takes
precedence over discovery. `getPublicClient()` and `getDraftClient()` are async
for the same reason: `await` them like `getClient()`. All three create the
shared `drupal-canvas` client (`createJsonApiClient()`), which deserializes
responses with `DefaultSerializer`.

`getJsonApiRuntimeConfig()` returns the nonsecret configuration browser clients
are created from; `CanvasRuntime` passes it down automatically, and
`handleJsonApiProxy(request)` is the proxy the `jsonApiProxy` route handlers
mount.

`fetchEntity({ type, id, viewMode })` renders one content entity without
page-level route or head data. Use it for embedded renders such as teaser cards.

`toNextMetadata()` maps the Canvas head entries that Next.js Metadata can
represent. It omits entries that Next.js Metadata cannot represent. Render
omitted entries as native head elements in the page or layout.
