/**
 * @file
 * Resolves the site's JSON:API prefix from the Canvas Headless module's public
 * JSON:API settings endpoint (`/canvas/api/v0/headless/jsonapi-settings`),
 * which reports the site's configured `jsonapi.base_path`.
 */

import type { DraftConfig } from './config';

/**
 * Creates a resolver for the site's JSON:API prefix.
 *
 * The prefix is fetched once per server instance and cached forever — it is
 * site configuration, not request state, and cannot change at runtime.
 * Concurrent first calls share the single in-flight fetch.
 *
 * Resolution order:
 * 1. The endpoint's `apiPrefix` when reachable.
 * 2. The configured fallback (CANVAS_JSONAPI_PREFIX or an explicit override)
 *    when the fetch fails — a site running a Canvas version without the
 *    endpoint still gets a working client via the fallback.
 * 3. undefined, leaving the JSON:API client's `/jsonapi` default in place.
 */
export function createApiPrefixResolver(
  config: DraftConfig,
  fetchImpl: typeof fetch = fetch,
): () => Promise<string | undefined> {
  let resolved: Promise<string | undefined> | null = null;

  return () => {
    resolved ??= (async () => {
      const url = `${config.baseUrl}/canvas/api/v0/headless/jsonapi-settings`;
      try {
        const response = await fetchImpl(url, {
          headers: { Accept: 'application/json' },
          cache: 'no-store',
        });
        if (!response.ok) {
          console.warn(
            `[drupal-canvas] Canvas JSON:API settings endpoint returned HTTP ${response.status} — ` +
              'falling back to the configured JSON:API prefix.',
          );
          return config.apiPrefix;
        }
        const data = (await response.json()) as {
          apiPrefix?: string;
        } | null;
        // A null payload means JSON:API is not installed on the site — no
        // prefix (fetched or configured) will make requests succeed, so
        // leave the client's default in place.
        return data?.apiPrefix;
      } catch (error) {
        console.warn(
          `[drupal-canvas] Failed to fetch Canvas JSON:API settings — ` +
            `falling back to the configured JSON:API prefix. ${
              error instanceof Error ? error.message : String(error)
            }`,
        );
        return config.apiPrefix;
      }
    })();
    return resolved;
  };
}
