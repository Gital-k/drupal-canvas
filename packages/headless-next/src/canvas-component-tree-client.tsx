// eslint-disable-next-line @typescript-eslint/triple-slash-reference
/// <reference path="./virtual.d.ts" />

'use client';

import canvasComponents from '@drupal-canvas/headless-next-generated-components';
import { CanvasComponentTree as ReactCanvasComponentTree } from '@drupal-canvas/headless-react';

import type { CanvasComponentTreeProps as ReactCanvasComponentTreeProps } from '@drupal-canvas/headless-react';

export type CanvasComponentTreeClientProps = Pick<
  ReactCanvasComponentTreeProps,
  'tree' | 'context' | 'jsonApi'
>;

/**
 * The client boundary of the Next.js renderer: registered components render
 * on the server for the initial HTML and hydrate in the browser, where hooks
 * and interactivity run. Prefer `CanvasComponentTree` (the server wrapper),
 * which supplies the JSON:API runtime configuration automatically; use this
 * component directly from client components, passing `jsonApi` yourself.
 */
export function CanvasComponentTreeClient({
  tree,
  context,
  jsonApi,
}: CanvasComponentTreeClientProps) {
  return (
    <ReactCanvasComponentTree
      tree={tree}
      context={context}
      jsonApi={jsonApi}
      components={canvasComponents}
    />
  );
}

export default CanvasComponentTreeClient;
