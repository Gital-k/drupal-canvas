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
existing Vite integration loads `/canvas/api/v0/site-data` and exposes the
result through `drupalSettings`. Populate the provider from the same settings,
preserving existing values and fallbacks, including Drupal theme assets. Keep
`getPageData()` and `getSiteData()` working for backward compatibility.

Workbench does not currently load page-specific Drupal context. Preserve the
existing page-data defaults: `pageTitle: ''`, `breadcrumbs: []`, and
`mainEntity: null`. These are the Workbench provider's page-context values, not
a missing-provider condition. Do not introduce page selection, additional page
requests, or authored fixtures as part of this change.

The interactive iframe stays mounted across preview targets. Ensure the provider
reflects the current settings during preview updates and hot updates. Verify
that hooks and legacy getters expose equivalent data in both preview rendering
paths.

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

## Remaining decisions

- Compatibility decisions for the other runtime APIs listed in Scope.

## Consequences

React Code Components access page and site data without branching on frontend
mode. The Drupal settings integration and headless React renderer supply the
same component-facing contract through different data-delivery paths.

Implementation spans Drupal context generation, dependency detection, the island
renderer, the headless response, and React integration. Verification must cover
field parity, separate islands and trees, concurrent requests, hydration,
navigation, preview updates, missing context, and editor data inspection.
