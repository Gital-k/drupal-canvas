import { describe, expect, it } from 'vitest';

import { safeSerializeDrupalSettings } from './previewPayload';

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
