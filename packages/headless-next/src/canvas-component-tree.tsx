import { CanvasComponentTreeClient } from './canvas-component-tree-client';
import { getJsonApiRuntimeConfig } from './server';

import type { CanvasComponentTreeClientProps } from './canvas-component-tree-client';

export type CanvasComponentTreeProps = CanvasComponentTreeClientProps;

/**
 * Renders a Canvas tree with every component discovered by withCanvas().
 *
 * A server component: it prepares the request's nonsecret JSON:API runtime
 * configuration (proxy path, resolved endpoints, preview state) and hands the
 * tree to the client boundary, so `useJsonApiClient()` works in registered
 * components without application code. Pass `context={page.context}` so
 * `usePageContext()` and `useSiteContext()` see the routed page's data; an
 * explicit `jsonApi` prop replaces the automatic configuration.
 *
 * Render it from server components (pages, layouts). From a client component,
 * use `CanvasComponentTreeClient` and pass `jsonApi` from a server function.
 */
export async function CanvasComponentTree({
  tree,
  context,
  jsonApi,
}: CanvasComponentTreeProps) {
  const runtimeConfig = jsonApi ?? (await getJsonApiRuntimeConfig());
  return (
    <CanvasComponentTreeClient
      tree={tree}
      context={context}
      jsonApi={runtimeConfig}
    />
  );
}

export default CanvasComponentTree;
