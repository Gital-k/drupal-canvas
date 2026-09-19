// @cspell:ignore Fuser
import { describe, expect, it } from 'vitest';

import {
  isSafeProxyPath,
  mapJsonApiRequestToProxy,
  mapProxyPathToUpstream,
  normalizeBaseUrl,
  resolveJsonApiProxyPath,
  toBackendRelativePath,
} from './jsonapi-proxy';

describe('proxy path resolution', () => {
  it('accepts JSON:API and router paths with optional language prefixes', () => {
    expect(resolveJsonApiProxyPath('/jsonapi/node/article')).toEqual({
      endpoint: 'jsonapi',
      path: '/jsonapi/node/article',
    });
    expect(resolveJsonApiProxyPath('/jsonapi')).toEqual({
      endpoint: 'jsonapi',
      path: '/jsonapi',
    });
    expect(resolveJsonApiProxyPath('/de/jsonapi/node/article')?.endpoint).toBe(
      'jsonapi',
    );
    expect(resolveJsonApiProxyPath('/pt-br/jsonapi/index')?.endpoint).toBe(
      'jsonapi',
    );
    expect(resolveJsonApiProxyPath('/router/translate-path')?.endpoint).toBe(
      'router',
    );
    expect(resolveJsonApiProxyPath('/fr/router/translate-path')?.endpoint).toBe(
      'router',
    );
  });

  it('honors custom prefixes, including multi-segment ones', () => {
    expect(
      resolveJsonApiProxyPath('/api/node/article', { apiPrefix: 'api' })
        ?.endpoint,
    ).toBe('jsonapi');
    expect(
      resolveJsonApiProxyPath('/drupal/api/node/article', {
        apiPrefix: '/drupal/api/',
      })?.endpoint,
    ).toBe('jsonapi');
    expect(
      resolveJsonApiProxyPath('/jsonapi/node/article', { apiPrefix: 'api' }),
    ).toBeNull();
    expect(
      resolveJsonApiProxyPath('/router', { routerPrefix: 'router' })?.endpoint,
    ).toBe('router');
  });

  it('rejects paths outside the boundary', () => {
    expect(resolveJsonApiProxyPath('/oauth/token')).toBeNull();
    expect(resolveJsonApiProxyPath('/user/login')).toBeNull();
    expect(resolveJsonApiProxyPath('/jsonapiX/node')).toBeNull();
    expect(resolveJsonApiProxyPath('/canvas/jsonapi/node')).toBeNull();
    // One leading segment shaped like a Drupal language code is tolerated.
    expect(resolveJsonApiProxyPath('/foo/jsonapi/node')?.endpoint).toBe(
      'jsonapi',
    );
    expect(resolveJsonApiProxyPath('/en/de/jsonapi/node')).toBeNull();
    expect(resolveJsonApiProxyPath('/canvas/api/v0/site-data')).toBeNull();
  });

  it('rejects unsafe paths', () => {
    expect(isSafeProxyPath('jsonapi/node')).toBe(false);
    expect(isSafeProxyPath('//evil.example/jsonapi')).toBe(false);
    expect(isSafeProxyPath('/jsonapi/../oauth/token')).toBe(false);
    expect(isSafeProxyPath('/jsonapi/./node')).toBe(false);
    expect(isSafeProxyPath('/jsonapi//node')).toBe(false);
    expect(isSafeProxyPath('/jsonapi%2F..%2Fuser')).toBe(false);
    expect(isSafeProxyPath('/jsonapi\\node')).toBe(false);
    expect(isSafeProxyPath('/jsonapi/node/')).toBe(true);
  });

  it('normalizes base URLs and splits backend-relative paths', () => {
    expect(normalizeBaseUrl('https://drupal.example/')).toBe(
      'https://drupal.example',
    );
    expect(normalizeBaseUrl('https://drupal.example/sub/?x#y')).toBe(
      'https://drupal.example/sub',
    );
    expect(
      toBackendRelativePath(
        'https://drupal.example/jsonapi/node/article?page[offset]=50',
        'https://drupal.example',
      ),
    ).toEqual({ path: '/jsonapi/node/article', search: '?page[offset]=50' });
    expect(
      toBackendRelativePath(
        'https://drupal.example/sub/jsonapi',
        'https://drupal.example/sub',
      ),
    ).toEqual({ path: '/jsonapi', search: '' });
    expect(
      toBackendRelativePath(
        'https://drupal.example/subway/jsonapi',
        'https://drupal.example/sub',
      ),
    ).toBeNull();
    expect(
      toBackendRelativePath(
        'https://other.example/jsonapi',
        'https://drupal.example',
      ),
    ).toBeNull();
  });

  it('maps upstream URLs to the proxy and back', () => {
    const options = {
      baseUrl: 'https://drupal.example',
      proxyUrl: '/api/canvas/jsonapi',
    };
    const upstream =
      'https://drupal.example/de/jsonapi/node/article?include=field_tags&page[limit]=5';
    const proxied = mapJsonApiRequestToProxy(upstream, options);
    expect(proxied).toBe(
      '/api/canvas/jsonapi/de/jsonapi/node/article?include=field_tags&page[limit]=5',
    );
    expect(
      mapProxyPathToUpstream(
        '/api/canvas/jsonapi/de/jsonapi/node/article',
        options,
      ),
    ).toEqual({
      url: 'https://drupal.example/de/jsonapi/node/article',
      endpoint: 'jsonapi',
    });
    expect(
      mapJsonApiRequestToProxy(
        'https://drupal.example/router/translate-path?path=/about',
        options,
      ),
    ).toBe('/api/canvas/jsonapi/router/translate-path?path=/about');
    expect(
      mapJsonApiRequestToProxy('https://cdn.example/image.png', options),
    ).toBeNull();
    expect(() =>
      mapJsonApiRequestToProxy('https://drupal.example/oauth/token', options),
    ).toThrowError(/not a supported/);
    expect(
      mapProxyPathToUpstream('/api/canvas/jsonapi/oauth/token', options),
    ).toBeNull();
    expect(
      mapProxyPathToUpstream('/other/jsonapi/node/article', options),
    ).toBeNull();
    expect(
      mapProxyPathToUpstream('/api/canvas/jsonapi/jsonapi/../user', options),
    ).toBeNull();
  });
});
