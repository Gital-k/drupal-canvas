import { describe, expect, it } from 'vitest';

import {
  isAllowedPreviewFetchUrl,
  safeSerializeDrupalSettings,
} from './previewPayload';

describe('safeSerializeDrupalSettings', () => {
  it('returns a plain-data object unchanged', () => {
    const value = { path: { baseUrl: '/' }, user: { uid: 1 } };
    expect(safeSerializeDrupalSettings(value)).toEqual(value);
  });

  it('strips a non-cloneable property instead of throwing', () => {
    const value = { path: { baseUrl: '/' }, onClose: () => {} };
    expect(safeSerializeDrupalSettings(value)).toEqual({
      path: { baseUrl: '/' },
    });
  });

  it('returns an empty object when the value cannot be JSON-serialized at all', () => {
    const circular: Record<string, unknown> = {};
    circular.self = circular;
    expect(safeSerializeDrupalSettings(circular)).toEqual({});
  });
});

describe('isAllowedPreviewFetchUrl', () => {
  it.each([
    '/jsonapi/menu_items/main',
    '/jsonapi/node/page',
    'https://example.com/jsonapi/media/image',
    '/system/menu/main/linkset',
    '/system/menu/footer/linkset',
  ])('allows %s', (url) => {
    expect(isAllowedPreviewFetchUrl(url)).toBe(true);
  });

  it.each([
    '/user/login',
    '/admin/config/system/site-information',
    '/system/menu/main/linkset/extra',
    '/system/menu//linkset',
    'not a url',
  ])('rejects %s', (url) => {
    expect(isAllowedPreviewFetchUrl(url)).toBe(false);
  });
});
