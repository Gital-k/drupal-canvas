import { getRequest } from '@tanstack/react-start/server';

import { handleJsonApiProxy } from './server';

/**
 * The JSON:API transport for server rendering: answers the shared client's
 * proxy-mapped requests in-process, through the same proxy handler the
 * browser reaches over HTTP, with the current request's draft session.
 *
 * The client created from `getJsonApiRuntimeConfig()` addresses the
 * application's proxy (`/api/canvas/jsonapi/...`), so this resolves such a
 * URL against the request being rendered and hands the proxy handler a
 * request for it. The handler reads the session through the request-scoped
 * helpers of @tanstack/react-start/server, keeps the backend boundary and
 * never downgrades an expired draft session to public content — exactly as
 * for browser requests. No credential is copied into the request: the page
 * request's cookies stay where they are, and only the handler sees the
 * session token. Redirects are followed as a browser's fetch would follow
 * them, within the application's proxy only.
 *
 * Only callable while a request is being handled (server rendering runs
 * inside TanStack Start's request context). Server-only: this module reaches
 * the session helpers, so the isomorphic `getServerJsonApiFetch()` loads it
 * on the server alone and the Start compiler keeps it out of browser bundles.
 */
/** Fetch follows at most this many redirects. */
const MAX_REDIRECTS = 20;

/** Statuses whose Location header Fetch follows. */
const REDIRECT_STATUSES = new Set([301, 302, 303, 307, 308]);

export const serverJsonApiFetch: typeof fetch = async (input, init) => {
  const pageUrl = getRequest().url;
  const url = new URL(toUrlString(input), pageUrl);
  // Re-target the request at the proxy URL, keeping method, headers, body
  // and signal.
  const request =
    input instanceof Request
      ? new Request(url, init ? new Request(input, init) : input)
      : new Request(url, init);
  // The request is issued by the application's own server-rendering pass,
  // which is what this provenance signal states. The page request's own
  // provenance (for example `cross-site` while Drupal's editor embeds the
  // preview) describes the visitor's navigation, not this request, and is
  // deliberately not forwarded.
  request.headers.set('sec-fetch-site', 'same-origin');
  // A string or buffer body can be re-sent on a redirect; a stream cannot.
  const replayableBody =
    init?.body !== undefined &&
    init.body !== null &&
    !(init.body instanceof ReadableStream)
      ? init.body
      : null;

  // Follow redirects the way a browser's fetch does (`redirect: 'follow'`):
  // the proxy handler only ever emits Locations inside the boundary,
  // rewritten to the proxy path, and refuses the rest with 502, so following
  // stays on the application's own proxy and no credential leaves it.
  let current = request;
  for (let hop = 0; ; hop += 1) {
    const response = await handleJsonApiProxy(current);
    const location = response.headers.get('location');
    if (!REDIRECT_STATUSES.has(response.status) || location === null) {
      return response;
    }
    if (hop >= MAX_REDIRECTS) {
      throw new TypeError(
        'Too many redirects while fetching from the JSON:API proxy.',
      );
    }
    const target = new URL(location, current.url);
    const proxyOrigin = new URL(pageUrl).origin;
    if (target.origin !== proxyOrigin) {
      throw new TypeError(
        `The JSON:API proxy redirected outside the application (${target.origin}).`,
      );
    }
    // Fetch semantics: 303 switches to GET (HEAD stays HEAD), 301/302 switch
    // POST to GET, and 307/308 keep the method; a switch to GET drops the
    // body and its headers, everything else re-sends the body.
    const switchToGet =
      response.status === 303
        ? current.method !== 'HEAD'
        : (response.status === 301 || response.status === 302) &&
          current.method === 'POST';
    const method = switchToGet ? 'GET' : current.method;
    const headers = new Headers(current.headers);
    let body: BodyInit | null = null;
    if (method === 'GET' || method === 'HEAD') {
      headers.delete('content-type');
      headers.delete('content-length');
      headers.delete('content-encoding');
    } else {
      if (replayableBody === null && current.body !== null) {
        // Fetch fails a redirect whose body cannot be replayed.
        throw new TypeError(
          'The JSON:API proxy redirected a request whose body cannot be re-sent.',
        );
      }
      body = replayableBody;
    }
    current = new Request(target, {
      method,
      headers,
      body,
      signal: current.signal,
    });
  }
};

function toUrlString(input: RequestInfo | URL): string {
  if (typeof input === 'string') {
    return input;
  }
  if (input instanceof URL) {
    return input.href;
  }
  return input.url;
}
