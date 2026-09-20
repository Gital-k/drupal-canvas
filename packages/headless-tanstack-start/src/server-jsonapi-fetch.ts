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
  const proxyOrigin = new URL(pageUrl).origin;
  const source = input instanceof Request ? input : null;
  const url = new URL(toUrlString(input), pageUrl);
  const method = (init?.method ?? source?.method ?? 'GET').toUpperCase();
  const headers = new Headers(init?.headers ?? source?.headers);
  const signal = init?.signal ?? source?.signal ?? undefined;
  const redirectMode = init?.redirect ?? source?.redirect ?? 'follow';
  // The body, in a form that can be sent again after a redirect: a string,
  // buffer or form body as given, a Request's body read once into memory. A
  // stream given as `init.body` is sent as is and cannot be replayed.
  let body: BodyInit | null = null;
  let replayable = true;
  if (init?.body !== undefined && init.body !== null) {
    body = init.body;
    replayable = !(init.body instanceof ReadableStream);
  } else if (source?.body) {
    body = await source.clone().arrayBuffer();
  }
  // The request is issued by the application's own server-rendering pass,
  // which is what this provenance signal states. The page request's own
  // provenance (for example `cross-site` while Drupal's editor embeds the
  // preview) describes the visitor's navigation, not this request, and is
  // deliberately not forwarded.
  headers.set('sec-fetch-site', 'same-origin');
  const build = (
    target: URL,
    requestMethod: string,
    requestHeaders: Headers,
    requestBody: BodyInit | null,
  ) =>
    new Request(target, {
      method: requestMethod,
      headers: requestHeaders,
      body:
        requestMethod === 'GET' || requestMethod === 'HEAD'
          ? null
          : requestBody,
      signal,
      ...(requestBody instanceof ReadableStream ? { duplex: 'half' } : {}),
    } as RequestInit);

  // Follow redirects the way a browser's fetch does for the requested
  // redirect mode: the proxy handler only ever emits Locations inside the
  // boundary, rewritten to the proxy path, and refuses the rest with 502, so
  // following stays on the application's own proxy and no credential leaves
  // it.
  let current = build(url, method, headers, body);
  let currentMethod = method;
  let currentHeaders = headers;
  for (let hop = 0; ; hop += 1) {
    const response = await handleJsonApiProxy(current);
    const location = response.headers.get('location');
    if (!REDIRECT_STATUSES.has(response.status) || location === null) {
      return response;
    }
    if (redirectMode === 'manual') {
      return response;
    }
    if (redirectMode === 'error') {
      throw new TypeError(
        'The JSON:API proxy answered a redirect and the request does not allow redirects.',
      );
    }
    if (hop >= MAX_REDIRECTS) {
      throw new TypeError(
        'Too many redirects while fetching from the JSON:API proxy.',
      );
    }
    const target = new URL(location, current.url);
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
        ? currentMethod !== 'HEAD'
        : (response.status === 301 || response.status === 302) &&
          currentMethod === 'POST';
    const nextMethod = switchToGet ? 'GET' : currentMethod;
    const nextHeaders = new Headers(currentHeaders);
    if (nextMethod === 'GET' || nextMethod === 'HEAD') {
      nextHeaders.delete('content-type');
      nextHeaders.delete('content-length');
      nextHeaders.delete('content-encoding');
    } else if (body !== null && !replayable) {
      // Fetch fails a redirect whose body cannot be replayed.
      throw new TypeError(
        'The JSON:API proxy redirected a request whose body cannot be re-sent.',
      );
    }
    current = build(target, nextMethod, nextHeaders, body);
    currentMethod = nextMethod;
    currentHeaders = nextHeaders;
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
