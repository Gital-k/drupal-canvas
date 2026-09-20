import { beforeEach, describe, expect, it, vi } from 'vitest';

/** The proxy handler's answers, keyed by pathname. */
const answers = new Map<string, (request: Request) => Promise<Response>>();
const handleJsonApiProxy = vi.fn(async (request: Request) => {
  const answer = answers.get(new URL(request.url).pathname);
  if (!answer) {
    return Response.json({ error: 'endpoint_not_allowed' }, { status: 404 });
  }
  return answer(request);
});

vi.mock('./server', () => ({ handleJsonApiProxy }));
vi.mock('@tanstack/react-start/server', () => ({
  getRequest: () => new Request('https://app.example/news'),
  getCookie: () => undefined,
}));

const { serverJsonApiFetch } = await import('./server-jsonapi-fetch');

function redirect(status: number, location: string): Response {
  return new Response(null, { status, headers: { Location: location } });
}

describe('serverJsonApiFetch redirects', () => {
  beforeEach(() => {
    handleJsonApiProxy.mockClear();
    answers.clear();
  });

  it('follows proxy-internal redirects like a browser fetch and returns the final body', async () => {
    answers.set('/api/canvas/jsonapi/jsonapi/node/article/a', async () =>
      redirect(301, '/api/canvas/jsonapi/jsonapi/node/article/b?x=1'),
    );
    answers.set('/api/canvas/jsonapi/jsonapi/node/article/b', async (request) =>
      Response.json({ url: request.url, method: request.method }),
    );
    const response = await serverJsonApiFetch(
      '/api/canvas/jsonapi/jsonapi/node/article/a',
    );
    expect(response.status).toBe(200);
    expect(await response.json()).toEqual({
      url: 'https://app.example/api/canvas/jsonapi/jsonapi/node/article/b?x=1',
      method: 'GET',
    });
    expect(handleJsonApiProxy).toHaveBeenCalledTimes(2);
  });

  it('applies Fetch method rules: 303 and POST-301 become GET without body, 307 re-sends', async () => {
    const seen: Array<{ method: string; body: string; type: string | null }> =
      [];
    answers.set('/api/canvas/jsonapi/jsonapi/node/article', async (request) => {
      const status = Number(new URL(request.url).searchParams.get('to'));
      return redirect(status, '/api/canvas/jsonapi/jsonapi/node/article/final');
    });
    answers.set(
      '/api/canvas/jsonapi/jsonapi/node/article/final',
      async (request) => {
        seen.push({
          method: request.method,
          body: await request.text(),
          type: request.headers.get('content-type'),
        });
        return Response.json({ ok: true });
      },
    );
    for (const to of [303, 301, 307]) {
      await serverJsonApiFetch(
        `/api/canvas/jsonapi/jsonapi/node/article?to=${to}`,
        {
          method: 'POST',
          body: '{"data":{}}',
          headers: { 'content-type': 'application/vnd.api+json' },
        },
      );
    }
    expect(seen).toEqual([
      { method: 'GET', body: '', type: null },
      { method: 'GET', body: '', type: null },
      { method: 'POST', body: '{"data":{}}', type: 'application/vnd.api+json' },
    ]);
  });

  it('never leaves the application origin and bounds redirect loops', async () => {
    answers.set('/api/canvas/jsonapi/jsonapi/node/article/a', async () =>
      redirect(302, 'https://drupal.example/jsonapi/node/article/a'),
    );
    await expect(
      serverJsonApiFetch('/api/canvas/jsonapi/jsonapi/node/article/a'),
    ).rejects.toThrow(/outside the application/);

    answers.set('/api/canvas/jsonapi/jsonapi/node/article/loop', async () =>
      redirect(302, '/api/canvas/jsonapi/jsonapi/node/article/loop'),
    );
    await expect(
      serverJsonApiFetch('/api/canvas/jsonapi/jsonapi/node/article/loop'),
    ).rejects.toThrow(/Too many redirects/);
  });

  it('honors explicit redirect modes and re-sends Request bodies', async () => {
    answers.set('/api/canvas/jsonapi/jsonapi/node/article', async () =>
      redirect(307, '/api/canvas/jsonapi/jsonapi/node/article/final'),
    );
    const seen: Array<{ method: string; body: string }> = [];
    answers.set(
      '/api/canvas/jsonapi/jsonapi/node/article/final',
      async (request) => {
        seen.push({ method: request.method, body: await request.text() });
        return Response.json({ ok: true });
      },
    );
    // `manual`: the redirect is returned as is; `error`: it fails.
    const manual = await serverJsonApiFetch(
      '/api/canvas/jsonapi/jsonapi/node/article',
      { redirect: 'manual' },
    );
    expect(manual.status).toBe(307);
    expect(manual.headers.get('location')).toBe(
      '/api/canvas/jsonapi/jsonapi/node/article/final',
    );
    await expect(
      serverJsonApiFetch('/api/canvas/jsonapi/jsonapi/node/article', {
        redirect: 'error',
      }),
    ).rejects.toThrow(/does not allow redirects/);
    expect(seen).toEqual([]);

    // A Request object's body is replayed on 307, like a string init body.
    const response = await serverJsonApiFetch(
      new Request(
        'https://app.example/api/canvas/jsonapi/jsonapi/node/article',
        {
          method: 'POST',
          body: '{"data":{"id":"r"}}',
          headers: { 'content-type': 'application/vnd.api+json' },
        },
      ),
    );
    expect(response.status).toBe(200);
    expect(seen).toEqual([{ method: 'POST', body: '{"data":{"id":"r"}}' }]);

    // A streamed init body cannot be replayed: the redirect fails, as in Fetch.
    await expect(
      serverJsonApiFetch('/api/canvas/jsonapi/jsonapi/node/article', {
        method: 'POST',
        body: new ReadableStream({
          start(controller) {
            controller.enqueue(new TextEncoder().encode('{}'));
            controller.close();
          },
        }),
        // @ts-expect-error duplex is required for stream bodies in Node.
        duplex: 'half',
      }),
    ).rejects.toThrow(/cannot be re-sent/);
  });

  it('returns 502 refusals from the handler unchanged', async () => {
    answers.set('/api/canvas/jsonapi/jsonapi/node/article/a', async () =>
      Response.json({ error: 'redirect_not_allowed' }, { status: 502 }),
    );
    const response = await serverJsonApiFetch(
      '/api/canvas/jsonapi/jsonapi/node/article/a',
    );
    expect(response.status).toBe(502);
  });
});
