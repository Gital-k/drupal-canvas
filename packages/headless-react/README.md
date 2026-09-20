# @drupal-canvas/headless-react

Shared React bindings for the Drupal Canvas Headless SDK.

The framework adapters (`@drupal-canvas/headless-next`,
`@drupal-canvas/headless-tanstack-start`) re-export these components with their
router wiring filled in — use those in an app. An app on a React framework
without an adapter can use this package directly; it depends only on React and
`@drupal-canvas/headless`.

## Installation

```bash
npm install @drupal-canvas/headless-react
```

## Usage

### Draft session

`<DraftSession>` runs the in-editor session renewal protocol and drives your
banner UI through a render prop. It takes the session state gathered on the
server, plus two framework hooks:

- `path` — the current pathname from the router, reported to the embedding
  editor and carried by the renew link.
- `refreshData` — the framework's server-data refresh (`router.refresh()` in
  Next.js). Optional: without it the component resets its expiry timer in place
  from the renew endpoint's response.

### Component rendering

`<CanvasComponentTree>` renders the structured content returned by
`fetchPage()`. Registry keys are `component.yml` machine names:

```tsx
import { CanvasComponentTree } from '@drupal-canvas/headless-react';

import HelloCard from './components/canvas/hello-card';

<CanvasComponentTree
  tree={page.content}
  components={{ 'hello-card': HelloCard }}
/>;
```

Pass `context={page.context}` so registered components can read the page and
site context through `usePageContext()` and `useSiteContext()` from
`drupal-canvas`: the renderer wraps the tree in `CanvasContextProvider`. An
explicit `context` prop takes precedence over an outer provider, `null` values
included; when omitted, an outer `CanvasContextProvider` is inherited.

`useJsonApiClient()` is served from the nonsecret JSON:API runtime configuration
the SDK's server integration prepares (`getJsonApiRuntimeConfig()`): either the
`jsonApi` prop, or the nearest `JsonApiRuntimeProvider` (framework adapters
supply one, for example the Next.js `CanvasRuntime` server component; TanStack
Start applications render it in the root route from loader data). Without
configuration an outer `JsonApiClientProvider` is inherited, and without that
the hook reports the missing provider. In the browser the client sends requests
through the application's same-origin proxy. Server rendering creates its own
client from the same configuration. Adapters whose server rendering runs inside
the request pass `jsonApiFetch`, a request-aware transport that answers the
client's proxy-mapped requests in-process with the current request's draft
session (TanStack Start does this), so the hook returns the same non-null,
draft-aware client on both sides of hydration and SWR fallback data renders into
the initial HTML without a hydration mismatch; credentials never cross the
server/client boundary. Without such a transport, public rendering gets a
direct, unauthenticated client, and a live draft session gets no client, so
draft rendering never falls back to published content. Data needed in the
initial HTML is prefetched with the SDK's `getClient()` and supplied as SWR
fallback data (see `@drupal-canvas/headless`).

Named Canvas slots become React props with rendered `ReactNode` values; a
`default` slot becomes `children`. Drupal markup strings are inserted as trusted
HTML. Because React does not natively support rendering comment nodes, draft
trees use layout-neutral `<template>` markers for Canvas boundaries, which may
affect structural CSS selectors. Published trees remain marker-free.
