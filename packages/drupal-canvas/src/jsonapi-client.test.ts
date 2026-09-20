// @cspell:ignore Aworking Alatest
import { afterEach, describe, expect, it, vi } from 'vitest';

import { createJsonApiClient, isDraftSessionError } from './jsonapi-client';
import {
  JSONAPI_PROXY_SESSION_EXPIRED,
  JSONAPI_PROXY_SESSION_HEADER,
} from './jsonapi-proxy';

import type { Cache } from '@drupal-api-client/api-client';

type FetchCall = { url: string; init?: RequestInit };

function jsonResponse(body: unknown, init: ResponseInit = {}): Response {
  return new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/vnd.api+json' },
    ...init,
  });
}

function fakeFetch(
  handler: (url: string, init?: RequestInit) => Response | Promise<Response>,
): { fetch: typeof fetch; calls: FetchCall[] } {
  const calls: FetchCall[] = [];
  const fetchImpl = (async (input: RequestInfo | URL, init?: RequestInit) => {
    const url =
      typeof input === 'string'
        ? input
        : input instanceof URL
          ? input.href
          : input.url;
    calls.push({ url, init });
    return handler(url, init);
  }) as typeof fetch;
  return { fetch: fetchImpl, calls };
}

function articleCollection() {
  return {
    jsonapi: { version: '1.0' },
    data: [
      { type: 'node--article', id: 'a', attributes: { title: 'Published A' } },
      { type: 'node--article', id: 'b', attributes: { title: 'Published B' } },
    ],
    links: {
      next: {
        href: 'https://drupal.example/jsonapi/node/article?page[offset]=2',
      },
    },
  };
}

describe('createJsonApiClient', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('configures the upstream client from runtime configuration', () => {
    const client = createJsonApiClient({
      baseUrl: 'https://drupal.example/',
      apiPrefix: '/api/',
      routerPrefix: 'router/translate-path',
      resourceVersion: 'rel:working-copy',
      preview: true,
    });
    expect(client.baseUrl).toBe('https://drupal.example');
    expect(client.apiPrefix).toBe('api');
    expect(client.router.apiPrefix).toBe('router/translate-path');
    expect(client.resourceVersion).toBe('rel:working-copy');
    expect(client.runtimeConfig).toEqual({
      baseUrl: 'https://drupal.example',
      apiPrefix: 'api',
      routerPrefix: 'router/translate-path',
      resourceVersion: 'rel:working-copy',
      preview: true,
    });
    expect(client.serializer).toBeDefined();
    expect(
      createJsonApiClient({
        baseUrl: 'https://drupal.example',
        serializer: null,
      }).serializer,
    ).toBeUndefined();
  });

  it('reads resources at the resource version unless the caller pins one', async () => {
    const { fetch, calls } = fakeFetch(() =>
      jsonResponse({
        data: { type: 'node--article', id: 'a', attributes: {} },
      }),
    );
    const client = createJsonApiClient({
      baseUrl: 'https://drupal.example',
      resourceVersion: 'rel:working-copy',
      serializer: null,
      fetch,
    });
    await client.getResource('node--article', 'a');
    await client.getResource('node--article', 'a', {
      queryString: 'resourceVersion=id:7',
    });
    await client.getResource('node--article', 'a', {
      queryString: 'include=field_tags',
    });
    await client.getResource('node--article', 'a', { rawResponse: true });
    expect(calls.map((call) => call.url)).toEqual([
      'https://drupal.example/jsonapi/node/article/a?resourceVersion=rel%3Aworking-copy',
      'https://drupal.example/jsonapi/node/article/a?resourceVersion=id:7',
      'https://drupal.example/jsonapi/node/article/a?include=field_tags&resourceVersion=rel%3Aworking-copy',
      'https://drupal.example/jsonapi/node/article/a',
    ]);
  });

  it('keeps default revisions without a resource version', async () => {
    const { fetch, calls } = fakeFetch(() => jsonResponse(articleCollection()));
    const client = createJsonApiClient({
      baseUrl: 'https://drupal.example',
      serializer: null,
      fetch,
    });
    const document = (await client.getCollection('node--article')) as {
      data: Array<{ attributes: { title: string } }>;
    };
    expect(calls).toHaveLength(1);
    expect(document.data[0].attributes.title).toBe('Published A');
  });

  it('hydrates collection items with their working copies before serializing', async () => {
    const { fetch, calls } = fakeFetch((url) => {
      if (url.includes('/node/article/a?')) {
        return jsonResponse({
          data: {
            type: 'node--article',
            id: 'a',
            attributes: { title: 'Draft A' },
          },
        });
      }
      if (url.includes('/node/article/b?')) {
        return jsonResponse({ errors: [{ status: '403' }] }, { status: 403 });
      }
      return jsonResponse(articleCollection());
    });
    const deserialize = vi.fn((document: Record<string, unknown>) => ({
      deserialized: true,
      items: (document.data as Array<{ attributes: { title: string } }>).map(
        (item) => item.attributes.title,
      ),
    }));
    const client = createJsonApiClient({
      baseUrl: 'https://drupal.example',
      resourceVersion: 'rel:working-copy',
      serializer: { deserialize },
      fetch,
    });
    const result = await client.getCollection('node--article', {
      queryString: 'include=field_tags',
    });
    expect(calls[0].url).toBe(
      'https://drupal.example/jsonapi/node/article?include=field_tags',
    );
    expect(
      calls
        .slice(1)
        .map((call) => call.url)
        .sort(),
    ).toEqual([
      'https://drupal.example/jsonapi/node/article/a?include=field_tags&resourceVersion=rel%3Aworking-copy',
      'https://drupal.example/jsonapi/node/article/b?include=field_tags&resourceVersion=rel%3Aworking-copy',
    ]);
    expect(deserialize).toHaveBeenCalledTimes(1);
    expect(result).toEqual({
      deserialized: true,
      items: ['Draft A', 'Published B'],
    });
  });

  it('returns raw collection responses without hydration', async () => {
    const { fetch, calls } = fakeFetch(() => jsonResponse(articleCollection()));
    const client = createJsonApiClient({
      baseUrl: 'https://drupal.example',
      resourceVersion: 'rel:working-copy',
      serializer: null,
      fetch,
    });
    const raw = (await client.getCollection('node--article', {
      rawResponse: true,
    })) as { response: Response; json: { data: unknown[] } };
    expect(calls).toHaveLength(1);
    expect(raw.response.status).toBe(200);
    expect(raw.json.data).toHaveLength(2);
  });

  it('propagates draft-session errors from item fetches', async () => {
    const { fetch } = fakeFetch((url) => {
      if (url.includes('/node/article/a?')) {
        return jsonResponse(
          { error: 'draft_session_expired', message: 'Session expired.' },
          {
            status: 401,
            headers: {
              [JSONAPI_PROXY_SESSION_HEADER]: JSONAPI_PROXY_SESSION_EXPIRED,
            },
          },
        );
      }
      return jsonResponse(articleCollection());
    });
    const client = createJsonApiClient({
      baseUrl: 'https://drupal.example',
      proxyUrl: '/api/canvas/jsonapi',
      resourceVersion: 'rel:working-copy',
      serializer: null,
      fetch,
    });
    const failure = await client.getCollection('node--article').then(
      () => null,
      (error: unknown) => error,
    );
    expect(isDraftSessionError(failure)).toBe(true);
    expect(failure).toMatchObject({ message: 'Session expired.', status: 401 });
  });

  it('routes browser requests through the proxy, including absolute links', async () => {
    const { fetch, calls } = fakeFetch(() => jsonResponse(articleCollection()));
    const client = createJsonApiClient({
      baseUrl: 'https://drupal.example',
      proxyUrl: '/api/canvas/jsonapi',
      serializer: null,
      credentials: 'same-origin',
      fetch,
    });
    await client.getCollection('node--article', {
      queryString: 'page[limit]=2',
    });
    await client.fetch(
      'https://drupal.example/jsonapi/node/article?page[offset]=2',
    );
    await client.getResourceByPath('/about').catch(() => undefined);
    expect(calls.map((call) => call.url)).toEqual([
      '/api/canvas/jsonapi/jsonapi/node/article?page[limit]=2',
      '/api/canvas/jsonapi/jsonapi/node/article?page[offset]=2',
      '/api/canvas/jsonapi/router/translate-path?path=/about',
    ]);
    expect(calls[0].init?.credentials).toBe('same-origin');
  });

  it('never lets a backend URL outside the boundary bypass the proxy', async () => {
    const { fetch, calls } = fakeFetch(() => jsonResponse({}));
    const client = createJsonApiClient({
      baseUrl: 'https://drupal.example',
      proxyUrl: '/api/canvas/jsonapi',
      fetch,
    });
    const result = await client.fetch('https://drupal.example/oauth/token');
    expect(result.error?.message).toContain('not a supported');
    expect(calls).toHaveLength(0);
  });

  it('keeps the applicable read options for working-copy item reads and merges included resources', async () => {
    const { fetch, calls } = fakeFetch((url) => {
      if (url.includes('/node/article/a?')) {
        return jsonResponse({
          data: {
            type: 'node--article',
            id: 'a',
            attributes: { title: 'Draft A' },
            relationships: {
              field_tags: { data: [{ type: 'taxonomy_term--tags', id: 't1' }] },
            },
          },
          included: [
            {
              type: 'taxonomy_term--tags',
              id: 't1',
              attributes: { name: 'Draft tag' },
            },
            {
              type: 'taxonomy_term--tags',
              id: 't2',
              attributes: { name: 'New tag' },
            },
          ],
        });
      }
      if (url.includes('/node/article/b?')) {
        return jsonResponse({ errors: [{ status: '404' }] }, { status: 404 });
      }
      return jsonResponse({
        ...articleCollection(),
        included: [
          {
            type: 'taxonomy_term--tags',
            id: 't1',
            attributes: { name: 'Published tag' },
          },
          {
            type: 'taxonomy_term--tags',
            id: 't0',
            attributes: { name: 'Other tag' },
          },
        ],
      });
    });
    const client = createJsonApiClient({
      baseUrl: 'https://drupal.example',
      resourceVersion: 'rel:working-copy',
      serializer: null,
      fetch,
    });
    const document = (await client.getCollection('node--article', {
      locale: 'de',
      queryString:
        'include=field_tags&fields[node--article]=title,field_tags&fields[taxonomy_term--tags]=name&filter[status]=1&page[limit]=5&sort=-created&resourceVersion=rel%3Alatest-version',
    })) as {
      data: Array<{ attributes: { title: string } }>;
      included: Array<{ id: string; attributes: { name: string } }>;
    };
    expect(calls[0].url).toBe(
      'https://drupal.example/de/jsonapi/node/article?include=field_tags&fields[node--article]=title,field_tags&fields[taxonomy_term--tags]=name&filter[status]=1&page[limit]=5&sort=-created&resourceVersion=rel%3Alatest-version',
    );
    const itemUrls = calls
      .slice(1)
      .map((call) => call.url)
      .sort();
    expect(itemUrls).toEqual([
      'https://drupal.example/de/jsonapi/node/article/a?include=field_tags&fields%5Bnode--article%5D=title%2Cfield_tags&fields%5Btaxonomy_term--tags%5D=name&resourceVersion=rel%3Alatest-version',
      'https://drupal.example/de/jsonapi/node/article/b?include=field_tags&fields%5Bnode--article%5D=title%2Cfield_tags&fields%5Btaxonomy_term--tags%5D=name&resourceVersion=rel%3Alatest-version',
    ]);
    expect(document.data.map((item) => item.attributes.title)).toEqual([
      'Draft A',
      'Published B',
    ]);
    // Working-copy includes replace the collection's resources of the same
    // identity; the rest of the collection's includes are kept.
    expect(
      document.included.map((resource) => [
        resource.id,
        resource.attributes.name,
      ]),
    ).toEqual([
      ['t1', 'Draft tag'],
      ['t0', 'Other tag'],
      ['t2', 'New tag'],
    ]);
  });

  it('surfaces a rejected session on direct authenticated requests and keeps ordinary item failures', async () => {
    const unauthorized = (url: string) =>
      url.includes('/node/article/a?')
        ? jsonResponse({ errors: [{ status: '401' }] }, { status: 401 })
        : jsonResponse(articleCollection());
    const authenticated = createJsonApiClient({
      baseUrl: 'https://drupal.example',
      resourceVersion: 'rel:working-copy',
      authentication: {
        type: 'Custom',
        credentials: { value: 'Bearer expired' },
      },
      serializer: null,
      fetch: fakeFetch(unauthorized).fetch,
    });
    await expect(
      authenticated.getCollection('node--article'),
    ).rejects.toSatisfy(isDraftSessionError);
    await expect(
      authenticated.getResource('node--article', 'a'),
    ).rejects.toSatisfy(isDraftSessionError);

    // 403 is a resource-level denial: the published item stays.
    const forbidden = createJsonApiClient({
      baseUrl: 'https://drupal.example',
      resourceVersion: 'rel:working-copy',
      authentication: {
        type: 'Custom',
        credentials: { value: 'Bearer valid' },
      },
      serializer: null,
      fetch: fakeFetch((url) =>
        url.includes('/node/article/a?')
          ? jsonResponse({ errors: [{ status: '403' }] }, { status: 403 })
          : jsonResponse(articleCollection()),
      ).fetch,
    });
    const document = (await forbidden.getCollection('node--article')) as {
      data: Array<{ attributes: { title: string } }>;
    };
    expect(document.data[0].attributes.title).toBe('Published A');

    // Unauthenticated clients see 401 as an ordinary failure.
    const anonymous = createJsonApiClient({
      baseUrl: 'https://drupal.example',
      resourceVersion: 'rel:working-copy',
      serializer: null,
      fetch: fakeFetch(unauthorized).fetch,
    });
    const anonymousDocument = (await anonymous.getCollection(
      'node--article',
    )) as { data: Array<{ attributes: { title: string } }> };
    expect(anonymousDocument.data[0].attributes.title).toBe('Published A');
  });

  it('does not cache authenticated responses without an explicit session scope', async () => {
    const store = new Map<string, unknown>();
    const cache: Cache = {
      get: async <T>(key: string) => store.get(key) as T,
      set: async (key, value) => {
        store.set(key, value);
      },
    };
    const makeClient = (token: string, cacheScope?: string) =>
      createJsonApiClient({
        baseUrl: 'https://drupal.example',
        authentication: {
          type: 'Custom',
          credentials: { value: `Bearer ${token}` },
        },
        cache,
        ...(cacheScope && { cacheScope }),
        serializer: null,
        fetch: fakeFetch(() =>
          jsonResponse({
            data: {
              type: 'node--article',
              id: 'a',
              attributes: { title: token },
            },
          }),
        ).fetch,
      });
    const a = makeClient('session-a');
    const b = makeClient('session-b');
    await a.getResource('node--article', 'a');
    const seenByB = (await b.getResource('node--article', 'a')) as {
      data: { attributes: { title: string } };
    };
    expect(seenByB.data.attributes.title).toBe('session-b');
    expect(store.size).toBe(0);

    // With a session-unique scope, caching works and stays separated.
    const scopedA = makeClient('session-a', 'session:a');
    const scopedB = makeClient('session-b', 'session:b');
    await scopedA.getResource('node--article', 'a');
    const scopedSeenByB = (await scopedB.getResource('node--article', 'a')) as {
      data: { attributes: { title: string } };
    };
    expect(scopedSeenByB.data.attributes.title).toBe('session-b');
    expect([...store.keys()].sort()).toEqual([
      expect.stringContaining('default:session:a:'),
      expect.stringContaining('default:session:b:'),
    ]);
  });

  it('builds JSON:API URLs on a separate JSON:API base and keeps the router on the backend', async () => {
    const { fetch, calls } = fakeFetch((url) =>
      url.includes('translate-path')
        ? jsonResponse({
            resolved: 'https://drupal.example/about',
            isHomePath: false,
            entity: {
              canonical: 'https://drupal.example/about',
              type: 'node',
              bundle: 'page',
              id: '1',
              uuid: 'u1',
            },
            label: 'About',
            jsonapi: {
              individual: 'https://api.example/drupal/api/node/page/u1',
              resourceName: 'node--page',
              basePath: '/drupal/api',
              entryPoint: 'https://api.example/drupal/api',
            },
            meta: { deprecated: {} },
          })
        : jsonResponse({
            data: { type: 'node--page', id: 'u1', attributes: {} },
          }),
    );
    const client = createJsonApiClient({
      baseUrl: 'https://drupal.example',
      apiUrl: 'https://api.example/drupal/api/',
      serializer: null,
      fetch,
    });
    expect(client.runtimeConfig).toMatchObject({
      baseUrl: 'https://drupal.example',
      apiPrefix: 'drupal/api',
      apiUrl: 'https://api.example/drupal/api',
    });
    await client.getResource('node--page', 'u1', { locale: 'de' });
    await client.getResourceByPath('/about');
    expect(calls.map((call) => call.url)).toEqual([
      'https://api.example/de/drupal/api/node/page/u1',
      'https://drupal.example/router/translate-path?path=/about',
      'https://api.example/drupal/api/node/page/u1',
    ]);

    // Through the proxy, both endpoints map to the application.
    const proxied = fakeFetch(() =>
      jsonResponse({ data: { type: 'node--page', id: 'u1', attributes: {} } }),
    );
    const browser = createJsonApiClient({
      baseUrl: 'https://drupal.example',
      apiUrl: 'https://api.example/drupal/api',
      proxyUrl: '/api/canvas/jsonapi',
      serializer: null,
      fetch: proxied.fetch,
    });
    await browser.getResource('node--page', 'u1');
    await browser.fetch('https://drupal.example/router/translate-path?path=/x');
    expect(proxied.calls.map((call) => call.url)).toEqual([
      '/api/canvas/jsonapi/drupal/api/node/page/u1',
      '/api/canvas/jsonapi/router/translate-path?path=/x',
    ]);
  });

  it('separates cache entries by resource version and scope', async () => {
    const store = new Map<string, unknown>();
    const cache: Cache = {
      get: async <T>(key: string) => store.get(key) as T,
      set: async (key: string, value: unknown) => {
        store.set(key, value);
      },
    };
    const { fetch } = fakeFetch(() => jsonResponse(articleCollection()));
    const publicClient = createJsonApiClient({
      baseUrl: 'https://drupal.example',
      serializer: null,
      cache,
      fetch,
    });
    const draftClient = createJsonApiClient({
      baseUrl: 'https://drupal.example',
      resourceVersion: 'rel:working-copy',
      cacheScope: 'session:42',
      serializer: null,
      cache,
      fetch,
    });
    await publicClient.getCollection('node--article');
    await draftClient.getCollection('node--article');
    const keys = [...store.keys()];
    expect(keys).toHaveLength(2);
    expect(keys.some((key) => key.startsWith('default:public:'))).toBe(true);
    expect(
      keys.some((key) => key.startsWith('rel:working-copy:session:42:')),
    ).toBe(true);
  });
});
