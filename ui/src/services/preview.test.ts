import { beforeEach, describe, expect, it, vi } from 'vitest';

import { makeStore } from '@/app/store';
import {
  initialState,
  selectLayoutHistory,
} from '@/features/layout/layoutModelSlice';
import { selectPreviewHtml } from '@/features/pagePreview/previewSlice';
import { initialState as uiInitialState } from '@/features/ui/uiSlice';
import { previewApi } from '@/services/preview';

const jsonResponse = (payload: object) => ({
  ok: true,
  status: 200,
  headers: new Headers({ 'Content-Type': 'application/json' }),
  text: async () => JSON.stringify(payload),
  json: async () => payload,
  clone() {
    return this;
  },
});

describe('updateComponent response ordering', () => {
  beforeEach(() => {
    window.history.pushState({}, '', '/canvas/editor/node/1');
  });

  // TRICKY: fetch stays stubbed for the whole file (vitest isolates globals
  // per test file): RTK Query can fire follow-up requests in microtasks after
  // a test ends, and removing the stub would let those hit the real network, failing
  // the run with an unhandled rejection on machines without network access.

  it('drops a stale response that resolves after a newer operation', async () => {
    // Two PATCH round-trips whose responses we resolve manually, so the
    // older operation's response can arrive after the newer one.
    const deferred: Array<(r: unknown) => void> = [];
    vi.stubGlobal(
      'fetch',
      vi.fn().mockImplementation((input: Request | string) => {
        const url = typeof input === 'string' ? input : input.url;
        if (url.includes('session/token')) {
          return Promise.resolve({ ok: true, text: async () => 'token' });
        }
        return new Promise((resolve) => {
          deferred.push(resolve);
        });
      }),
    );

    const store = makeStore({
      layoutModel: {
        present: {
          ...initialState,
          model: { existing: { resolved: { text: 'original' } } },
        },
        past: [],
        future: [],
      },
      ui: uiInitialState,
      configuration: {
        baseUrl: 'https://example.com/',
        entityType: 'node',
        entity: '1',
        isNew: false,
        isPublished: true,
        devMode: false,
        homepageStagedConfigExists: false,
      },
    });

    const patch = (n: number) =>
      store.dispatch(
        previewApi.endpoints.updateComponent.initiate({
          operation: 'update-model',
          componentInstanceUuid: `uuid-${n}`,
          componentType: 'sdc.test@x',
          model: { resolved: { text: `edit ${n}` } },
        }) as any,
      );

    const first = patch(1);
    const second = patch(2);
    // Wait for both fetches to be issued (CSRF token fetches resolve
    // immediately; the PATCH bodies park in `deferred`).
    await vi.waitFor(() => {
      expect(deferred.length).toBe(2);
    });

    // Newer operation's response arrives first, echoing the full model as
    // the server does.
    deferred[1](
      jsonResponse({
        html: 'NEW',
        layout: [],
        model: {
          existing: { resolved: { text: 'original' } },
          'uuid-1': { resolved: { text: 'edit 1' } },
          'uuid-2': { resolved: { text: 'edit 2' } },
        },
        autoSaves: {},
      }),
    );
    await second;
    await vi.waitFor(() => {
      expect(selectPreviewHtml(store.getState() as any)).toBe('NEW');
    });
    // Older operation's response straggles in afterwards.
    deferred[0](
      jsonResponse({
        html: 'STALE',
        layout: [],
        model: {
          existing: { resolved: { text: 'original' } },
          'uuid-1': { resolved: { text: 'edit 1' } },
        },
        autoSaves: {},
      }),
    );
    await first;
    await new Promise((r) => setTimeout(r, 50));

    // The stale response must not rewind the preview.
    expect(selectPreviewHtml(store.getState() as any)).toBe('NEW');

    // Only the surviving (newest) response created an undo entry from its
    // echo; the dropped stale response must not add one.
    const history = selectLayoutHistory(store.getState() as any);
    expect(history.past.length).toBe(1);
    expect((history.present.model as any)['uuid-2']).toEqual({
      resolved: { text: 'edit 2' },
    });
  });

  it('leaves the model and undo history untouched when the server rejects an edit', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockImplementation((input: Request | string) => {
        const url = typeof input === 'string' ? input : input.url;
        if (url.includes('session/token')) {
          return Promise.resolve({ ok: true, text: async () => 'token' });
        }
        return Promise.resolve({
          ok: false,
          status: 422,
          headers: new Headers({ 'Content-Type': 'application/json' }),
          text: async () => JSON.stringify({ message: 'The URI is invalid.' }),
          json: async () => ({ message: 'The URI is invalid.' }),
          clone() {
            return this;
          },
        });
      }),
    );

    const store = makeStore({
      layoutModel: {
        present: {
          ...initialState,
          model: { existing: { resolved: { url: 'https://drupal.org' } } },
        },
        past: [],
        future: [],
      },
      ui: uiInitialState,
      configuration: {
        baseUrl: 'https://example.com/',
        entityType: 'node',
        entity: '1',
        isNew: false,
        isPublished: true,
        devMode: false,
        homepageStagedConfigExists: false,
      },
    });

    await store.dispatch(
      previewApi.endpoints.updateComponent.initiate({
        operation: 'update-model',
        componentInstanceUuid: 'existing',
        componentType: 'sdc.test@x',
        model: { resolved: { url: 'ttp://google.com' } },
      }) as any,
    );

    const history = selectLayoutHistory(store.getState() as any);
    expect(history.past.length).toBe(0);
    expect((history.present.model as any).existing).toEqual({
      resolved: { url: 'https://drupal.org' },
    });
  });
});
