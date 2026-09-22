import { afterEach, describe, expect, it, vi } from 'vitest';
import { DRAFT_DATA_COOKIE_NAME } from '@drupal-canvas/headless';

import {
  ANGULAR_DRAFT_FLAG_COOKIE,
  createCanvasHandler,
  createCanvasRequest,
} from '../server';

import type { DraftData } from '@drupal-canvas/headless';

const baseUrl = 'https://cms.internal.example';
const editorOrigin = 'https://editor.example';
const origin = 'https://frontend.example';
const manifest = { version: 1 as const, components: [], warnings: [] };
const claims = {
  path: '/hello',
  sub: '42',
  resourceVersion: 'rel:working-copy',
  renewUrl: `${editorOrigin}/renew`,
  previewContext: {
    language: 'fr',
    viewMode: 'teaser',
    pageVariant: 'alternative',
  },
};
const assertion = (sub = '42') =>
  `e30.${Buffer.from(JSON.stringify({ ...claims, sub })).toString('base64url')}.signature`;
const draft = (overrides: Partial<DraftData> = {}): DraftData => ({
  ...claims,
  accessToken: 'private-token',
  tokenType: 'Bearer',
  tokenExpiresAt: Date.now() + 600_000,
  codeVerifier: 'private-verifier',
  ...overrides,
});
const cookie = (data = draft()) =>
  `${ANGULAR_DRAFT_FLAG_COOKIE}=1; ${DRAFT_DATA_COOKIE_NAME}=${encodeURIComponent(JSON.stringify(data))}`;
const page = {
  content: { element: 'div' },
  head: { title: 'Hello' },
  route: { managedByCanvas: true },
};
function options(
  fetchImpl = vi.fn<typeof fetch>().mockResolvedValue(Response.json(page)),
) {
  return { config: { baseUrl }, manifest, fetchImpl };
}
afterEach(() => vi.unstubAllGlobals());

describe('request-scoped Canvas adapter', () => {
  it('exchanges through shared flows, preserves CHIPS attributes and excludes credentials from session data', async () => {
    const fetchImpl = vi.fn<typeof fetch>().mockResolvedValue(
      Response.json({
        access_token: 'secret-new',
        token_type: 'Bearer',
        expires_in: 900,
      }),
    );
    const ctx = createCanvasRequest(
      new Request(`${origin}/api/draft?assertion=${assertion()}`),
      options(fetchImpl),
    );
    const response = (await ctx.handle())!;
    expect(response.status).toBe(307);
    expect(response.headers.get('location')).toBe('/hello');
    expect(response.headers.getSetCookie()).toHaveLength(2);
    for (const value of response.headers.getSetCookie())
      for (const attribute of [
        'Path=/',
        'HttpOnly',
        'Secure',
        'SameSite=None',
        'Partitioned',
      ])
        expect(value).toContain(attribute);
    const serialized = JSON.stringify(await ctx.session());
    expect(serialized).not.toMatch(/secret-new|codeVerifier|accessToken/);
    expect(serialized).not.toContain(baseUrl);
    expect(response.headers.get('content-security-policy')).toBe(
      `frame-ancestors 'self' ${editorOrigin}`,
    );
    expect(fetchImpl.mock.calls[0][1]?.body?.toString()).toContain(
      'code_challenge',
    );
  });
  it('rotates PKCE during renewal and pins editor identity', async () => {
    const fetchImpl = vi.fn<typeof fetch>().mockResolvedValue(
      Response.json({
        access_token: 'new',
        token_type: 'Bearer',
        expires_in: 900,
      }),
    );
    for (const [sub, status] of [
      ['42', 200],
      ['other', 409],
    ] as const) {
      const ctx = createCanvasRequest(
        new Request(`${origin}/api/draft/renew`, {
          method: 'POST',
          headers: { cookie: cookie() },
          body: JSON.stringify({ assertion: assertion(sub) }),
        }),
        options(fetchImpl),
      );
      const response = (await ctx.handle())!;
      expect(response.status).toBe(status);
      if (status === 200) {
        expect(await response.json()).toEqual({
          tokenExpiresAt: expect.any(Number),
        });
        expect(fetchImpl.mock.calls[0][1]?.body?.toString()).toContain(
          'code_verifier=private-verifier',
        );
        expect(response.headers.getSetCookie()).toHaveLength(2);
      }
    }
    expect(fetchImpl).toHaveBeenCalledTimes(1);
  });
  it('requires a session for renewal and POST plus same-origin for exit', async () => {
    const renew = await createCanvasRequest(
      new Request(`${origin}/api/draft/renew`, {
        method: 'POST',
        body: JSON.stringify({ assertion: assertion() }),
      }),
      options(),
    ).handle();
    expect(renew?.status).toBe(400);
    for (const [method, requestOrigin, status] of [
      ['GET', origin, 405],
      ['POST', 'https://evil.example', 403],
      ['POST', origin, 303],
    ]) {
      const response = (await createCanvasRequest(
        new Request(`${origin}/api/disable-draft`, {
          method,
          headers: { origin: requestOrigin, cookie: cookie() },
        }),
        options(),
      ).handle())!;
      expect(response.status).toBe(status);
      if (status === 303) {
        expect(response.headers.get('location')).toBe('/');
        expect(response.headers.getSetCookie()).toHaveLength(2);
        for (const value of response.headers.getSetCookie())
          expect(value).toMatch(
            /HttpOnly; Secure; SameSite=None; Partitioned; Expires=Thu, 01 Jan 1970/,
          );
      }
    }
  });
  it('keeps request credentials/context isolated; expiry falls back to anonymous', async () => {
    const fetchImpl = vi.fn<typeof fetch>(async () => Response.json(page));
    const a = createCanvasRequest(
      new Request(origin, { headers: { cookie: cookie() } }),
      options(fetchImpl),
    );
    const b = createCanvasRequest(
      new Request(origin, {
        headers: { cookie: cookie(draft({ tokenExpiresAt: 0 })) },
      }),
      options(fetchImpl),
    );
    const [live, expired] = await Promise.all([
      a.loadPage('/a'),
      b.loadPage('/b'),
    ]);
    expect(live.page).toMatchObject({ content: { canvasDraftMode: true } });
    expect(expired.session.initialExpired).toBe(true);
    expect(expired.page).not.toHaveProperty('content.canvasDraftMode');
    const calls = fetchImpl.mock.calls;
    expect(calls[0][1]?.headers).toMatchObject({
      Authorization: 'Bearer private-token',
    });
    expect(String(calls[0][0])).toContain('pageVariant=alternative');
    expect(calls[1][1]?.headers).not.toHaveProperty('Authorization');
    expect(String(calls[1][0])).not.toContain('language=');
    expect(JSON.stringify([live, expired])).not.toMatch(
      /private-token|private-verifier/,
    );
  });
  it('rejects non-local paths and preserves entity and thumbnail context', async () => {
    const fetchImpl = vi.fn<typeof fetch>(async () =>
      Response.json({ ...page, managedByCanvas: true }),
    );
    const ctx = createCanvasRequest(
      new Request(origin, { headers: { cookie: cookie() } }),
      options(fetchImpl),
    );
    for (const path of [
      '//evil.example',
      'https://evil.example',
      '/\\evil',
      '/ok#bad',
    ])
      await expect(ctx.loadPage(path)).rejects.toThrow('site-relative');
    await ctx.server.fetchEntity({ type: 'node', id: '9', viewMode: 'teaser' });
    expect(String(fetchImpl.mock.calls[0][0])).toContain(
      '/entity?type=node&id=9&viewMode=teaser',
    );
    await ctx.loadPage('/api/canvas/component-preview?componentId=js.frame');
    expect(String(fetchImpl.mock.calls[1][0])).toContain(
      'componentId=js.frame',
    );
    expect(String(fetchImpl.mock.calls[1][0])).not.toContain('pageVariant=');
  });
  it('merges CSP without weakening app policies and marks all output private/no-store', async () => {
    const ctx = createCanvasRequest(new Request(origin), options());
    for (const policy of [
      "default-src 'none'",
      "default-src 'self'; frame-ancestors 'none'",
    ]) {
      const response = await ctx.finalize(
        new Response('body', {
          headers: { 'Content-Security-Policy': policy },
        }),
      );
      expect(response.headers.get('content-security-policy')).toContain(policy);
      expect(response.headers.get('cache-control')).toBe('private, no-store');
      if (policy.includes('frame-ancestors'))
        expect(response.headers.get('content-security-policy')).toBe(policy);
    }
  });
  it('handles HTTP redirects and 404 before/around SSR and passes only explicit context', async () => {
    const render = vi.fn(async () => new Response('SSR'));
    const redirect = createCanvasHandler(
      options(
        vi.fn<typeof fetch>().mockResolvedValue(
          Response.json({
            redirect: { statusCode: 301, url: '/new', external: false },
          }),
        ),
      ),
    );
    expect((await redirect(new Request(origin + '/old'), render)).status).toBe(
      301,
    );
    expect(render).not.toHaveBeenCalled();
    const missing = createCanvasHandler(
      options(
        vi
          .fn<typeof fetch>()
          .mockResolvedValue(new Response(null, { status: 404 })),
      ),
    );
    const result = await missing(new Request(origin + '/missing'), render);
    expect(result.status).toBe(404);
    expect(await result.text()).toBe('SSR');
  });
  it('serves embedded production metadata without source files using shared redemption/CORS', async () => {
    const fetchImpl = vi.fn<typeof fetch>().mockResolvedValue(
      Response.json({
        access_token: 'discarded',
        token_type: 'Bearer',
        expires_in: 60,
      }),
    );
    vi.stubGlobal('fetch', fetchImpl);
    const handler = createCanvasRequest(
      new Request(origin + '/api/canvas/components', {
        headers: {
          authorization: `Bearer ${assertion()}`,
          origin: editorOrigin,
        },
      }),
      options(),
    );
    const response = (await handler.handle())!;
    expect(response.status).toBe(200);
    expect(await response.json()).toEqual(manifest);
    expect(response.headers.get('access-control-allow-origin')).toBe(
      editorOrigin,
    );
    expect(
      (
        await createCanvasRequest(
          new Request(origin + '/api/canvas/components'),
          options(),
        ).handle()
      )?.status,
    ).toBe(401);
  });
});
