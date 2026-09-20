import { beforeEach, describe, expect, it, vi } from 'vitest';

const handleJsonApiProxy = vi.fn(async (request: Request) => {
  return new Response(
    JSON.stringify({
      url: request.url,
      method: request.method,
      site: request.headers.get('sec-fetch-site'),
      accept: request.headers.get('accept'),
      cookie: request.headers.get('cookie'),
      body: request.method === 'GET' ? null : await request.text(),
    }),
    { status: 200, headers: { 'content-type': 'application/json' } },
  );
});

vi.mock('./server', () => ({ handleJsonApiProxy }));
vi.mock('@tanstack/react-start/server', () => ({
  getRequest: () =>
    new Request('https://app.example/news/latest?page=2', {
      headers: {
        cookie: 'canvas_headless_draft_data=secret',
        'sec-fetch-site': 'cross-site',
      },
    }),
  getCookie: () => undefined,
}));

const { serverJsonApiFetch } = await import('./server-jsonapi-fetch');
const { getServerJsonApiFetch } = await import('./jsonapi-fetch');

describe('serverJsonApiFetch', () => {
  beforeEach(() => {
    handleJsonApiProxy.mockClear();
  });

  it('answers proxy-mapped requests in-process against the current request', async () => {
    const response = await serverJsonApiFetch(
      '/api/canvas/jsonapi/jsonapi/node/article?resourceVersion=rel:working-copy',
      { headers: { accept: 'application/vnd.api+json' } },
    );
    expect(handleJsonApiProxy).toHaveBeenCalledTimes(1);
    expect(await response.json()).toEqual({
      url: 'https://app.example/api/canvas/jsonapi/jsonapi/node/article?resourceVersion=rel:working-copy',
      method: 'GET',
      site: 'same-origin',
      accept: 'application/vnd.api+json',
      // The session is the handler's to read; the page request's cookies are
      // not copied onto the proxied request.
      cookie: null,
      body: null,
    });
  });

  it('keeps method and body, and accepts Request inputs', async () => {
    const fromInit = await serverJsonApiFetch(
      '/api/canvas/jsonapi/node/article',
      {
        method: 'POST',
        body: '{"data":{}}',
        headers: { 'content-type': 'application/vnd.api+json' },
      },
    );
    expect(await fromInit.json()).toMatchObject({
      method: 'POST',
      body: '{"data":{}}',
      site: 'same-origin',
    });

    const fromRequest = await serverJsonApiFetch(
      new Request('https://app.example/api/canvas/jsonapi/node/article/a', {
        method: 'PATCH',
        body: '{"data":{"id":"a"}}',
      }),
    );
    expect(await fromRequest.json()).toMatchObject({
      url: 'https://app.example/api/canvas/jsonapi/node/article/a',
      method: 'PATCH',
      body: '{"data":{"id":"a"}}',
      site: 'same-origin',
    });
  });

  it('is what the isomorphic resolver hands the renderer on the server', async () => {
    // Without the Start compiler (as in this test) the runtime stub keeps
    // the server implementation; the compiler removes it from browser
    // bundles (verified by the SSR/client build check in the MR notes).
    const transport = getServerJsonApiFetch();
    expect(transport).toBeTypeOf('function');
    const response = await transport!('/api/canvas/jsonapi/node/article');
    expect(await response.json()).toMatchObject({
      url: 'https://app.example/api/canvas/jsonapi/node/article',
      site: 'same-origin',
    });
  });
});
