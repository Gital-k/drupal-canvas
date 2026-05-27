import { describe, expect, it } from 'vitest';

import { applyConflictStateFromResponse } from './pendingChangesApi';

describe('pendingChangesApi conflict normalization', () => {
  it('attaches active conflict metadata from Felix 409 responses without status', () => {
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
          detail: 'Conflict detected.',
          source: { pointer: 'canvas_page:1:en' },
          meta: {
            api_auto_save_key: 'canvas_page:1:en',
            conflict: {
              conflict_id: '7',
              timestamp: 1776892400,
            },
          },
        },
      ],
    });

    expect(result['canvas_page:1:en']).toMatchObject({
      hasConflict: true,
      conflict: {
        conflict_id: '7',
        timestamp: 1776892400000,
      },
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
      conflict: undefined,
    });
  });
});
