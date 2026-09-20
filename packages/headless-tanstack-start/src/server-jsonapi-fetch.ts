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
 * session token.
 *
 * Only callable while a request is being handled (server rendering runs
 * inside TanStack Start's request context). Server-only: this module reaches
 * the session helpers, so the isomorphic `getServerJsonApiFetch()` loads it
 * on the server alone and the Start compiler keeps it out of browser bundles.
 */
export const serverJsonApiFetch: typeof fetch = async (input, init) => {
  const url = new URL(toUrlString(input), getRequest().url);
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
  return handleJsonApiProxy(request);
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
