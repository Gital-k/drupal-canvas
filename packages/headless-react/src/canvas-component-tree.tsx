import { createElement, useMemo } from 'react';
import {
  CanvasContextProvider,
  createJsonApiClient,
  JsonApiClientProvider,
  useHasJsonApiClient,
} from 'drupal-canvas';

import '@drupal-canvas/headless/preview.css';

import {
  CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS,
  CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS,
  CANVAS_PREVIEW_CONTENT_REGION_ELEMENT,
  findCanvasComponent,
  getCanvasComponentRenderData,
  getCanvasTemplateMarkerAttributes,
  hasCanvasPreviewContentRegion,
  isCanvasComponentTreeDraft,
  isCanvasComponentTreeEmpty,
  isCanvasComponentTreeSlotEmpty,
  normalizeCanvasComponentTreeSlot,
  reportMissingCanvasComponent,
  reportMissingCanvasComponentUuid,
} from '@drupal-canvas/headless';

import { useJsonApiRuntimeConfig } from './jsonapi-runtime';

import type { ElementType, ReactNode } from 'react';
import type {
  CanvasComponentTreeElement,
  CanvasMarker as CanvasMarkerProps,
} from '@drupal-canvas/headless';
import type { CanvasContext, JsonApiRuntimeConfig } from 'drupal-canvas';

/** App component implementations keyed by component.yml machine name. */
export type CanvasComponentRegistry = Record<string, ElementType>;

export interface CanvasComponentTreeProps {
  tree: CanvasComponentTreeElement | null;
  components: CanvasComponentRegistry;
  /**
   * The page and site context from `fetchPage()` (`page.context`). When
   * supplied, the tree renders inside its own `CanvasContextProvider`,
   * taking precedence over any outer provider, `null` values included. When
   * omitted, an outer `CanvasContextProvider` is inherited without adding
   * another provider.
   */
  context?: CanvasContext;
  /**
   * The nonsecret JSON:API runtime configuration prepared by the SDK's
   * server integration (`getJsonApiRuntimeConfig()`). When omitted, the
   * configuration of the nearest `JsonApiRuntimeProvider` applies (framework
   * adapters supply it from their server integration). With configuration,
   * the renderer creates the browser client for `useJsonApiClient()`, which
   * sends requests through the application's same-origin proxy. Without
   * configuration, an outer `JsonApiClientProvider` is inherited; otherwise
   * the hook reports the missing provider.
   *
   * Server rendering creates its own client from the same configuration.
   * With `jsonApiFetch` it uses the current request's session through that
   * transport; without a transport, public rendering gets a direct,
   * unauthenticated client, and a live draft session gets no client, so a
   * component fetching during rendering can never receive public content
   * masquerading as a draft (SWR fallback data then stays unused during
   * server rendering — see `jsonApiFetch`).
   */
  jsonApi?: JsonApiRuntimeConfig;
  /**
   * Fetch implementation for the client created from `jsonApi`. Framework
   * adapters whose server rendering runs inside the request (TanStack Start)
   * supply a request-aware transport here: it answers the client's
   * proxy-mapped requests in-process with the current request's draft
   * session, so the server-rendered client is the same non-null,
   * draft-aware client the browser gets, without serializing credentials.
   * The generic renderer never imports server code; the transport is the
   * adapter's. In the browser, omit it (the default `fetch` goes through the
   * proxy).
   */
  jsonApiFetch?: typeof fetch;
}

interface CanvasElementProps {
  node: CanvasComponentTreeElement;
  components: CanvasComponentRegistry;
  path: string;
  editor: boolean;
}

/**
 * Renders a structured Canvas component tree.
 *
 * HTML strings are intentionally inserted as HTML. Apps must only pass trusted
 * rendered output here.
 */
export function CanvasComponentTree({
  tree,
  components,
  context,
  jsonApi,
  jsonApiFetch,
}: CanvasComponentTreeProps) {
  const rendered = <CanvasTreeContent tree={tree} components={components} />;
  const withClient = (
    <CanvasTreeClientProvider jsonApi={jsonApi} jsonApiFetch={jsonApiFetch}>
      {rendered}
    </CanvasTreeClientProvider>
  );
  return context === undefined ? (
    withClient
  ) : (
    <CanvasContextProvider context={context}>
      {withClient}
    </CanvasContextProvider>
  );
}

/**
 * Creates the JSON:API client for the tree from the runtime configuration,
 * reusing it while the configuration and transport stay unchanged. Without
 * configuration an outer provider is inherited.
 */
function CanvasTreeClientProvider({
  jsonApi: explicitConfig,
  jsonApiFetch,
  children,
}: {
  jsonApi?: JsonApiRuntimeConfig;
  jsonApiFetch?: typeof fetch;
  children: ReactNode;
}) {
  const runtimeConfig = useJsonApiRuntimeConfig();
  const hasOuterClient = useHasJsonApiClient();
  // Precedence: the explicit prop, then the adapter-supplied runtime
  // configuration, then an outer JsonApiClientProvider.
  const jsonApi = explicitConfig ?? runtimeConfig;
  const key = jsonApi ? JSON.stringify(jsonApi) : null;
  const client = useMemo(() => {
    if (!jsonApi) {
      return null;
    }
    if (typeof window !== 'undefined') {
      // Browser: through the application's same-origin proxy, which
      // authenticates from the session cookie.
      return createJsonApiClient({
        ...jsonApi,
        credentials: 'same-origin',
        fetch: jsonApiFetch,
      });
    }
    if (jsonApiFetch) {
      // Server rendering with the adapter's request-aware transport: the
      // same proxy-mapped, draft-aware requests, answered in-process with the
      // current request's session.
      return createJsonApiClient({ ...jsonApi, fetch: jsonApiFetch });
    }
    if (jsonApi.preview) {
      // Server rendering in a live draft session without a transport: no
      // request-aware client exists here, and a public client would let
      // draft rendering silently fall back to published content.
      return null;
    }
    // Server rendering of public content: direct, unauthenticated.
    return createJsonApiClient({
      ...jsonApi,
      proxyUrl: undefined,
      resourceVersion: null,
      preview: false,
    });
    // The serialized configuration is the identity of the client.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, jsonApiFetch]);
  if (client === null || (hasOuterClient && !jsonApi)) {
    return <>{children}</>;
  }
  return (
    <JsonApiClientProvider client={client}>{children}</JsonApiClientProvider>
  );
}

function CanvasTreeContent({
  tree,
  components,
}: Pick<CanvasComponentTreeProps, 'tree' | 'components'>) {
  const editor = isCanvasComponentTreeDraft(tree);
  const previewContentRegion = editor && hasCanvasPreviewContentRegion(tree);
  const emptyRegion =
    editor && !previewContentRegion && isCanvasComponentTreeEmpty(tree);
  const content = tree ? (
    <CanvasElement
      node={tree}
      components={components}
      path="tree"
      editor={editor}
      key={getCanvasElementKey(tree, 'tree')}
    />
  ) : null;

  return editor && !previewContentRegion ? (
    <>
      <CanvasMarker position="start" type="region" id="content" />
      {emptyRegion && <CanvasEmptyRegionPlaceholder />}
      {content}
      <CanvasMarker position="end" type="region" id="content" />
    </>
  ) : (
    content
  );
}

/** Renders an empty-region drop area while editing. */
function CanvasEmptyRegionPlaceholder() {
  return (
    <div aria-hidden="true" className={CANVAS_EMPTY_REGION_PLACEHOLDER_CLASS} />
  );
}

function CanvasElement({ node, components, path, editor }: CanvasElementProps) {
  if (node.element === CANVAS_PREVIEW_CONTENT_REGION_ELEMENT) {
    const content = renderStructuralChildren(node, components, path, editor);
    if (!editor) {
      return <>{content}</>;
    }
    return (
      <>
        <CanvasMarker position="start" type="region" id="content" />
        {isCanvasComponentTreeEmpty(node) && <CanvasEmptyRegionPlaceholder />}
        {content}
        <CanvasMarker position="end" type="region" id="content" />
      </>
    );
  }

  if (node.element === 'drupal-markup') {
    return <>{renderStructuralChildren(node, components, path, editor)}</>;
  }

  const componentData = getCanvasComponentRenderData(node);
  if (!componentData) {
    return (
      <>
        {renderSlots(node, components, path, editor).flatMap(
          ({ content }) => content,
        )}
      </>
    );
  }

  const Component = findCanvasComponent(components, componentData);
  if (!Component) {
    reportMissingCanvasComponent(componentData, path);
    return null;
  }

  const renderedSlots = renderSlots(node, components, path, editor);
  const slotProps = Object.fromEntries(
    renderedSlots.map(({ name, content }) => [
      name === 'default' ? 'children' : name,
      content,
    ]),
  );
  const component = createElement(Component, {
    ...componentData.props,
    ...slotProps,
  });

  if (!editor) {
    return component;
  }
  if (!componentData.componentUuid) {
    reportMissingCanvasComponentUuid(componentData, path);
    return component;
  }
  return (
    <>
      <CanvasMarker
        position="start"
        type="component"
        id={componentData.componentUuid}
      />
      {component}
      <CanvasMarker
        position="end"
        type="component"
        id={componentData.componentUuid}
      />
    </>
  );
}

function renderStructuralChildren(
  node: CanvasComponentTreeElement,
  components: CanvasComponentRegistry,
  path: string,
  editor: boolean,
): ReactNode[] {
  return Object.values(node.slots ?? {}).flatMap((slot, slotIndex) =>
    normalizeCanvasComponentTreeSlot(slot).map((child, childIndex) => {
      const childPath = `${path}:${slotIndex}:${childIndex}`;
      return typeof child === 'string' ? (
        <CanvasMarkup html={child} key={childPath} />
      ) : (
        <CanvasElement
          node={child}
          components={components}
          path={childPath}
          editor={editor}
          key={getCanvasElementKey(child, childPath)}
        />
      );
    }),
  );
}

function renderSlots(
  node: CanvasComponentTreeElement,
  components: CanvasComponentRegistry,
  path: string,
  editor: boolean,
) {
  const componentData = getCanvasComponentRenderData(node);
  return Object.entries(node.slots ?? {}).map(([name, slot]) => {
    const empty = isCanvasComponentTreeSlotEmpty(slot);
    const children =
      editor && empty ? [] : normalizeCanvasComponentTreeSlot(slot);
    return {
      name,
      content: wrapSlot(
        children.map((child, index) => {
          const childPath = `${path}:${name}:${index}`;
          return typeof child === 'string' ? (
            <CanvasMarkup html={child} key={childPath} />
          ) : (
            <CanvasElement
              node={child}
              components={components}
              path={childPath}
              editor={editor}
              key={getCanvasElementKey(child, childPath)}
            />
          );
        }),
        editor,
        componentData?.componentUuid,
        name,
        empty,
      ),
    };
  });
}

/** Keeps a Canvas component's React identity tied to its stored UUID. */
function getCanvasElementKey(
  node: CanvasComponentTreeElement,
  fallback: string,
): string {
  const componentUuid = getCanvasComponentRenderData(node)?.componentUuid;
  return componentUuid ? `component:${componentUuid}` : fallback;
}

function wrapSlot(
  content: ReactNode[],
  editor: boolean,
  componentUuid: string | undefined,
  slotName: string,
  empty: boolean,
): ReactNode[] {
  if (!editor || !componentUuid) {
    return content;
  }
  const id = `${componentUuid}/${slotName}`;
  return [
    <CanvasMarker position="start" type="slot" id={id} key={`${id}:start`} />,
    ...(empty
      ? [<CanvasEmptySlotPlaceholder key={`${id}:empty-placeholder`} />]
      : []),
    ...content,
    <CanvasMarker position="end" type="slot" id={id} key={`${id}:end`} />,
  ];
}

/** Renders a minimum empty-slot drop area while editing. */
function CanvasEmptySlotPlaceholder() {
  return (
    <div aria-hidden="true" className={CANVAS_EMPTY_SLOT_PLACEHOLDER_CLASS} />
  );
}

/** React uses template markers because it cannot render comment nodes. */
function CanvasMarker({ position, type, id }: CanvasMarkerProps) {
  return createElement(
    'template',
    getCanvasTemplateMarkerAttributes({ position, type, id }),
  );
}

function CanvasMarkup({ html }: { html: string }) {
  return (
    <span
      style={{ display: 'contents' }}
      dangerouslySetInnerHTML={{ __html: html }}
    />
  );
}
