import { describe, expect, it } from 'vitest';

import { stripNullableKeysForRegion } from './prepare-regions-push';

describe('stripNullableKeysForRegion', () => {
  it('omits null parent_uuid, slot, and label for root-level components', () => {
    const stripped = stripNullableKeysForRegion([
      {
        uuid: 'root-uuid',
        component_id: 'js.hero',
        component_version: 'v1',
        inputs: { heading: 'Welcome' },
        parent_uuid: null,
        slot: null,
        label: null,
      },
    ]);

    expect(stripped).toEqual([
      {
        uuid: 'root-uuid',
        component_id: 'js.hero',
        component_version: 'v1',
        inputs: { heading: 'Welcome' },
      },
    ]);
  });

  it('preserves parent_uuid and slot for nested components, drops null label', () => {
    const stripped = stripNullableKeysForRegion([
      {
        uuid: 'child',
        component_id: 'js.button',
        component_version: 'v1',
        inputs: {},
        parent_uuid: 'parent',
        slot: 'actions',
        label: null,
      },
    ]);

    expect(stripped).toEqual([
      {
        uuid: 'child',
        component_id: 'js.button',
        component_version: 'v1',
        inputs: {},
        parent_uuid: 'parent',
        slot: 'actions',
      },
    ]);
  });

  it('keeps non-empty label', () => {
    const stripped = stripNullableKeysForRegion([
      {
        uuid: 'node',
        component_id: 'js.heading',
        component_version: 'v1',
        inputs: {},
        parent_uuid: null,
        slot: null,
        label: 'Main heading',
      },
    ]);

    expect(stripped[0].label).toBe('Main heading');
  });
});
