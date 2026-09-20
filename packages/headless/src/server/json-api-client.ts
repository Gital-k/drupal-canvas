/**
 * @file
 * The SDK's JSON:API client factories. Both create the shared
 * `drupal-canvas` client implementation (the same one `useJsonApiClient()`
 * hands to React Code Components) instead of maintaining a separate
 * subclass: draft reads, serialization, and proxy mapping live there.
 */

import { createJsonApiClient } from 'drupal-canvas/jsonapi-client';

import { getSessionToken } from '../token';

import type {
  CanvasJsonApiClient,
  JsonApiRuntimeConfig,
} from 'drupal-canvas/jsonapi-client';
import type { DraftData } from '../draft-data';
import type { DraftConfig } from './config';

/**
 * The resolved upstream JSON:API endpoints: the backend base URL (where the
 * supporting endpoints such as path translation live), the JSON:API prefix
 * relative to it, and — with a full URL override — the separate JSON:API base
 * URL.
 */
export interface JsonApiEndpoints {
  baseUrl: string;
  apiPrefix?: string;
  apiUrl?: string;
}

/**
 * Resolves the upstream JSON:API endpoints. An explicit full URL override
 * (`jsonApiUrl`) takes precedence over the discovered or configured prefix
 * for JSON:API requests only: the backend base URL keeps serving the
 * Decoupled Router and the other supporting endpoints, so an override on
 * another host or path never redirects path translation there.
 */
export function resolveJsonApiEndpoints(
  config: Pick<DraftConfig, 'baseUrl' | 'apiPrefix' | 'jsonApiUrl'>,
): JsonApiEndpoints {
  if (config.jsonApiUrl) {
    const url = new URL(config.jsonApiUrl);
    const apiPrefix = url.pathname.replace(/^\/+|\/+$/g, '');
    return {
      baseUrl: config.baseUrl,
      ...(apiPrefix && { apiPrefix }),
      apiUrl: `${url.origin}${apiPrefix ? `/${apiPrefix}` : ''}`,
    };
  }
  return {
    baseUrl: config.baseUrl,
    ...(config.apiPrefix && { apiPrefix: config.apiPrefix }),
  };
}

/**
 * The full upstream JSON:API base URL the endpoints resolve to.
 */
export function resolveJsonApiUrl(endpoints: JsonApiEndpoints): string {
  return (
    endpoints.apiUrl ??
    `${endpoints.baseUrl.replace(/\/+$/, '')}/${endpoints.apiPrefix ?? 'jsonapi'}`
  );
}

/**
 * The nonsecret JSON:API runtime configuration for a browser client: the
 * resolved upstream endpoints, the application's proxy path, and the
 * session's resource version while a draft session is live. Contains no
 * credentials and is safe to serialize into a page.
 */
export function resolveJsonApiRuntimeConfig(
  config: Pick<
    DraftConfig,
    'baseUrl' | 'apiPrefix' | 'jsonApiUrl' | 'jsonApiProxyPath'
  >,
  draftData: DraftData | null,
): JsonApiRuntimeConfig {
  const endpoints = resolveJsonApiEndpoints(config);
  const live = draftData !== null && getSessionToken(draftData) !== null;
  return {
    baseUrl: endpoints.baseUrl,
    ...(endpoints.apiPrefix && { apiPrefix: endpoints.apiPrefix }),
    ...(endpoints.apiUrl && { apiUrl: endpoints.apiUrl }),
    proxyUrl: config.jsonApiProxyPath ?? '/api/canvas/jsonapi',
    resourceVersion: live ? draftData.resourceVersion : null,
    preview: live,
  };
}

/**
 * A client for public content: unauthenticated, sees only published content.
 *
 * The config's apiPrefix (resolved from the site's canvasData.v0 payload by
 * the draft server) points the client at sites serving JSON:API from a
 * non-default prefix, e.g. `/api`; when absent, the client's `/jsonapi`
 * default applies.
 */
export function getPublicClient(
  config: Pick<DraftConfig, 'baseUrl' | 'apiPrefix' | 'jsonApiUrl'>,
): CanvasJsonApiClient {
  return createJsonApiClient({
    ...resolveJsonApiEndpoints(config),
    resourceVersion: null,
    preview: false,
    cacheScope: 'public',
  });
}

/**
 * A client for draft content, authenticated with the session's user-bound
 * access token (minted from the preview assertion, carrying the initiating
 * editor's own permissions). Returns working copies transparently: resource
 * reads use the session's resource version, and collection reads fetch each
 * item's working copy (see the shared client).
 *
 * Throws when the session has expired — callers are expected to check
 * isDraftSessionExpired() first and fall back to the public client with a
 * visible indicator instead of silently downgrading.
 */
export function getDraftClient(
  config: Pick<DraftConfig, 'baseUrl' | 'apiPrefix' | 'jsonApiUrl'>,
  draftData: DraftData,
): CanvasJsonApiClient {
  const token = getSessionToken(draftData);
  if (!token) {
    throw new Error('The draft preview session has expired.');
  }
  return createJsonApiClient({
    ...resolveJsonApiEndpoints(config),
    authentication: {
      type: 'Custom',
      credentials: { value: `${token.tokenType} ${token.value}` },
    },
    resourceVersion: draftData.resourceVersion,
    preview: true,
    // Cache entries are separated per editor session and token lifetime.
    cacheScope: `session:${draftData.sub}:${draftData.tokenExpiresAt}`,
  });
}
