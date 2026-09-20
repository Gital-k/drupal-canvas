import { describe, expect, it } from 'vitest';

import {
  getDraftClient,
  getPublicClient,
  resolveJsonApiEndpoints,
  resolveJsonApiRuntimeConfig,
  resolveJsonApiUrl,
} from './json-api-client';

import type { DraftData } from '../draft-data';

function liveDraftData(overrides: Partial<DraftData> = {}): DraftData {
  return {
    path: '/node/9',
    resourceVersion: 'rel:working-copy',
    sub: '42',
    renewUrl: 'https://drupal.example/canvas-headless/renew',
    accessToken: 'secret-token',
    tokenType: 'Bearer',
    tokenExpiresAt: Date.now() + 600_000,
    codeVerifier: 'stored-verifier',
    ...overrides,
  };
}

describe('JSON:API client factories', () => {
  it('resolves endpoints with an explicit full URL override winning', () => {
    expect(
      resolveJsonApiEndpoints({
        baseUrl: 'https://drupal.example',
        apiPrefix: 'api',
      }),
    ).toEqual({ baseUrl: 'https://drupal.example', apiPrefix: 'api' });
    expect(
      resolveJsonApiEndpoints({
        baseUrl: 'https://drupal.example',
        apiPrefix: 'api',
        jsonApiUrl: 'https://cdn.example/drupal/jsonapi/',
      }),
    ).toEqual({ baseUrl: 'https://cdn.example', apiPrefix: 'drupal/jsonapi' });
    expect(
      resolveJsonApiUrl({
        baseUrl: 'https://drupal.example',
        apiPrefix: 'api',
      }),
    ).toBe('https://drupal.example/api');
    expect(resolveJsonApiUrl({ baseUrl: 'https://drupal.example' })).toBe(
      'https://drupal.example/jsonapi',
    );
  });

  it('creates public and draft clients on the shared implementation', () => {
    const publicClient = getPublicClient({
      baseUrl: 'https://drupal.example',
      apiPrefix: 'api',
    });
    expect(publicClient.baseUrl).toBe('https://drupal.example');
    expect(publicClient.apiPrefix).toBe('api');
    expect(publicClient.resourceVersion).toBeNull();
    expect(publicClient.authentication).toBeUndefined();
    expect(publicClient.serializer).toBeDefined();

    const draftClient = getDraftClient(
      { baseUrl: 'https://drupal.example' },
      liveDraftData(),
    );
    expect(draftClient.resourceVersion).toBe('rel:working-copy');
    expect(draftClient.authentication).toEqual({
      type: 'Custom',
      credentials: { value: 'Bearer secret-token' },
    });
    expect(draftClient.runtimeConfig.preview).toBe(true);
    expect(() =>
      getDraftClient(
        { baseUrl: 'https://drupal.example' },
        liveDraftData({ tokenExpiresAt: Date.now() - 1 }),
      ),
    ).toThrow('expired');
  });

  it('builds nonsecret runtime configuration for the browser client', () => {
    const config = {
      baseUrl: 'https://drupal.example',
      apiPrefix: 'api',
      jsonApiProxyPath: '/api/canvas/jsonapi',
    };
    expect(resolveJsonApiRuntimeConfig(config, null)).toEqual({
      baseUrl: 'https://drupal.example',
      apiPrefix: 'api',
      proxyUrl: '/api/canvas/jsonapi',
      resourceVersion: null,
      preview: false,
    });
    const live = resolveJsonApiRuntimeConfig(config, liveDraftData());
    expect(live).toEqual({
      baseUrl: 'https://drupal.example',
      apiPrefix: 'api',
      proxyUrl: '/api/canvas/jsonapi',
      resourceVersion: 'rel:working-copy',
      preview: true,
    });
    expect(JSON.stringify(live)).not.toContain('secret-token');
    expect(
      resolveJsonApiRuntimeConfig(
        config,
        liveDraftData({ tokenExpiresAt: Date.now() - 1 }),
      ),
    ).toMatchObject({ resourceVersion: null, preview: false });
  });
});
