import { beforeEach, describe, expect, it, vi } from 'vitest';
import { configureStore } from '@reduxjs/toolkit';

import { componentAndLayoutApi } from '@/services/componentAndLayout';

import type { HostEntityParams } from '@/services/baseQuery';

// Mock the network layer so the test exercises the endpoint's argument handling
// (URL building + cache keying) without a real request. Everything the hoisted
// `vi.mock` factory needs lives inside `vi.hoisted`. The mock derives its
// response from the host query parameters the endpoint built, proving the URL
// is built from the query ARGUMENT (not from global state). Distinct sizes per
// host let the selected list identify which host entry it came from.
const { baseQueryMock } = vi.hoisted(() => {
  const makeComponents = (prefix: string, count: number) =>
    Object.fromEntries(
      Array.from({ length: count }, (_, i) => [
        `${prefix}-${i}`,
        { id: `${prefix}-${i}`, name: `${prefix} ${i}` },
      ]),
    );
  const responses: Record<string, Record<string, unknown>> = {
    canvas_page: makeComponents('page', 7),
    'node:article': makeComponents('node', 8),
    global: makeComponents('global', 5),
  };
  const baseQueryMock = vi.fn(async (arg: string | { url: string }) => {
    const url = typeof arg === 'string' ? arg : arg.url;
    const params = new URL(url, 'http://localhost').searchParams;
    const key = params.get('entity_bundle')
      ? `${params.get('entity_type')}:${params.get('entity_bundle')}`
      : (params.get('entity_type') ?? 'global');
    return { data: responses[key] };
  });
  return { baseQueryMock };
});

vi.mock('@/services/baseQuery', async (importOriginal) => {
  const actual = (await importOriginal()) as Record<string, unknown>;
  return { ...actual, baseQueryWithAutoSaves: baseQueryMock };
});

const makeStore = () =>
  configureStore({
    reducer: {
      [componentAndLayoutApi.reducerPath]: componentAndLayoutApi.reducer,
    },
    middleware: (getDefault) =>
      getDefault().concat(componentAndLayoutApi.middleware),
  });

const getList = (store: ReturnType<typeof makeStore>, host: HostEntityParams) =>
  componentAndLayoutApi.endpoints.getComponents.select(host)(
    store.getState() as any,
  ).data;

beforeEach(() => {
  baseQueryMock.mockClear();
});

describe('getComponents host argument', () => {
  it('sends the host as query parameters built from the argument', async () => {
    const store = makeStore();
    await store.dispatch(
      componentAndLayoutApi.endpoints.getComponents.initiate({
        entityType: 'node',
        entityBundle: 'article',
      }),
    );
    const url = baseQueryMock.mock.calls[0][0] as string;
    expect(url).toContain('entity_type=node');
    expect(url).toContain('entity_bundle=article');
  });

  it('sends no host parameters when there is no host', async () => {
    const store = makeStore();
    await store.dispatch(
      componentAndLayoutApi.endpoints.getComponents.initiate({}),
    );
    expect(baseQueryMock.mock.calls[0][0]).toBe(
      'canvas/api/v0/config/component',
    );
  });

  it('keys the cache by host and returns a prior host from cache on return', async () => {
    const store = makeStore();
    const page: HostEntityParams = { entityType: 'canvas_page' };
    const node: HostEntityParams = {
      entityType: 'node',
      entityBundle: 'article',
    };

    await store.dispatch(
      componentAndLayoutApi.endpoints.getComponents.initiate(page),
    );
    await store.dispatch(
      componentAndLayoutApi.endpoints.getComponents.initiate(node),
    );

    // Two distinct hosts produced two distinct requests and cache entries.
    expect(baseQueryMock).toHaveBeenCalledTimes(2);
    const cacheKeys = Object.keys(
      (store.getState() as any)[componentAndLayoutApi.reducerPath].queries,
    );
    expect(cacheKeys).toHaveLength(2);

    // Each host's cache entry holds that host's list.
    expect(Object.keys(getList(store, page) ?? {})).toHaveLength(7);
    expect(Object.keys(getList(store, node) ?? {})).toHaveLength(8);

    // Returning to the page host is served from its cache — no new request —
    // and yields the page list again (the bug returned the node list here).
    await store.dispatch(
      componentAndLayoutApi.endpoints.getComponents.initiate(page),
    );
    expect(baseQueryMock).toHaveBeenCalledTimes(2);
    expect(Object.keys(getList(store, page) ?? {})).toHaveLength(7);
  });
});
