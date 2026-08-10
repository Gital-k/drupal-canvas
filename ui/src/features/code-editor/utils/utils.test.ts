import { describe, expect, it } from 'vitest';

import {
  detectValidPropOrSlotChange,
  parsePropValueForPreview,
  serializeProps,
} from './utils';

import type { CodeComponentProp } from '@/types/CodeComponent';

describe('detectValidSlotsOrPropChange', () => {
  it('invalid change when arrays are identical', () => {
    const arr1 = [
      { id: '1', name: 'test' },
      {
        id: '2',
        name: 'test2',
      },
    ];
    const arr2 = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
    ];
    expect(detectValidPropOrSlotChange(arr1, arr2)).toBe(false);
  });

  it('invalid change when the only difference is one additional item with empty name', () => {
    const current = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
      { id: '3', name: '' },
    ];
    const last = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
    ];
    expect(detectValidPropOrSlotChange(current, last)).toBe(false);
  });

  it('invalid change when the only difference is multiple items with empty names', () => {
    const current = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
      { id: '3', name: '' },
      { id: '4', name: '' },
      { id: '5', name: '' },
    ];
    const last = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
    ];
    expect(detectValidPropOrSlotChange(current, last)).toBe(false);
  });

  it('valid change when an item with a name is added', () => {
    const current = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
      { id: '3', name: 'test3' },
    ];
    const last = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
    ];
    expect(detectValidPropOrSlotChange(current, last)).toBe(true);
  });

  it('valid change when an item is removed', () => {
    const current = [{ id: '1', name: 'test' }];
    const last = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
    ];
    expect(detectValidPropOrSlotChange(current, last)).toBe(true);
  });

  it('valid change when an item is updated', () => {
    const current = [
      { id: '1', name: 'newName' },
      { id: '2', name: 'test2' },
    ];
    const last = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
    ];
    expect(detectValidPropOrSlotChange(current, last)).toBe(true);
  });

  it('valid change when empty-named item is added but a name is changed', () => {
    const current = [
      { id: '1', name: 'newName' },
      { id: '2', name: 'test2' },
      { id: '3', name: '' },
    ];
    const last = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
    ];
    expect(detectValidPropOrSlotChange(current, last)).toBe(true);
  });

  it('valid change when empty-named item is added and order of other items is changed', () => {
    const current = [
      { id: '2', name: 'test' },
      { id: '1', name: 'test2' },
      { id: '3', name: '' },
    ];
    const last = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
      { id: '3', name: '' },
    ];
    expect(detectValidPropOrSlotChange(current, last)).toBe(true);
  });

  it('invalid change when position of empty-named item is changed', () => {
    const current = [
      { id: '1', name: 'test' },
      { id: '3', name: '' },
      { id: '2', name: 'test2' },
    ];
    const last = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
      { id: '3', name: '' },
    ];
    expect(detectValidPropOrSlotChange(current, last)).toBe(false);
  });

  it('valid change when item is removed and empty-named item is added', () => {
    const current = [
      { id: '1', name: 'test' },
      { id: '3', name: '' },
    ];
    const last = [
      { id: '1', name: 'test' },
      { id: '2', name: 'test2' },
    ];
    expect(detectValidPropOrSlotChange(current, last)).toBe(true);
  });

  // Preview state is editor-only and never persisted, so picking a preview
  // entity must not mark the component as having unsaved changes.
  it('invalid change when the only difference is preview entity state', () => {
    const last = [
      { id: '1', name: 'author', type: 'content-entity-reference' as const },
    ];
    const current = [
      {
        ...last[0],
        previewEntity: { entityTypeId: 'node', entityId: '1' },
        previewResolvedValue: { owner: { name: 'Bob' } },
      },
    ];
    expect(detectValidPropOrSlotChange(current, last)).toBe(false);
    // Clearing it again is equally uninteresting.
    expect(detectValidPropOrSlotChange(last, current)).toBe(false);
  });

  it('valid change when a real edit accompanies preview entity state', () => {
    const last = [
      { id: '1', name: 'author', type: 'content-entity-reference' as const },
    ];
    const current = [
      {
        ...last[0],
        name: 'renamed',
        previewEntity: { entityTypeId: 'node', entityId: '1' },
      },
    ];
    expect(detectValidPropOrSlotChange(current, last)).toBe(true);
  });
});

describe('content entity reference props', () => {
  const cerProp: CodeComponentProp = {
    id: 'cer-prop',
    name: 'Author',
    type: 'object',
    example: '',
    derivedType: 'contentEntityReference',
    $ref: 'json-schema-definitions://canvas.module/content-entity-reference',
    entityFieldExpressions: ['ℹ︎␜entity:node:article␝title␞␟value'],
  };

  it('parsePropValueForPreview passes the resolved payload through, defaulting to null', () => {
    expect(
      parsePropValueForPreview({
        ...cerProp,
        previewResolvedValue: { __type: 'article', label: 'Hello' },
      }),
    ).toEqual({ __type: 'article', label: 'Hello' });
    // An unset/unresolved reference is null, never undefined: the runtime
    // payload contract for components.
    expect(parsePropValueForPreview(cerProp)).toBeNull();
    expect(
      parsePropValueForPreview({ ...cerProp, previewResolvedValue: null }),
    ).toBeNull();
  });

  it('serializeProps excludes the ephemeral preview state', () => {
    const serialized = serializeProps([
      {
        ...cerProp,
        previewEntity: { entityTypeId: 'node', entityId: '1' },
        previewResolvedValue: { __type: 'article' },
      },
    ]);
    const serializedProp = serialized.author;
    expect(serializedProp).toBeDefined();
    expect(serializedProp).not.toHaveProperty('previewEntity');
    expect(serializedProp).not.toHaveProperty('previewResolvedValue');
    expect(serializedProp).not.toHaveProperty('entityFieldExpressions');
  });
});
