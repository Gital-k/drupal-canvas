/**
 * @file
 * The shared, framework-agnostic JSON:API client for Drupal Canvas. It
 * extends `@drupal-api-client/json-api-client` with:
 * - draft reads at a configured resource version (working copies), resolved
 *   before the configured serializer runs;
 * - transparent mapping of browser requests to a same-origin proxy, including
 *   absolute pagination links and the path-translation endpoint;
 * - draft-session errors surfaced as `DraftSessionError`;
 * - client caches separated by resource version and session scope.
 *
 * The legacy `new JsonApiClient()` API remains for Drupal-rendered Code
 * Components and Workbench; it reads `drupalSettings` and throws an actionable
 * migration error elsewhere. Rendering integrations and the Headless SDK use
 * `createJsonApiClient()` instead, which has no environment guard.
 *
 * @see docs/adr/0021-code-component-runtime-compatibility-across-frontend-modes.md
 */

import {
  DefaultSerializer,
  JsonApiClient as UpstreamJsonApiClient,
} from '@drupal-api-client/json-api-client';

import {
  DEFAULT_JSONAPI_PREFIX,
  DEFAULT_ROUTER_PREFIX,
  JSONAPI_PROXY_SESSION_EXPIRED,
  JSONAPI_PROXY_SESSION_HEADER,
  mapJsonApiRequestToProxy,
  normalizeBaseUrl,
  resolveJsonApiBase,
  trimSlashes,
} from './jsonapi-proxy.js';
import { assertLegacyRuntime } from './migration.js';

import type { BaseUrl, Cache } from '@drupal-api-client/api-client';
import type {
  GetOptions,
  JsonApiClientOptions,
} from '@drupal-api-client/json-api-client';

type EndpointUrlSegments = Parameters<UpstreamJsonApiClient['createURL']>[0];

/**
 * Serializable JSON:API runtime configuration. Rendering integrations receive
 * it from the environment (Drupal settings, Workbench, or the Headless SDK's
 * server integration) and create the browser client from it. It carries no
 * secrets.
 */
export interface JsonApiRuntimeConfig {
  /** Absolute base URL of the Drupal backend, without a trailing slash. */
  baseUrl: string;
  /** JSON:API prefix without surrounding slashes. Default: `jsonapi`. */
  apiPrefix?: string;
  /**
   * Absolute upstream JSON:API base URL when JSON:API is not served under
   * `baseUrl`/`apiPrefix` (for example `https://api.example/drupal/jsonapi`).
   * Takes precedence over `apiPrefix`: its path is the JSON:API prefix and its
   * origin the JSON:API origin (a locale segment precedes the prefix). The
   * Decoupled Router and other supporting endpoints stay under `baseUrl`.
   */
  apiUrl?: string;
  /** Decoupled Router path without surrounding slashes. */
  routerPrefix?: string;
  /**
   * Same-origin proxy base URL (for example `/api/canvas/jsonapi`). When set,
   * every backend request is sent through the proxy instead.
   */
  proxyUrl?: string;
  /**
   * JSON:API `resourceVersion` applied to reads (for example
   * `rel:working-copy` in previews); `null` or omitted keeps default
   * revisions.
   */
  resourceVersion?: string | null;
  /** Whether the client serves an editor preview. */
  preview?: boolean;
}

/** Full client configuration: runtime configuration plus runtime objects. */
export interface JsonApiClientConfig extends JsonApiRuntimeConfig {
  /** Authentication for direct backend requests (server side only). */
  authentication?: JsonApiClientOptions['authentication'];
  /**
   * Response serializer. Defaults to `DefaultSerializer`; pass `null` to keep
   * raw JSON:API documents.
   */
  serializer?: JsonApiClientOptions['serializer'] | null;
  /**
   * Client cache. Keys are scoped by resource version and `cacheScope`. An
   * authenticated client caches only with an explicit `cacheScope`: without
   * one, responses fetched with one session could be served to another
   * client sharing the cache, so the cache is ignored.
   */
  cache?: Cache;
  /**
   * Distinguishes cache entries of different sessions sharing one cache
   * object. Defaults to `public` for unauthenticated clients. Authenticated
   * clients must set a scope unique to the session (for example the session
   * subject and token expiry) to cache at all.
   */
  cacheScope?: string;
  /** Fetch implementation; defaults to the global `fetch`. */
  fetch?: typeof fetch;
  /** Credentials mode for requests, e.g. `same-origin` for Drupal previews. */
  credentials?: RequestCredentials;
  /**
   * Additional options passed to the upstream client (`indexLookup`,
   * `defaultLocale`, `logger`, `debug`). Options this configuration already
   * covers are ignored.
   */
  upstreamOptions?: Omit<
    JsonApiClientOptions,
    | 'apiPrefix'
    | 'authentication'
    | 'cache'
    | 'customFetch'
    | 'decoupledRouterApiPrefix'
    | 'serializer'
  >;
}

/**
 * Thrown when the draft preview session is rejected: the application proxy
 * reports it expired or invalid, or Drupal answers an authenticated request
 * with 401. Never swallowed by the client's fallbacks.
 */
export class DraftSessionError extends Error {
  readonly status: number;

  constructor(message: string, status = 401) {
    super(message);
    this.name = 'DraftSessionError';
    this.status = status;
  }
}

export function isDraftSessionError(
  error: unknown,
): error is DraftSessionError {
  return (
    error instanceof DraftSessionError ||
    (typeof error === 'object' &&
      error !== null &&
      (error as { name?: unknown }).name === 'DraftSessionError')
  );
}

function toUrlString(input: RequestInfo | URL): string {
  if (typeof input === 'string') {
    return input;
  }
  if (input instanceof URL) {
    return input.href;
  }
  return input.url;
}

/**
 * Creates the transport for the shared client: routes backend requests through
 * the configured proxy, applies the credentials mode, and turns the proxy's
 * draft-session errors into exceptions.
 */
function createTransport(config: JsonApiClientConfig): typeof fetch {
  const fetchImpl = config.fetch ?? fetch;
  return async (input, init) => {
    let url = toUrlString(input);
    if (config.proxyUrl) {
      url =
        mapJsonApiRequestToProxy(url, {
          baseUrl: config.baseUrl,
          proxyUrl: config.proxyUrl,
          apiPrefix: config.apiPrefix,
          apiUrl: config.apiUrl,
          routerPrefix: config.routerPrefix,
        }) ?? url;
    }
    const request: RequestInit = { ...init };
    if (config.credentials && request.credentials === undefined) {
      request.credentials = config.credentials;
    }
    const response = await fetchImpl(url, request);
    // A direct, authenticated request answered with 401 means Drupal rejected
    // the session credentials (expired or revoked token); 403 and other
    // failures are ordinary resource-level outcomes and stay with the caller.
    if (config.authentication && !config.proxyUrl && response.status === 401) {
      throw new DraftSessionError(
        'The draft preview session was rejected by Drupal.',
        401,
      );
    }
    if (
      config.proxyUrl &&
      response.headers.get(JSONAPI_PROXY_SESSION_HEADER) ===
        JSONAPI_PROXY_SESSION_EXPIRED
    ) {
      let message = 'The draft preview session has expired.';
      try {
        const body = (await response.clone().json()) as { message?: unknown };
        if (typeof body?.message === 'string') {
          message = body.message;
        }
      } catch {
        // Keep the default message.
      }
      throw new DraftSessionError(message, response.status);
    }
    return response;
  };
}

/** Prefixes every key of a cache so scopes never share entries. */
function scopeCache(cache: Cache, scope: string): Cache {
  return {
    get: (key, ...args) => cache.get(`${scope}:${key}`, ...args),
    set: (key, value, ...args) => cache.set(`${scope}:${key}`, value, ...args),
  };
}

interface JsonApiResourceIdentifier {
  id?: unknown;
  type?: unknown;
}

interface JsonApiCollectionDocument {
  data?: unknown;
  [key: string]: unknown;
}

/**
 * The shared client implementation. Construct it with
 * {@link createJsonApiClient}; the legacy `JsonApiClient` constructor extends
 * it for Drupal and Workbench.
 */
export class CanvasJsonApiClient extends UpstreamJsonApiClient {
  /** The serializable part of the configuration. */
  readonly runtimeConfig: JsonApiRuntimeConfig;

  /** The resource version applied to reads, or `null`. */
  readonly resourceVersion: string | null;

  /** The JSON:API origin when it differs from the backend base URL. */
  private readonly apiOrigin: string | null;

  constructor(config: JsonApiClientConfig) {
    const baseUrl = normalizeBaseUrl(config.baseUrl);
    const apiUrl = config.apiUrl ? normalizeBaseUrl(config.apiUrl) : undefined;
    const api = resolveJsonApiBase(baseUrl, {
      apiPrefix: config.apiPrefix,
      apiUrl,
    });
    const apiPrefix = api.apiPrefix || DEFAULT_JSONAPI_PREFIX;
    const routerPrefix =
      trimSlashes(config.routerPrefix ?? DEFAULT_ROUTER_PREFIX) ||
      DEFAULT_ROUTER_PREFIX;
    const resourceVersion = config.resourceVersion || null;
    // Authenticated responses are session-specific: without a scope that
    // identifies the session, a shared cache must not hold them.
    const cache =
      config.cache && (!config.authentication || config.cacheScope)
        ? scopeCache(
            config.cache,
            [resourceVersion ?? 'default', config.cacheScope ?? 'public'].join(
              ':',
            ),
          )
        : undefined;
    const serializer =
      config.serializer === undefined
        ? new DefaultSerializer()
        : (config.serializer ?? undefined);
    super(baseUrl, {
      ...config.upstreamOptions,
      apiPrefix,
      decoupledRouterApiPrefix: routerPrefix,
      serializer,
      cache,
      authentication: config.authentication,
      customFetch: createTransport({ ...config, baseUrl }),
    });
    this.runtimeConfig = {
      baseUrl,
      apiPrefix,
      ...(apiUrl && { apiUrl }),
      routerPrefix,
      ...(config.proxyUrl && { proxyUrl: config.proxyUrl }),
      resourceVersion,
      preview: config.preview ?? false,
    };
    this.resourceVersion = resourceVersion;
    this.apiOrigin = apiUrl && api.origin !== baseUrl ? api.origin : null;
  }

  /**
   * Builds JSON:API URLs on the separate JSON:API origin when one is
   * configured; index lookups and everything else stay upstream.
   */
  override async createURL(segments: EndpointUrlSegments): Promise<string> {
    if (this.apiOrigin === null || this.indexLookup) {
      return super.createURL(segments);
    }
    const {
      localeSegment,
      entityTypeId,
      bundleId,
      viewName,
      viewDisplayId,
      resourceId,
      queryString,
    } = segments;
    const locale = localeSegment ? `${localeSegment}/` : '';
    const path = viewName
      ? `${locale}${this.apiPrefix}/views/${viewName}/${viewDisplayId}`
      : `${locale}${this.apiPrefix}/${entityTypeId}/${bundleId}${resourceId ? `/${resourceId}` : ''}`;
    return new URL(
      `/${path}${queryString ? `?${queryString}` : ''}`,
      this.apiOrigin,
    ).toString();
  }

  /**
   * Adds the configured resource version to a read unless the caller selected
   * one explicitly.
   */
  private withResourceVersion(options?: GetOptions): GetOptions | undefined {
    if (
      this.resourceVersion === null ||
      options?.queryString?.includes('resourceVersion=')
    ) {
      return options;
    }
    const version = `resourceVersion=${encodeURIComponent(this.resourceVersion)}`;
    return {
      ...options,
      queryString: options?.queryString
        ? `${options.queryString}&${version}`
        : version,
    };
  }

  override async getResource<T>(
    type: string,
    resourceId: string,
    options?: GetOptions,
  ) {
    return super.getResource<T>(
      type,
      resourceId,
      options?.rawResponse ? options : this.withResourceVersion(options),
    );
  }

  /**
   * Collection reads at a resource version fetch each item's working copy in
   * a separate request, because Drupal returns default revisions in
   * collections. Working copies are merged into the raw document before the
   * serializer runs. A failing ordinary item fetch keeps the original item;
   * draft-session errors propagate. Raw responses bypass this.
   */
  override async getCollection<T>(type: string, options?: GetOptions) {
    if (this.resourceVersion === null || options?.rawResponse) {
      return super.getCollection<T>(type, options);
    }
    const { entityTypeId, bundleId } =
      UpstreamJsonApiClient.getEntityTypeIdAndBundleId(type);
    const localeSegment = options?.locale || this.defaultLocale;
    const cacheKey = await UpstreamJsonApiClient.createCacheKey({
      entityTypeId,
      bundleId,
      localeSegment,
      queryString: options?.queryString,
      cacheKey: options?.cacheKey,
    });
    if (!options?.disableCache) {
      const cached = await this.getCachedResponse<T>(cacheKey);
      if (cached) {
        return cached;
      }
    }
    const url = await this.createURL({
      localeSegment,
      entityTypeId,
      bundleId,
      queryString: options?.queryString,
    });
    const init: RequestInit = options?.disableAuthentication
      ? { credentials: 'omit' }
      : {};
    const { response, error } = await this.fetch(url, init);
    if (error) {
      throw error;
    }
    const status = response.status;
    let document: unknown = status === 204 ? '' : await response.json();
    if (status < 400 && isCollectionDocument(document)) {
      document = await this.hydrateWorkingCopies(type, document, init, {
        localeSegment,
        queryString: options?.queryString,
      });
    }
    const result = (
      this.serializer?.deserialize
        ? this.serializer.deserialize(document as Record<string, unknown>)
        : document
    ) as T;
    if (this.cache && !options?.disableCache && status < 400) {
      await this.cache.set(cacheKey, result);
    }
    return result;
  }

  /**
   * Replaces each collection item with its working copy. Item reads keep the
   * read options that apply to a single resource — the locale, sparse
   * fieldsets, includes, and a resource version the caller selected
   * explicitly — but not collection-only parameters (filter, sort, page).
   * Included resources returned with the working copies replace the
   * collection's included resources of the same identity, so relationships
   * of a working copy resolve against the document.
   */
  private async hydrateWorkingCopies(
    type: string,
    document: JsonApiCollectionDocument & { data: JsonApiResourceIdentifier[] },
    init: RequestInit,
    read: { localeSegment?: string; queryString?: string },
  ): Promise<JsonApiCollectionDocument> {
    const { entityTypeId, bundleId } =
      UpstreamJsonApiClient.getEntityTypeIdAndBundleId(type);
    const itemParams = new URLSearchParams();
    for (const [key, value] of new URLSearchParams(read.queryString ?? '')) {
      if (
        key === 'include' ||
        key === 'resourceVersion' ||
        /^fields\[[^\]]+\]$/.test(key)
      ) {
        itemParams.append(key, value);
      }
    }
    if (!itemParams.has('resourceVersion')) {
      itemParams.set('resourceVersion', this.resourceVersion as string);
    }
    const included = new Map<string, unknown>();
    const includedKey = (resource: unknown): string | null =>
      typeof resource === 'object' &&
      resource !== null &&
      typeof (resource as JsonApiResourceIdentifier).type === 'string' &&
      typeof (resource as JsonApiResourceIdentifier).id === 'string'
        ? `${(resource as JsonApiResourceIdentifier).type}:${(resource as JsonApiResourceIdentifier).id}`
        : null;
    if (Array.isArray(document.included)) {
      for (const resource of document.included) {
        const key = includedKey(resource);
        if (key !== null) {
          included.set(key, resource);
        }
      }
    }
    const data = await Promise.all(
      document.data.map(async (item) => {
        if (typeof item?.id !== 'string') {
          return item;
        }
        const url = await this.createURL({
          localeSegment: read.localeSegment,
          entityTypeId,
          bundleId,
          resourceId: item.id,
          queryString: itemParams.toString(),
        });
        try {
          const { response, error } = await this.fetch(url, init);
          if (error) {
            throw error;
          }
          if (!response.ok) {
            return item;
          }
          const workingCopy = (await response.json()) as {
            data?: unknown;
            included?: unknown;
          };
          if (
            !workingCopy ||
            typeof workingCopy !== 'object' ||
            !('data' in workingCopy) ||
            !workingCopy.data
          ) {
            return item;
          }
          if (Array.isArray(workingCopy.included)) {
            for (const resource of workingCopy.included) {
              const key = includedKey(resource);
              if (key !== null) {
                included.set(key, resource);
              }
            }
          }
          return workingCopy.data;
        } catch (error) {
          if (isDraftSessionError(error)) {
            throw error;
          }
          return item;
        }
      }),
    );
    return {
      ...document,
      data,
      ...(included.size > 0 && { included: [...included.values()] }),
    };
  }
}

function isCollectionDocument(
  value: unknown,
): value is JsonApiCollectionDocument & { data: JsonApiResourceIdentifier[] } {
  return (
    typeof value === 'object' &&
    value !== null &&
    Array.isArray((value as JsonApiCollectionDocument).data)
  );
}

/**
 * Creates the shared JSON:API client from explicit configuration. This is the
 * construction path for rendering integrations and the Headless SDK; it has
 * no environment guard.
 */
export function createJsonApiClient(
  config: JsonApiClientConfig,
): CanvasJsonApiClient {
  return new CanvasJsonApiClient(config);
}

interface LegacyDrupalSettings {
  canvasData?: {
    v0?: {
      baseUrl?: string;
      jsonapiSettings?: { apiPrefix?: string } | null;
    };
  };
}

/**
 * The legacy JSON:API client for Drupal-rendered Code Components and
 * Workbench: configured from `drupalSettings`, with `DefaultSerializer` and
 * the site's JSON:API prefix. Outside those environments it throws an
 * actionable migration error, even when a base URL is supplied.
 *
 * @deprecated Use `useJsonApiClient()` in React Code Components, or the
 *   Headless SDK's `getClient()` in headless server code.
 */
class LegacyJsonApiClient extends CanvasJsonApiClient {
  constructor(baseUrl?: BaseUrl, options?: JsonApiClientOptions) {
    assertLegacyRuntime('new JsonApiClient()');
    const settings = (globalThis as { drupalSettings?: LegacyDrupalSettings })
      .drupalSettings?.canvasData?.v0;
    if (settings?.jsonapiSettings === null) {
      throw new Error(
        'The JSON:API module is not installed. Please install it to use JsonApiClient.',
      );
    }
    const clientBaseUrl = baseUrl || settings?.baseUrl;
    if (!clientBaseUrl) {
      throw new Error(
        "Could not determine your site's base URL for the JSON:API client. " +
          'If working outside of Drupal Canvas, you can use the @drupal-canvas/vite-plugin to automatically configure it for you. ' +
          'Otherwise you must explicitly provide a base URL, i.e. `const client = new JsonApiClient("https://...")`',
      );
    }
    const {
      apiPrefix,
      authentication,
      cache,
      customFetch,
      decoupledRouterApiPrefix,
      serializer,
      ...upstreamOptions
    } = options ?? {};
    super({
      baseUrl: clientBaseUrl,
      apiPrefix: apiPrefix ?? settings?.jsonapiSettings?.apiPrefix,
      routerPrefix: decoupledRouterApiPrefix,
      authentication,
      cache,
      cacheScope: 'legacy',
      fetch: customFetch as typeof fetch | undefined,
      serializer:
        options && 'serializer' in options ? (serializer ?? null) : undefined,
      upstreamOptions,
    });
  }
}

export * from '@drupal-api-client/json-api-client';
export {
  DEFAULT_JSONAPI_PREFIX,
  DEFAULT_ROUTER_PREFIX,
  isSafeProxyPath,
  JSONAPI_DRAFT_SESSION_EXPIRED_ERROR,
  JSONAPI_PROXY_SESSION_EXPIRED,
  JSONAPI_PROXY_SESSION_HEADER,
  mapJsonApiRequestToProxy,
  mapProxyPathToUpstream,
  normalizeBaseUrl,
  resolveJsonApiBase,
  resolveJsonApiProxyPath,
  toBackendRelativePath,
  trimSlashes,
  type JsonApiProxyEndpointName,
  type JsonApiProxyEndpoints,
  type ResolvedJsonApiProxyPath,
} from './jsonapi-proxy.js';
export { LegacyJsonApiClient as JsonApiClient };
