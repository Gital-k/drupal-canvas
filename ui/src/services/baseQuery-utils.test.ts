import { describe, expect, it } from 'vitest';

import {
  extractEntityParams,
  getHostEntityParams,
  replaceEntityParamsInUrl,
} from '@/services/baseQuery';

describe('extractEntityParams', () => {
  it('removes query parameters and hash fragments', () => {
    const url = '/canvas/editor/node/1234/?foo=bar#section';
    const result = extractEntityParams(url);
    expect(result).toEqual({ entityType: 'node', entityId: '1234' });
  });

  it('removes hash fragments', () => {
    const url = '/canvas/editor/node/1234#section';
    const result = extractEntityParams(url);
    expect(result).toEqual({ entityType: 'node', entityId: '1234' });
  });

  it('works with template editor URLs', () => {
    const url = '/canvas/template/article/bundle/view/5678?x=1#y';
    const result = extractEntityParams(url);
    expect(result).toEqual({
      entityType: 'article',
      templateBundle: 'bundle',
      templateViewMode: 'view',
      entityId: '5678',
    });
  });

  it('returns undefined for non-matching URLs', () => {
    const url = '/not/a/matching/url?foo=bar#baz';
    const result = extractEntityParams(url);
    expect(result).toEqual({ entityType: undefined, entityId: undefined });
  });

  // The `/canvas` prefix is optional so a basename-stripped react-router
  // `location.pathname` (used by useHostEntityParams) parses the same as a full
  // `window.location.href`.
  it('works with a basename-stripped page editor pathname', () => {
    const result = extractEntityParams('/editor/canvas_page/1');
    expect(result).toEqual({ entityType: 'canvas_page', entityId: '1' });
  });

  it('works with a basename-stripped template editor pathname', () => {
    const result = extractEntityParams('/template/node/article/full/2');
    expect(result).toEqual({
      entityType: 'node',
      templateBundle: 'article',
      templateViewMode: 'full',
      entityId: '2',
    });
  });

  it('works with a basename-stripped pattern editor pathname', () => {
    const result = extractEntityParams('/pattern/123');
    expect(result).toEqual({ entityType: 'pattern', entityId: '123' });
  });
});

describe('getHostEntityParams', () => {
  it('returns the entity type for the page editor (host without a bundle)', () => {
    const result = getHostEntityParams('/canvas/editor/canvas_page/1');
    expect(result).toEqual({
      entityType: 'canvas_page',
      entityBundle: undefined,
    });
  });

  it('returns the entity type and bundle for the template editor', () => {
    const result = getHostEntityParams(
      '/canvas/template/node/article/full/5678',
    );
    expect(result).toEqual({ entityType: 'node', entityBundle: 'article' });
  });

  it('returns no host for the pattern editor', () => {
    expect(getHostEntityParams('/canvas/pattern/abc')).toEqual({});
  });

  it('returns no host for non-matching URLs', () => {
    expect(getHostEntityParams('/not/a/matching/url')).toEqual({});
  });

  // Region editing has no route of its own: it is always nested under an entity
  // or template route, which keeps the host in the leading path segments. So the
  // host resolves to the page/template the region belongs to — no separate
  // handling is needed for regions here.
  it('resolves a page-region route to the page host', () => {
    expect(
      getHostEntityParams('/editor/canvas_page/1/region/content/component/abc'),
    ).toEqual({ entityType: 'canvas_page', entityBundle: undefined });
  });

  it('resolves a template-region route to the template host', () => {
    expect(
      getHostEntityParams('/template/node/article/full/2/region/content'),
    ).toEqual({ entityType: 'node', entityBundle: 'article' });
  });
});

describe('replaceEntityParamsInUrl', () => {
  it('replaces {entity_type} and {entity_id} in the URL', () => {
    const url = '/api/{entity_type}/{entity_id}';
    const result = replaceEntityParamsInUrl(url, 'node', '123');
    expect(result).toBe('/api/node/123');
  });

  it('replaces all template params in the URL', () => {
    const url =
      '/api/{entity_type}/{template_bundle}/{template_view_mode}/{entity_id}';
    const result = replaceEntityParamsInUrl(
      url,
      'article',
      '456',
      'bundle',
      'view',
    );
    expect(result).toBe('/api/article/bundle/view/456');
  });

  it('throws an error if a required param is missing', () => {
    const url = '/api/{entity_type}/{entity_id}';
    expect(() => replaceEntityParamsInUrl(url, undefined, '123')).toThrow();
    expect(() => replaceEntityParamsInUrl(url, 'node', undefined)).toThrow();
  });

  it('returns the URL unchanged if no params are present', () => {
    const url = '/api/static/path';
    const result = replaceEntityParamsInUrl(url);
    expect(result).toBe('/api/static/path');
  });
});
