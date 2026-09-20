// eslint-disable-next-line @typescript-eslint/triple-slash-reference
/// <reference path="./virtual.d.ts" />

import canvasComponents from 'virtual:@drupal-canvas/headless/components';
import { CanvasComponentTree as ReactCanvasComponentTree } from '@drupal-canvas/headless-react';

import type { CanvasComponentTreeProps as ReactCanvasComponentTreeProps } from '@drupal-canvas/headless-react';

export type CanvasComponentTreeProps = Pick<
  ReactCanvasComponentTreeProps,
  'tree' | 'context' | 'jsonApi'
>;

/**
 * Renders a Canvas tree with every component discovered by canvas().
 *
 * Pass `context={page.context}` so `usePageContext()` and `useSiteContext()`
 * see the routed page's data, and `jsonApi` from `getJsonApiRuntimeConfig()`
 * (read in a server function alongside `fetchPage()`) so `useJsonApiClient()`
 * reaches Drupal through the application's same-origin proxy.
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
      components={canvasComponents}
    />
  );
}

export default CanvasComponentTree;
