/**
 * @file
 * Framework-agnostic URL mapping between a Drupal backend's JSON:API
 * endpoints and a same-origin application proxy. The browser client maps
 * upstream URLs to the proxy; the proxy (in the Canvas Headless SDK) validates
 * and maps them back. Both sides share this module so they cannot disagree
 * about which paths are supported.
 *
 * The supported endpoints are those the JSON:API client reaches:
 * - the JSON:API prefix (resources, collections, views, the index), and
 * - the Decoupled Router path translation endpoint (`getResourceByPath()`).
 * Both may be preceded by one Drupal language path prefix.
 *
 * @see docs/adr/0021-code-component-runtime-compatibility-across-frontend-modes.md
 */

/** The default JSON:API prefix of Drupal core's JSON:API module. */
export const DEFAULT_JSONAPI_PREFIX = 'jsonapi';

/** The default path of the Decoupled Router module's endpoint. */
export const DEFAULT_ROUTER_PREFIX = 'router/translate-path';

/**
 * The response header the proxy sets on draft-session errors, and the value
 * it carries when the session expired or is invalid.
 */
export const JSONAPI_PROXY_SESSION_HEADER = 'X-Canvas-Draft-Session';

export const JSONAPI_PROXY_SESSION_EXPIRED = 'expired';

/** The `error` code of the proxy's draft-session error body. */
export const JSONAPI_DRAFT_SESSION_EXPIRED_ERROR = 'draft_session_expired';

/** The endpoints the proxy maps. */
export interface JsonApiProxyEndpoints {
  /** JSON:API prefix without surrounding slashes, e.g. `jsonapi`. */
  apiPrefix: string;
  /** Decoupled Router path without surrounding slashes. */
  routerPrefix: string;
  /**
   * Absolute upstream JSON:API base URL when JSON:API is not served under the
   * backend base URL and `apiPrefix` (for example on another host or path).
   * Its path becomes the JSON:API prefix; the supporting endpoints (path
   * translation) stay under the backend base URL.
   */
  apiUrl?: string;
}

/** The JSON:API origin and prefix an endpoint configuration resolves to. */
export function resolveJsonApiBase(
  baseUrl: string,
  endpoints: Partial<JsonApiProxyEndpoints> = {},
): { origin: string; apiPrefix: string } {
  if (endpoints.apiUrl) {
    const api = new URL(normalizeBaseUrl(endpoints.apiUrl));
    return {
      origin: api.origin,
      apiPrefix: trimSlashes(api.pathname) || DEFAULT_JSONAPI_PREFIX,
    };
  }
  return {
    origin: normalizeBaseUrl(baseUrl),
    apiPrefix: trimSlashes(endpoints.apiPrefix || DEFAULT_JSONAPI_PREFIX),
  };
}

export type JsonApiProxyEndpointName = 'jsonapi' | 'router';

/** A validated proxied path. */
export interface ResolvedJsonApiProxyPath {
  endpoint: JsonApiProxyEndpointName;
  /** The site-relative upstream path, including any language prefix. */
  path: string;
}

/** Drupal language codes as they appear in URL path prefixes. */
const LANGUAGE_PREFIX_PATTERN = /^[a-z]{2,3}(?:-[a-zA-Z0-9]{2,8})?$/;

/** Strips surrounding slashes. */
export function trimSlashes(value: string): string {
  return value.replace(/^\/+|\/+$/g, '');
}

function splitSegments(prefix: string): string[] {
  return trimSlashes(prefix).split('/').filter(Boolean);
}

/**
 * Whether `path` is a safe site-relative path: one leading slash, no empty,
 * dot, or dot-dot segments, no backslashes, and no encoded slashes that could
 * decode into a different path upstream.
 */
export function isSafeProxyPath(path: string): boolean {
  if (!path.startsWith('/') || path.startsWith('//') || path.includes('\\')) {
    return false;
  }
  if (/%2f|%5c/i.test(path)) {
    return false;
  }
  const segments = path.slice(1).split('/');
  return segments.every(
    (segment, index) =>
      (segment !== '' || index === segments.length - 1) &&
      segment !== '.' &&
      segment !== '..',
  );
}

function matchesPrefix(segments: string[], prefix: string[]): boolean {
  return (
    prefix.length > 0 &&
    segments.length >= prefix.length &&
    prefix.every((segment, index) => segments[index] === segment)
  );
}

/**
 * Validates a site-relative path against the supported endpoints. Returns the
 * endpoint it belongs to, or `null` when the path is unsafe or outside the
 * boundary.
 */
export function resolveJsonApiProxyPath(
  path: string,
  endpoints: Partial<JsonApiProxyEndpoints> = {},
): ResolvedJsonApiProxyPath | null {
  if (!isSafeProxyPath(path)) {
    return null;
  }
  const apiPrefix = splitSegments(
    endpoints.apiUrl
      ? resolveJsonApiBase('http://localhost', endpoints).apiPrefix
      : endpoints.apiPrefix || DEFAULT_JSONAPI_PREFIX,
  );
  const routerPrefix = splitSegments(
    endpoints.routerPrefix || DEFAULT_ROUTER_PREFIX,
  );
  const segments = path.slice(1).split('/').filter(Boolean);
  const candidates = [segments];
  if (segments.length > 1 && LANGUAGE_PREFIX_PATTERN.test(segments[0])) {
    candidates.push(segments.slice(1));
  }
  for (const candidate of candidates) {
    if (matchesPrefix(candidate, apiPrefix)) {
      return { endpoint: 'jsonapi', path };
    }
    if (matchesPrefix(candidate, routerPrefix)) {
      return { endpoint: 'router', path };
    }
  }
  return null;
}

/** Normalizes a backend base URL: absolute, no trailing slash. */
export function normalizeBaseUrl(baseUrl: string): string {
  const url = new URL(baseUrl);
  url.search = '';
  url.hash = '';
  return `${url.origin}${url.pathname.replace(/\/+$/, '')}`;
}

/**
 * Splits an absolute upstream URL into the part relative to the backend base
 * URL. Returns `null` when the URL is not on the backend.
 */
export function toBackendRelativePath(
  url: string,
  baseUrl: string,
): { path: string; search: string } | null {
  let target: URL;
  try {
    target = new URL(url);
  } catch {
    return null;
  }
  const base = new URL(normalizeBaseUrl(baseUrl));
  if (target.origin !== base.origin) {
    return null;
  }
  const basePath = base.pathname.replace(/\/+$/, '');
  if (
    basePath !== '' &&
    target.pathname !== basePath &&
    !target.pathname.startsWith(`${basePath}/`)
  ) {
    return null;
  }
  const path = target.pathname.slice(basePath.length) || '/';
  return { path, search: target.search };
}

/**
 * Maps an absolute upstream JSON:API URL to the same-origin proxy. Returns
 * `null` for URLs that are not on the backend. Throws for backend URLs
 * outside the supported endpoints, so an absolute Drupal URL can never bypass
 * the proxy.
 */
export function mapJsonApiRequestToProxy(
  url: string,
  options: {
    baseUrl: string;
    proxyUrl: string;
  } & Partial<JsonApiProxyEndpoints>,
): string | null {
  const api = resolveJsonApiBase(options.baseUrl, options);
  // With a separate JSON:API base, JSON:API requests live on its origin and
  // the supporting endpoints on the backend base URL. Without one, both are
  // relative to the backend base URL.
  const targets: Array<{
    base: string;
    endpoint: JsonApiProxyEndpointName | null;
  }> = options.apiUrl
    ? [
        { base: api.origin, endpoint: 'jsonapi' },
        { base: options.baseUrl, endpoint: 'router' },
      ]
    : [{ base: options.baseUrl, endpoint: null }];
  let onBackend = false;
  for (const target of targets) {
    const relative = toBackendRelativePath(url, target.base);
    if (relative === null) {
      continue;
    }
    onBackend = true;
    const resolved = resolveJsonApiProxyPath(relative.path, options);
    if (
      resolved !== null &&
      (target.endpoint === null || resolved.endpoint === target.endpoint)
    ) {
      return `${options.proxyUrl.replace(/\/+$/, '')}${resolved.path}${relative.search}`;
    }
  }
  if (!onBackend) {
    return null;
  }
  throw new Error(
    `[drupal-canvas] The browser JSON:API client only reaches the Drupal ` +
      `backend through the application proxy, and "${url}" is ` +
      'not a supported JSON:API or path-translation endpoint.',
  );
}

/**
 * Maps a proxied request path back to its absolute upstream URL. Returns
 * `null` when the path is unsafe or outside the supported endpoints.
 */
export function mapProxyPathToUpstream(
  proxiedPath: string,
  options: {
    baseUrl: string;
    proxyUrl: string;
  } & Partial<JsonApiProxyEndpoints>,
): { url: string; endpoint: JsonApiProxyEndpointName } | null {
  const proxyPath = new URL(
    options.proxyUrl,
    'http://localhost',
  ).pathname.replace(/\/+$/, '');
  if (proxyPath !== '' && !proxiedPath.startsWith(`${proxyPath}/`)) {
    return null;
  }
  const path =
    proxyPath === '' ? proxiedPath : proxiedPath.slice(proxyPath.length);
  const resolved = resolveJsonApiProxyPath(path, options);
  if (resolved === null) {
    return null;
  }
  const upstreamBase =
    resolved.endpoint === 'jsonapi'
      ? resolveJsonApiBase(options.baseUrl, options).origin
      : normalizeBaseUrl(options.baseUrl);
  return {
    url: `${upstreamBase}${resolved.path}`,
    endpoint: resolved.endpoint,
  };
}
