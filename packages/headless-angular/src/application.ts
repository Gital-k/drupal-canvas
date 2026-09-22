import {
  afterNextRender,
  computed,
  DestroyRef,
  DOCUMENT,
  effect,
  inject,
  Injectable,
  makeEnvironmentProviders,
  makeStateKey,
  provideAppInitializer,
  REQUEST_CONTEXT,
  signal,
  TransferState,
  untracked,
} from '@angular/core';
import { RedirectCommand, Router } from '@angular/router';
import { isPageRedirect, serializeJsonForHtml } from '@drupal-canvas/headless';
import { assertCanvasPath } from '@drupal-canvas/headless-angular/contracts';
import {
  createAsyncRefreshQueue,
  createCanvasGeometryBridge,
  createDraftSession,
  createHeightReporter,
} from '@drupal-canvas/headless/client';

import type { ResolveFn } from '@angular/router';
import type { EntityResult, PageHead } from '@drupal-canvas/headless';
import type {
  CanvasEntityOptions,
  CanvasPageData,
  CanvasRequestContext,
  CanvasSession,
} from '@drupal-canvas/headless-angular/contracts';
import type {
  DraftSession,
  DraftSessionRenewState,
} from '@drupal-canvas/headless/client';

/** Per-Angular-application store: SSR must bootstrap one application per request. */
@Injectable({ providedIn: 'root' })
export class CanvasPageStore {
  private readonly context = inject(REQUEST_CONTEXT, {
    optional: true,
  }) as CanvasRequestContext | null;
  private readonly transfer = inject(TransferState);
  private readonly document = inject(DOCUMENT);
  private readonly head = inject(CanvasDocumentHead);
  private readonly destroy = inject(DestroyRef);
  private controller?: AbortController;
  private readonly entityControllers = new Set<AbortController>();
  private generation = 0;
  private disposed = false;
  readonly path = signal('/');
  readonly data = signal<CanvasPageData | null>(null);
  readonly error = signal<unknown>(null);
  readonly session = computed(() => this.data()?.session ?? null);
  readonly page = computed(() => {
    const page = this.data()?.page;
    return page && !isPageRedirect(page) ? page : null;
  });
  private readonly queue = createAsyncRefreshQueue(
    () => this.load(this.path()),
    (error) => {
      if (
        !this.disposed &&
        !(error instanceof Error && error.name === 'AbortError')
      )
        this.error.set(error);
    },
  );
  constructor() {
    this.destroy.onDestroy(() => {
      this.disposed = true;
      this.controller?.abort();
      for (const controller of this.entityControllers) controller.abort();
    });
  }
  refresh(): Promise<void> {
    return this.disposed ? Promise.resolve() : this.queue.request();
  }
  setSession(session: CanvasSession): void {
    this.data.update((data) => (data ? { ...data, session } : data));
  }
  async load(path: string): Promise<CanvasPageData> {
    assertCanvasPath(path);
    if (this.disposed) throw new Error('Canvas store destroyed');
    this.path.set(path);
    const generation = ++this.generation;
    this.controller?.abort();
    this.controller = new AbortController();
    const key = makeStateKey<CanvasPageData>(`canvas:${path}`);
    let result: CanvasPageData;
    if (this.context?.canvas) {
      if (this.context.canvas.path !== path)
        throw new Error('SSR Canvas context does not match the router path');
      result = this.context.canvas.data;
      // Only the allowlisted data contract crosses hydration, never REQUEST_CONTEXT.
      this.transfer.set(key, result);
    } else if (this.transfer.hasKey(key)) {
      result = this.transfer.get(key, null as unknown as CanvasPageData);
      this.transfer.remove(key);
    } else {
      result = await this.getJson(
        `/api/canvas/page?path=${encodeURIComponent(path)}`,
        this.controller.signal,
      );
    }
    if (!this.disposed && generation === this.generation) {
      this.data.set(result);
      this.error.set(null);
      this.head.apply(
        result.page && !isPageRedirect(result.page)
          ? result.page.head
          : { title: 'Not found' },
      );
    }
    return result;
  }
  async fetchEntity(
    options: CanvasEntityOptions,
  ): Promise<EntityResult | null> {
    if (this.disposed) throw new Error('Canvas store destroyed');
    if (this.context?.canvas) return this.context.canvas.fetchEntity(options);
    const query = new URLSearchParams({ type: options.type, id: options.id });
    if (options.viewMode) query.set('viewMode', options.viewMode);
    const controller = new AbortController();
    this.entityControllers.add(controller);
    try {
      return await this.getJson(
        `/api/canvas/entity?${query}`,
        controller.signal,
      );
    } finally {
      this.entityControllers.delete(controller);
    }
  }
  private async getJson<T>(path: string, signal?: AbortSignal): Promise<T> {
    const view = this.document.defaultView;
    if (!view)
      throw new Error(
        'Canvas SSR requires createCanvasHandler and REQUEST_CONTEXT',
      );
    const response = await view.fetch(new URL(path, view.location.origin), {
      credentials: 'same-origin',
      cache: 'no-store',
      signal,
    });
    if (!response.ok)
      throw new Error(`Canvas data request failed (${response.status})`);
    return response.json();
  }
}

/** Catch-all resolver. SSR redirects/status are handled before Angular rendering. */
export const canvasPageResolver: ResolveFn<
  CanvasPageData | RedirectCommand
> = async (_route, state) => {
  const store = inject(CanvasPageStore);
  const router = inject(Router);
  const document = inject(DOCUMENT);
  const result = await store.load(state.url.split('#')[0]);
  if (result.page && isPageRedirect(result.page)) {
    const target = result.page.redirect.url;
    if (result.page.redirect.external)
      document.defaultView?.location.assign(target);
    else
      return new RedirectCommand(router.parseUrl(target), { replaceUrl: true });
  }
  return result;
};

/** Own only Canvas head nodes. Angular manages body hydration independently. */
@Injectable({ providedIn: 'root' })
export class CanvasDocumentHead {
  private readonly document = inject(DOCUMENT);
  constructor() {
    const originalTitle = this.document.title;
    inject(DestroyRef).onDestroy(() => {
      this.document.head
        .querySelectorAll('[data-canvas-head]')
        .forEach((node) => node.remove());
      this.document.title = originalTitle;
    });
  }
  apply(head: PageHead): void {
    this.document.title = head.title;
    this.document.head
      .querySelectorAll('[data-canvas-head]')
      .forEach((node) => node.remove());
    for (const [tag, values] of [
      ['meta', head.meta],
      ['link', head.link],
    ] as const) {
      for (const attributes of values ?? []) {
        const node = this.document.createElement(tag);
        for (const [name, value] of Object.entries(attributes)) {
          if (
            /^(name|content|property|charset|rel|href|hreflang|type|media|sizes|title|crossorigin|referrerpolicy)$/i.test(
              name,
            )
          )
            node.setAttribute(name, value);
        }
        node.setAttribute('data-canvas-head', '');
        this.document.head.appendChild(node);
      }
    }
    for (const entry of head.script ?? []) {
      const script = this.document.createElement('script');
      script.type = 'application/ld+json';
      script.textContent = serializeJsonForHtml(entry.textContent);
      script.setAttribute('data-canvas-head', '');
      this.document.head.appendChild(script);
    }
  }
}

/** Hydration-safe ownership of the existing host/session/geometry machines. */
@Injectable({ providedIn: 'root' })
export class CanvasDraftSession {
  private readonly store = inject(CanvasPageStore);
  private readonly document = inject(DOCUMENT);
  private readonly ready = signal(false);
  readonly embedded = signal<boolean | null>(null);
  private readonly runtimeExpired = signal(false);
  readonly expired = computed(() =>
    this.ready()
      ? this.runtimeExpired()
      : this.store.session()?.enabled
        ? this.store.session()!.initialExpired
        : false,
  );
  readonly renewState = signal<DraftSessionRenewState>('idle');
  private machine?: DraftSession;
  // Navigation changes path only; it must not reset the session's renewal timer.
  private readonly epoch = computed(() => JSON.stringify(this.store.session()));
  constructor() {
    afterNextRender(() => {
      this.embedded.set(
        this.document.defaultView!.self !== this.document.defaultView!.top,
      );
      this.ready.set(true);
    });
    effect((onCleanup) => {
      const ready = this.ready();
      const session = JSON.parse(this.epoch()) as CanvasSession | null;
      if (!ready || !session?.enabled) {
        this.runtimeExpired.set(false);
        this.renewState.set('idle');
        return;
      }
      const embedded = this.embedded() === true;
      const machine = createDraftSession({
        ...session,
        embedded,
        path: untracked(this.store.path),
        onEvent: (event) => {
          if (event.type === 'refresh-requested') void this.store.refresh();
          else if (event.type === 'renewed') {
            if (event.tokenExpiresAt !== null)
              this.store.setSession({
                ...session,
                tokenExpiresAt: event.tokenExpiresAt,
                initialExpired: false,
              });
            void this.store.refresh();
          } else {
            this.runtimeExpired.set(machine.getState().expired);
            this.renewState.set(machine.getState().renewState);
          }
        },
      });
      this.machine = machine;
      this.runtimeExpired.set(machine.getState().expired);
      this.renewState.set(machine.getState().renewState);
      const height = createHeightReporter({
        editorOrigin: session.editorOrigin,
        embedded,
      });
      const geometry =
        embedded && session.editorOrigin
          ? createCanvasGeometryBridge({ editorOrigin: session.editorOrigin })
          : null;
      onCleanup(() => {
        machine.destroy();
        height.destroy();
        geometry?.destroy();
        this.machine = undefined;
      });
    });
    effect(() => {
      this.machine?.setPath(this.store.path());
    });
  }
}
export function provideCanvas() {
  return makeEnvironmentProviders([
    provideAppInitializer(() => {
      inject(CanvasDraftSession);
    }),
  ]);
}
