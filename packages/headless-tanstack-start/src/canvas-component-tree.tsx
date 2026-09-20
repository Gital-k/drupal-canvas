// eslint-disable-next-line @typescript-eslint/triple-slash-reference
/// <reference path="./virtual.d.ts" />

import canvasComponents from 'virtual:@drupal-canvas/headless/components';
import { CanvasComponentTree as ReactCanvasComponentTree } from '@drupal-canvas/headless-react';

import { getServerJsonApiFetch } from './jsonapi-fetch';

import type { CanvasComponentTreeProps as ReactCanvasComponentTreeProps } from '@drupal-canvas/headless-react';

export type CanvasComponentTreeProps = Pick<
  ReactCanvasComponentTreeProps,
  'tree' | 'context' | 'jsonApi'
>;

/**
 * The request-aware transport for server rendering; `undefined` in the
 * browser (see ./jsonapi-fetch).
 */
const serverJsonApiFetch = getServerJsonApiFetch();

/**
 * Renders a Canvas tree with every component discovered by canvas().
 *
 * Pass `context={page.context}` so `usePageContext()` and `useSiteContext()`
 * see the routed page's data, and `jsonApi` from `getJsonApiRuntimeConfig()`
 * (read in a server function alongside `fetchPage()`, or supplied through
 * `JsonApiRuntimeProvider`) so `useJsonApiClient()` reaches Drupal through
 * the application's same-origin proxy. During server rendering the client
 * answers requests in-process with the current request's draft session, so
 * the hook returns the same draft-aware client on both sides of hydration.
 */
export function CanvasComponentTree({
  tree,
  context,
  jsonApi,
}: CanvasComponentTreeProps) {
  return (
    <ReactCanvasComponentTree
      tree={tree}
      context={context}
      jsonApi={jsonApi}
      jsonApiFetch={serverJsonApiFetch}
      components={canvasComponents}
    />
  );
}

export default CanvasComponentTree;
