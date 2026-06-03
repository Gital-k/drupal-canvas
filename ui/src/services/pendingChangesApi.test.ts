import { describe, expect, it } from 'vitest';

import {
  applyConflictStateFromResponse,
  CONFLICT_CODE,
} from './pendingChangesApi';

describe('pendingChangesApi conflict normalization', () => {
  it('attaches active conflict metadata from Felix 409 responses with code 4', () => {
    const result = applyConflictStateFromResponse({
      data: {
        'canvas_page:1:en': {
          owner: {
            name: 'Builder',
            avatar: null,
            uri: '/user/1',
            id: 1,
          },
          entity_type: 'canvas_page',
          entity_id: 1,
          data_hash: 'draft-hash',
          langcode: 'en',
          label: 'Homepage',
          updated: 100,
        },
      },
      errors: [
        {
          code: CONFLICT_CODE.DETECTED,
          detail: 'Conflict detected.',
          source: { pointer: 'canvas_page:1:en' },
          meta: {
            api_auto_save_key: 'canvas_page:1:en',
            conflict_id: '7',
          },
        },
      ],
    });

    expect(result['canvas_page:1:en']).toMatchObject({
      hasConflict: true,
      conflict_id: '7',
    });
  });

  it('leaves entries with non-resolvable conflict codes selectable', () => {
    const result = applyConflictStateFromResponse({
      data: {
        'canvas_page:1:en': {
          owner: {
            name: 'Builder',
            avatar: null,
            uri: '/user/1',
            id: 1,
          },
          entity_type: 'canvas_page',
          entity_id: 1,
          data_hash: 'draft-hash',
          langcode: 'en',
          label: 'Homepage',
          updated: 100,
        },
      },
      errors: [
        {
          code: CONFLICT_CODE.EXPECTED,
          detail: 'Conflict is not resolvable by the comparison UI.',
          source: { pointer: 'canvas_page:1:en' },
          meta: {
            api_auto_save_key: 'canvas_page:1:en',
            conflict_id: '7',
          },
        },
      ],
    });

    expect(result['canvas_page:1:en']).toMatchObject({
      hasConflict: false,
      conflict_id: undefined,
    });
  });

  it('leaves entries without a matching conflict selectable', () => {
    const result = applyConflictStateFromResponse({
      data: {
        'canvas_page:2:en': {
          owner: {
            name: 'Builder',
            avatar: null,
            uri: '/user/1',
            id: 1,
          },
          entity_type: 'canvas_page',
          entity_id: 2,
          data_hash: 'clean-hash',
          langcode: 'en',
          label: 'About',
          updated: 100,
        },
      },
      errors: [],
    });

    expect(result['canvas_page:2:en']).toMatchObject({
      hasConflict: false,
      conflict_id: undefined,
    });
  });
});
