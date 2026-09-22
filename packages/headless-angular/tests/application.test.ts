import '@angular/compiler';

import { Subject } from 'rxjs';
import { afterEach, expect, it, vi } from 'vitest';
import {
  createEnvironmentInjector,
  DOCUMENT,
  Injector,
  TransferState,
} from '@angular/core';
import {
  DefaultUrlSerializer,
  NavigationCancel,
  NavigationCancellationCode,
  NavigationEnd,
  NavigationStart,
  Router,
} from '@angular/router';

import { CanvasDocumentHead, CanvasPageStore } from '../src/application';

import type { EnvironmentInjector } from '@angular/core';
import type { Event } from '@angular/router';
import type { CanvasPageData } from '../contracts';

const scopes: EnvironmentInjector[] = [];
afterEach(() => scopes.splice(0).forEach((scope) => scope.destroy()));
const data = (title: string): CanvasPageData => ({
  page: {
    content: null,
    head: { title },
    route: {
      name: 'test',
      requestUri: title,
      params: {},
      managedByCanvas: true,
      entity: null,
    },
  },
  session: {
    enabled: true,
    initialExpired: false,
    tokenExpiresAt: 1000,
    renewUrl: 'https://cms.example/renew',
    editorOrigin: 'https://cms.example',
  },
});
function harness(ignoreAbort = false) {
  const events = new Subject<Event>();
  let current: { id: number } | null = null;
  const requests: {
    path: string;
    signal: AbortSignal;
    resolve: (data: CanvasPageData) => void;
  }[] = [];
  const serializer = new DefaultUrlSerializer();
  const router = {
    events,
    getCurrentNavigation: () => current,
    parseUrl: (url: string) => serializer.parse(url),
    navigateByUrl: vi.fn(async () => true),
  };
  const assign = vi.fn();
  const head = { apply: vi.fn() };
  const scope = createEnvironmentInjector(
    [
      CanvasPageStore,
      { provide: CanvasDocumentHead, useValue: head },
      { provide: Router, useValue: router },
      { provide: TransferState, useValue: new TransferState() },
      {
        provide: DOCUMENT,
        useValue: {
          defaultView: {
            location: { origin: 'https://frontend.example', assign },
            fetch: (url: URL, options: { signal: AbortSignal }) =>
              new Promise<Response>((resolve, reject) => {
                if (!ignoreAbort)
                  options.signal.addEventListener(
                    'abort',
                    () => reject(new DOMException('Aborted', 'AbortError')),
                    { once: true },
                  );
                requests.push({
                  path: url.searchParams.get('path')!,
                  signal: options.signal,
                  resolve: (result) =>
                    resolve(new Response(JSON.stringify(result))),
                });
              }),
          },
        },
      },
    ],
    Injector.NULL as EnvironmentInjector,
  );
  scopes.push(scope);
  const store = scope.get(CanvasPageStore);
  return {
    scope,
    store,
    requests,
    router,
    head,
    assign,
    start(id: number, path: string) {
      current = { id };
      events.next(new NavigationStart(id, path));
    },
    end(id: number, path: string) {
      events.next(new NavigationEnd(id, path, path));
      current = null;
    },
    cancel(id: number, code: NavigationCancellationCode) {
      events.next(new NavigationCancel(id, '/pending', 'test', code));
      current = null;
    },
    async seed() {
      const loaded = store.load('/one');
      requests[0].resolve(data('one'));
      await loaded;
    },
  };
}

it('holds host/renewal refresh behind navigation and commits only on NavigationEnd', async () => {
  const h = harness();
  await h.seed();
  h.start(1, '/second?example=query');
  const loaded = h.store.load('/second?example=query');
  const refreshed = h.store.refresh();
  h.store.setSession({ ...data('one').session, tokenExpiresAt: 9000 });
  expect(h.requests).toHaveLength(2);
  expect(h.requests[1].signal.aborted).toBe(false);
  expect(h.store.path()).toBe('/one');
  expect(h.store.pendingPath()).toBe('/second?example=query');
  h.requests[1].resolve(data('second'));
  await loaded;
  expect(h.store.page()?.head.title).toBe('one');
  expect(h.head.apply).toHaveBeenCalledTimes(1);
  h.end(1, '/second?example=query');
  expect(h.store.session()?.tokenExpiresAt).toBe(9000);
  expect(h.store.page()?.head.title).toBe('second');
  await Promise.resolve();
  expect(h.requests[2].path).toBe('/second?example=query');
  h.requests[2].resolve({
    ...data('fresh second'),
    session: h.store.session()!,
  });
  await refreshed;
  expect(h.store.path()).toBe('/second?example=query');
  expect(h.store.pendingPath()).toBeNull();
  expect(h.store.page()?.head.title).toBe('fresh second');
  expect(h.store.error()).toBeNull();
});

it('discards a canceled navigation response even if transport ignores abort', async () => {
  const h = harness(true);
  await h.seed();
  h.start(1, '/canceled');
  const loaded = h.store.load('/canceled');
  const refreshed = h.store.refresh();
  h.cancel(1, NavigationCancellationCode.GuardRejected);
  await Promise.resolve();
  expect(h.requests[1].signal.aborted).toBe(true);
  expect(h.requests[2].path).toBe('/one');
  h.requests[1].resolve(data('canceled'));
  await loaded;
  expect(h.store.page()?.head.title).toBe('one');
  h.requests[2].resolve(data('fresh one'));
  await refreshed;
  expect(h.store.path()).toBe('/one');
  expect(h.store.pendingPath()).toBeNull();
  expect(h.store.page()?.head.title).toBe('fresh one');
});

it('discards a late refresh when navigation supersedes it, even if transport ignores abort', async () => {
  const h = harness(true);
  await h.seed();
  const refreshed = h.store.refresh();
  h.start(1, '/second');
  const loaded = h.store.load('/second');
  expect(h.requests[1].signal.aborted).toBe(true);
  h.requests[2].resolve(data('second'));
  await loaded;
  h.end(1, '/second');
  h.requests[1].resolve(data('stale one'));
  await refreshed;
  expect(h.store.path()).toBe('/second');
  expect(h.store.page()?.head.title).toBe('second');
});

it('keeps refresh waiting across a resolver redirect chain', async () => {
  const h = harness();
  await h.seed();
  h.start(1, '/redirect');
  const loaded = h.store.load('/redirect');
  const refreshed = h.store.refresh();
  h.requests[1].resolve({
    ...data('ignored'),
    page: { redirect: { url: '/target', external: false, statusCode: 302 } },
  });
  await loaded;
  h.cancel(1, NavigationCancellationCode.Redirect);
  await Promise.resolve();
  expect(h.requests).toHaveLength(2);
  expect(h.store.page()?.head.title).toBe('one');
  h.start(2, '/target');
  const target = h.store.load('/target');
  h.requests[2].resolve(data('target'));
  await target;
  h.end(2, '/target');
  await Promise.resolve();
  expect(h.requests[3].path).toBe('/target');
  h.requests[3].resolve(data('fresh target'));
  await refreshed;
  expect(h.store.path()).toBe('/target');
  expect(h.store.page()?.head.title).toBe('fresh target');
});

it.each([false, true])(
  'follows refresh redirect external=%s without committing Not found',
  async (external) => {
    const h = harness();
    await h.seed();
    const refreshed = h.store.refresh();
    const url = external
      ? 'https://elsewhere.example/target'
      : '/target?query=1';
    h.requests[1].resolve({
      ...data('ignored'),
      page: { redirect: { url, external, statusCode: 302 } },
    });
    await refreshed;
    if (external) {
      expect(h.assign).toHaveBeenCalledWith(url);
      expect(h.router.navigateByUrl).not.toHaveBeenCalled();
    } else {
      expect(h.router.navigateByUrl).toHaveBeenCalledWith(
        h.router.parseUrl(url),
        { replaceUrl: true },
      );
      expect(h.assign).not.toHaveBeenCalled();
    }
    expect(h.store.page()?.head.title).toBe('one');
    expect(h.head.apply).toHaveBeenCalledTimes(1);
  },
);

it('teardown aborts a resolver and releases waiting refresh without starting more requests', async () => {
  const h = harness();
  await h.seed();
  h.start(1, '/pending');
  const loaded = h.store.load('/pending');
  const rejected = expect(loaded).rejects.toMatchObject({ name: 'AbortError' });
  const refreshed = h.store.refresh();
  h.scope.destroy();
  scopes.splice(scopes.indexOf(h.scope), 1);
  await Promise.all([rejected, refreshed]);
  expect(h.requests).toHaveLength(2);
  expect(h.router.events.observed).toBe(false);
});
