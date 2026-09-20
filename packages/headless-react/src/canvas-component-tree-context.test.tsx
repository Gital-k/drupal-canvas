// @vitest-environment jsdom

import {
  CanvasContextProvider,
  createJsonApiClient,
  JsonApiClientProvider,
  useJsonApiClient,
  usePageContext,
  useSiteContext,
} from 'drupal-canvas';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { CanvasComponentTree } from './canvas-component-tree';

import type { CanvasContext } from 'drupal-canvas';

const context: CanvasContext = {
  page: { pageTitle: 'From tree', breadcrumbs: [], mainEntity: null },
  site: {
    branding: { homeUrl: '/', siteName: 'Tree site', siteSlogan: '' },
    baseUrl: 'https://drupal.example',
    themeAssets: { logo: { url: '' }, favicon: { url: '', mimeType: '' } },
  },
};

const outerContext: CanvasContext = {
  page: { pageTitle: 'From outer', breadcrumbs: [], mainEntity: null },
  site: {
    ...context.site!,
    branding: { ...context.site!.branding, siteName: 'Outer site' },
  },
};

function Header() {
  const page = usePageContext();
  const site = useSiteContext();
  const client = useJsonApiClient();
  return (
    <header>
      {page?.pageTitle ?? 'no-page'}|{site?.branding.siteName ?? 'no-site'}|
      {client
        ? `${client.baseUrl}|${client.runtimeConfig.proxyUrl ?? 'direct'}`
        : 'no-client'}
    </header>
  );
}

const tree = { element: 'js-header', props: { canvasUuid: 'header' } };

describe('CanvasComponentTree context and client', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('establishes the provider from the context prop', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const html = renderToStaticMarkup(
      <CanvasComponentTree
        tree={tree}
        components={{ header: Header }}
        context={context}
      />,
    );
    expect(html).toContain('From tree|Tree site|');
    expect(
      warn.mock.calls.map(([message]) => String(message)),
    ).not.toContainEqual(expect.stringContaining('usePageContext()'));
  });

  it('inherits an outer provider when the context prop is omitted', () => {
    const html = renderToStaticMarkup(
      <CanvasContextProvider context={outerContext}>
        <CanvasComponentTree tree={tree} components={{ header: Header }} />
      </CanvasContextProvider>,
    );
    expect(html).toContain('From outer|Outer site|');
  });

  it('lets an explicit context prop win over an outer provider, null values included', () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    expect(
      renderToStaticMarkup(
        <CanvasContextProvider context={outerContext}>
          <CanvasComponentTree
            tree={tree}
            components={{ header: Header }}
            context={context}
          />
        </CanvasContextProvider>,
      ),
    ).toContain('From tree|Tree site|');
    expect(
      renderToStaticMarkup(
        <CanvasContextProvider context={outerContext}>
          <CanvasComponentTree
            tree={tree}
            components={{ header: Header }}
            context={{ page: null, site: context.site }}
          />
        </CanvasContextProvider>,
      ),
    ).toContain('no-page|Tree site|');
  });

  it('creates a client from the runtime configuration and inherits an outer client otherwise', () => {
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    const html = renderToStaticMarkup(
      <CanvasComponentTree
        tree={tree}
        components={{ header: Header }}
        context={context}
        jsonApi={{
          baseUrl: 'https://drupal.example',
          apiPrefix: 'jsonapi',
          proxyUrl: '/api/canvas/jsonapi',
          resourceVersion: 'rel:working-copy',
          preview: true,
        }}
      />,
    );
    // jsdom defines `window`, so the browser client is created: it goes
    // through the proxy.
    expect(html).toContain('https://drupal.example|/api/canvas/jsonapi');

    const outerClient = createJsonApiClient({
      baseUrl: 'https://outer.example',
    });
    expect(
      renderToStaticMarkup(
        <JsonApiClientProvider client={outerClient}>
          <CanvasComponentTree
            tree={tree}
            components={{ header: Header }}
            context={context}
          />
        </JsonApiClientProvider>,
      ),
    ).toContain('https://outer.example|direct');
  });
});
