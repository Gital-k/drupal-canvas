# 21. Code component runtime compatibility across frontend modes

Date: 2026-09-21

## Status

Proposed.

## Context

Code Component rendering depends on the frontend mode:

- In Drupal-rendered frontends, Code Components are authored as React
  components. Drupal renders the surrounding page and embeds them as
  browser-rendered islands. The default runtime uses Preact, with React imports
  mapped to Preact's compatibility layer.
- In headless frontends, an application uses the Canvas Headless SDK to obtain
  page content and integrate Code Components into its rendering environment.
  Headless applications can use any frontend framework; components are not
  restricted to React.

The goal of this ADR is to enable reuse of the same React Code Components in
Drupal-rendered frontends and React-based headless frontends. Page and site data
remain available to non-React applications, but how those applications access
and distribute the data is outside this ADR's scope.

Every public `drupal-canvas` runtime API needs an explicit compatibility
decision for the two React Code Component environments: support both, deprecate
the API with replacement guidance, or identify unsupported headless usage with
actionable warnings and graceful fallback behavior. This includes data access,
Drupal integration, components, and rendering utilities.

## Decision

### Scope

This ADR covers all public `drupal-canvas` runtime exports, including root and
subpath exports, aliases, and runtime re-exports, together with their public
type contracts.

#### Page and site data

| API           | Public import paths and export names                                |
| ------------- | ------------------------------------------------------------------- |
| `getPageData` | Named export from `drupal-canvas` and `drupal-canvas/drupal-utils`. |
| `getSiteData` | Named export from `drupal-canvas` and `drupal-canvas/drupal-utils`. |

#### Data and Drupal integration

| API               | Public import paths and export names                                                                                                                                        |
| ----------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `JsonApiClient`   | Named export from `drupal-canvas` and `drupal-canvas/jsonapi-client`, including its constructor, options, inherited methods, response serialization, and cache behavior.    |
| `sortLinksetMenu` | Named export from `drupal-canvas`; exported as `sortMenu` from `drupal-canvas/drupal-utils`.                                                                                |
| `sortMenu`        | Named export from `drupal-canvas` and `drupal-canvas/jsonapi-utils`. This is distinct from the linkset helper exported under the same name by `drupal-canvas/drupal-utils`. |
| `getNodePath`     | Named export from `drupal-canvas` and `drupal-canvas/jsonapi-utils`.                                                                                                        |

#### Components

| API               | Public import paths and export names                                                          |
| ----------------- | --------------------------------------------------------------------------------------------- |
| `FormattedText`   | Named export from `drupal-canvas`; default export from `drupal-canvas/FormattedText`.         |
| `Image`           | Named export from `drupal-canvas`; default export from `drupal-canvas/next-image-standalone`. |
| `Region`          | Named export from `drupal-canvas`.                                                            |
| `RegionsProvider` | Named export from `drupal-canvas`.                                                            |

#### General and rendering utilities

| API                       | Public import paths and export names                                                           |
| ------------------------- | ---------------------------------------------------------------------------------------------- |
| `cn`                      | Named export from `drupal-canvas` and `drupal-canvas/utils`.                                   |
| `canvasTreeToSpec`        | Named export from `drupal-canvas/json-render-utils`.                                           |
| `specToCanvasTree`        | Named export from `drupal-canvas/json-render-utils`.                                           |
| `renderSpec`              | Named export from `drupal-canvas/json-render-utils`.                                           |
| `renderCanvasTree`        | Named export from `drupal-canvas/json-render-utils`.                                           |
| `defineComponentRegistry` | Named export from `drupal-canvas/json-render-utils`.                                           |
| `defineComponentCatalog`  | Named export from `drupal-canvas/json-render-utils`, including the returned catalog's methods. |

#### Upstream runtime re-exports

The following APIs are named exports from `drupal-canvas/jsonapi-client`,
re-exported through `@drupal-api-client/json-api-client`. They are not exported
from the package root.

- `DefaultSerializer`
- `createCache`

Together, these groups contain 19 distinct runtime APIs. Alternate import paths
and export names are included in the scope, not counted as additional APIs.

#### Public types and associated contracts

Named type exports from `drupal-canvas/json-render-utils`:

- `CanvasComponentTreeNode`
- `CanvasComponentTree`
- `AuthoredSpecElement`
- `AuthoredSpecElementMap`
- `AuthoredSpecSlots`
- `ComponentRegistry`

Named upstream type re-exports from `drupal-canvas/jsonapi-client`:

- `CreateOptions`
- `DeleteOptions`
- `EndpointUrlSegments`
- `EntityTypeWithBundle`
- `GetOptions`
- `JsonApiClientOptions`
- `JsonApiIndex`
- `RawApiResponseWithData`
- `RequestBaseOptions`
- `UpdateOptions`

The scope also includes the parameter, prop, return-value, and returned-object
contracts of each runtime API, even when their types are not separately exported
by name. This includes page and site data, menu inputs and outputs, component
props, and catalog validation results.

### Replace page and site getters with context hooks

Replace `getPageData()` with `usePageContext()` and `getSiteData()` with
`useSiteContext()` from `drupal-canvas` as the shared APIs for React Code
Components. The replacement hooks synchronously read React context; they do not
fetch data or read global Drupal settings directly.

The hooks expose the data currently returned by `getPageData()` and
`getSiteData()`:

- Page context: page title, breadcrumbs, and primary entity metadata, including
  language and translation information. The primary entity can be `null`.
- Site context: Drupal's backend base URL, branding, and theme logo and favicon
  information.

Deprecate `getPageData()` and `getSiteData()` in favor of the hooks, but keep
them working in Drupal-rendered Code Components for backward compatibility. The
getters are not supported in headless frontends. Components that need to work in
both frontend modes must migrate to the hooks.

A shared Code Component uses the same hooks in either frontend:

```tsx
import { usePageContext, useSiteContext } from 'drupal-canvas';

export default function PageHeader() {
  const page = usePageContext();
  const site = useSiteContext();

  if (!page || !site) return null;

  return (
    <header>
      <a href={site.branding.homeUrl}>{site.branding.siteName}</a>
      <h1>{page.pageTitle}</h1>
    </header>
  );
}
```

Unavailable context returns `null`, distinct from valid empty data or a page
without a primary entity. Report missing integration through a deduplicated,
actionable developer warning.

#### React-based headless rendering

Return page and site context with the component tree in the same `fetchPage()`
response. Application authors pass that context to `CanvasComponentTree`, which
establishes the provider internally. No separate provider or data request is
required in application code.

The proposed usage keeps the existing `tree={page.content}` contract:

```tsx
const page = await canvas.fetchPage('/about');

return (
  <CanvasComponentTree
    tree={page.content}
    context={page.context}
    components={componentRegistry}
  />
);
```

Here, the proposed `page.context` contains both `page` and `site` context. Any
React descendant of the renderer can consume it through the shared hooks.
Context belongs to the rendered tree and request, not a process-global current
page. Server rendering and initial hydration use the same snapshot; navigation
and preview updates replace context together with the corresponding content.

Drupal generates the data for the resolved page, preserving its language,
access, cacheability, and draft-preview semantics. The headless application does
not reconstruct Drupal metadata independently.

#### Public context API

Export `PageContext`, `SiteContext`, and `CanvasContext` from `drupal-canvas`.
`PageContext` and `SiteContext` preserve the existing getter data shapes:

```ts
interface CanvasContext {
  page: PageContext | null;
  site: SiteContext | null;
}
```

`fetchPage()` returns `CanvasContext` as `page.context`. The renderer accepts
`context?: CanvasContext` so existing renderer usage remains valid. Without
context, the hooks return `null` and report the missing integration as described
above.

Export `CanvasContextProvider` from `drupal-canvas` with a `context` prop and
React children. The rendering integrations use this provider internally.
Application authors can also use it to make context available to components
outside the Canvas tree, such as a site header:

```tsx
<CanvasContextProvider context={page.context}>
  <SiteHeader />
  <CanvasComponentTree
    tree={page.content}
    context={page.context}
    components={componentRegistry}
  />
</CanvasContextProvider>
```

The explicit provider is optional; the tree renderer still establishes its own
provider from its `context` prop.

#### Drupal-rendered Code Components

Wrap every Code Component island in the same provider through the shared island
renderer. The provider adds no DOM wrapper, and authors do not change their
component exports.

Each island needs its own provider, including islands nested through slots:
separate rendering roots do not inherit React context from one another. The
provider and hooks must share the same context instance through Drupal's
Preact-compatible runtime.

Extend import-based data-dependency detection to recognize the new hooks while
retaining support for the getters. This preserves selective settings attachment
rather than making every provider request all data unconditionally.

When a preview replaces or rerenders an island, supply fresh context. Changes to
`drupalSettings` alone are not reactive: settings-only updates must also refresh
the provider. Component-only previews must supply preview context or report it
as unavailable, not reuse an unrelated page's context.

Keep settings extraction separate from editor instrumentation. The existing
getters send `_canvas_useswr_data_fetch` messages to populate the code editor's
Component data panel; these messages do not fetch data or refresh previews.
Providers must not call both getters on every island mount merely to obtain
data. Preserve data inspection through deduplicated reporting of hook usage,
separate from the shared context mechanism.

#### Workbench previews

Workbench must support the same context hooks without requiring changes to Code
Components. Its interactive preview renders through `renderSpec()` in
`packages/workbench/src/client/PreviewFrameApp.tsx`, rather than through
`CanvasComponentTree`. Its generated preview entry in
`packages/workbench/src/server/preview-payload.ts` also renders directly. Both
paths must wrap the rendered output in `CanvasContextProvider`, including page
templates and their page content.

Change only the component-facing API, not Workbench's data-loading behavior. The
existing Vite integration loads `/canvas/api/v0/site-data`. Fetch once and pass
the data directly to the provider, preserving existing values and fallbacks,
including Drupal theme assets. Also populate `drupalSettings` for legacy
consumers, including `getPageData()`, `getSiteData()`, and the current
`JsonApiClient`. This is a compatibility mechanism, not the data source for the
new context API.

Workbench does not currently load page-specific Drupal context. Preserve the
existing page-data defaults: `pageTitle: ''`, `breadcrumbs: []`, and
`mainEntity: null`. These are the Workbench provider's page-context values, not
a missing-provider condition. Do not introduce page selection, additional page
requests, or authored fixtures as part of this change.

The interactive iframe stays mounted across preview targets. Ensure the provider
reflects the current data during preview updates and hot updates. Verify that
hooks and legacy getters expose equivalent data in both preview rendering paths.

#### Navigation URLs and headless theme assets

Preserve the existing Drupal-generated navigation URLs, including breadcrumbs,
translation links, and the branding home URL. Do not add headless-specific URL
rewriting; applications handle any routing adjustments.

In headless context, preserve the theme-assets shape but return empty values:

```ts
themeAssets: {
  logo: { url: '' },
  favicon: { url: '', mimeType: '' },
}
```

Drupal-rendered Code Components retain their existing theme assets. Shared
components must handle empty asset URLs. Empty theme assets do not make site
context unavailable or trigger a missing-context warning.

### Consolidate JSON:API clients behind a context hook

Combine the existing `drupal-canvas` client and the headless SDK's draft client
into one implementation in `drupal-canvas`. Both already extend
`@drupal-api-client/json-api-client`. The SDK remains responsible for
environment configuration and preview sessions; the shared client stays usable
in browsers.

Although this ADR targets React Code Component compatibility, the JSON:API
client and its supporting utilities must be framework-agnostic. Configuration,
draft handling, proxy logic, and URL mapping must work without React in any
frontend framework. React hooks and providers are a convenience layer over this
shared implementation, not a requirement for using it.

React Code Components use `useJsonApiClient()` from `drupal-canvas`. The hook
reads a client from context. It does not fetch data or create a client on each
render. Components can use the client with SWR or another fetching library.

The Drupal island renderer, headless React renderer, and both Workbench preview
paths add the provider automatically. Export `JsonApiClientProvider` with a
`client` prop for components outside the Canvas tree. Reuse the client while its
configuration and session stay unchanged. Without a provider, the hook returns
`null` and warns how to fix the missing integration, without repeating warnings.

Do not include the client in `page.context`. Server rendering and browser
hydration each create their own client with the right connection to Drupal. Keep
preview tokens out of React context and serialized page data.

Keep `new JsonApiClient()` working in Drupal-rendered Code Components and
Workbench. Components shared across frontend modes use the hook instead of
reading global Drupal settings through the constructor. Server code and custom
integrations can still construct a client explicitly.

#### Configuration and responsibilities

- Drupal supplies its backend URL and JSON:API settings. Keep existing
  authentication behavior: credentials and Drupal permissions determine which
  content is accessible. In Drupal previews, the provider configures the shared
  client to request `rel:working-copy`, using the editor's Drupal session and an
  explicit preview flag. Normal page rendering and legacy `new JsonApiClient()`
  calls keep their existing revision behavior. Unsaved Canvas editor state is
  outside this change's scope.
- Workbench uses its existing live Drupal configuration. Keep its data loading,
  previews, and settings for legacy consumers unchanged.
- The headless SDK reads the backend URL from explicit configuration or
  `CANVAS_SITE_URL`. It discovers the JSON:API prefix through
  `/canvas/api/v0/site-data`. If discovery fails, it uses the configured prefix
  or `CANVAS_JSONAPI_PREFIX`.
- The SDK supplies authentication to server requests and nonsecret preview
  configuration, including the resource version, to the browser client. Its
  public and draft client factories create the shared client instead of
  maintaining a separate subclass.

#### Headless browser requests use a JSON:API proxy

Add a same-origin JSON:API proxy to the headless application. Configure its full
upstream JSON:API base URL on the server. Map the proxy's local prefix to that
URL and forward the remaining path and query parameters, rather than listing
individual resource routes. The hook's browser client sends both public and
draft requests through it, using ordinary JSON:API paths and query parameters
rather than a client-operation protocol. The proxy forwards requests and
response bodies without processing JSON:API documents. Server code uses the same
client but calls Drupal directly.

```text
Code Component → shared browser client → application proxy → Drupal
Server code → shared server client → Drupal
```

Put the shared endpoint logic in the framework-independent headless SDK.
Framework adapters or custom application integrations mount the route and
provide access to the request and session. The proxy must not depend on React or
a React-specific adapter. This endpoint does not exist yet.

Reuse the SDK's preview session: a framework draft flag and an HTTP-only cookie
containing the token. Keep the `Secure`, `SameSite=None`, and `Partitioned`
cookie attributes needed for embedded previews. The browser sends these cookies
with same-origin requests, but component JavaScript cannot read the token. The
endpoint gets credentials and draft state from the session, not from values
supplied by component code.

Choose request behavior from the session:

- No preview session: make unauthenticated Drupal requests.
- Valid preview session: authenticate with the editor's credentials. The client
  supplies the resource version in the query; the proxy does not add it.
- Expired or invalid preview session: return a session error, not public
  content. The SDK's current `getClient()` falls back to public content on
  expiry, so the endpoint cannot reuse that behavior unchanged.

Connect session errors to preview renewal. Update the browser client's preview
configuration and refresh affected data after renewal or preview changes.

The existing headless preview authentication already supports operations under
the editor's Drupal permissions, including writes. The proxy reuses that access;
it does not add a separate read-only restriction. Drupal remains responsible for
authorizing each operation. Writes affect Drupal content, not an isolated
preview copy.

Restrict requests to the server-configured JSON:API base URL. Reject arbitrary
destinations and validate paths and redirects to keep requests within that
boundary. Do not forward arbitrary browser authentication headers or Drupal
response cookies. Restrict browser access to the application's origin and
protect state-changing requests against CSRF; CORS restrictions alone are not
CSRF protection.

Keep authenticated draft responses out of shared caches with private, no-store
responses. Disable client caches or separate them by session and resource
version. Clear stale browser data when preview state changes so draft content
cannot carry over into public mode or another session.

#### Draft reads and response compatibility

Move the SDK's draft-read behavior into the shared client. Apply it where the
client runs: in the browser for proxied requests and on the server for direct
requests. The proxy does not repeat this work:

- Resource reads use the session's resource version unless the caller selects
  one explicitly.
- Collection reads fetch each item's working copy in a separate request because
  Drupal returns default revisions in collections. If an item fetch fails, keep
  the original item. This does not discover every unpublished entity or make
  collection filtering and sorting use working-copy values.
- Raw responses bypass working-copy changes, as they do today.

The client handles serialization and raw-response options; the proxy does not
need to interpret them. Preserve Drupal's response bodies, status codes, and
safe response headers, except when the proxy rejects a request or reports a
session error. Direct and proxied reads must agree on supported query options,
languages, errors, and response shapes.

Route pagination and other supported Drupal links through the proxy in the
client's transport layer, without rewriting response bodies. Do not let an
absolute Drupal URL bypass the proxy. Methods that use endpoints outside the
configured JSON:API base URL require a separate compatibility decision.

Draft collections make an additional browser-to-proxy request for each working
copy. Accept this preview-only overhead in exchange for keeping draft behavior
in one client implementation and the proxy limited to HTTP forwarding,
authentication, and access controls.

## Remaining decisions

- The proxy's mount path and client-side mapping of Drupal URLs to the proxy.
  Check index discovery, pagination links, language prefixes, views, and path
  resolution against the configured JSON:API base URL.
- CSRF protection for proxied writes and cache invalidation after mutations.
- Public types for the client provider, and how preview renewal triggers browser
  data refresh and cache clearing.
- Compatibility decisions for the other runtime APIs listed in Scope.

## Consequences

React Code Components access page and site data without branching on frontend
mode. The Drupal settings integration and headless React renderer supply the
same component-facing contract through different data-delivery paths.

Implementation spans Drupal context generation, dependency detection, the island
renderer, the headless response, and React integration. Verification must cover
field parity, separate islands and trees, concurrent requests, hydration,
navigation, preview updates, missing context, and editor data inspection.

JSON:API consolidation also requires a headless server endpoint for portable
browser reads. Verify public and authenticated requests, custom prefixes,
working-copy resources and collections, response serialization, session expiry
and renewal, embedded-preview cookies, cache isolation, authorized and denied
writes, CSRF protection, and rejection of arbitrary destinations. Keep explicit
client construction and legacy Drupal and Workbench behavior covered.
