import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { makeStore } from '@/app/store';
import { initialState } from '@/features/layout/layoutModelSlice';
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

  afterEach(() => {
    vi.unstubAllGlobals();
  });

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
      layoutModel: { present: { ...initialState }, past: [], future: [] },
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

    // Newer operation's response arrives first.
    deferred[1](
      jsonResponse({
        html: 'NEW',
        layout: [],
        model: {},
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
        model: {},
        autoSaves: {},
      }),
    );
    await first;
    await new Promise((r) => setTimeout(r, 50));

    // The stale response must not rewind the preview.
    expect(selectPreviewHtml(store.getState() as any)).toBe('NEW');
  });
});
