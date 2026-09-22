import {
  afterNextRender,
  Component,
  DestroyRef,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import {
  CanvasComponentTree,
  CanvasDraftSession,
  CanvasPageStore,
} from '@drupal-canvas/headless-angular';
import { createHeadlessPreviewHost } from '@drupal-canvas/headless-host';

import components from './canvas-components.generated';

import type { ElementRef } from '@angular/core';

@Component({
  selector: 'integration-page',
  imports: [CanvasComponentTree, RouterLink],
  template: `<h1>Integrated Canvas page</h1>
    <a
      id="navigate"
      routerLink="/integration/second"
      [queryParams]="{ example: 'query' }"
      >Next page</a
    >
    <a id="redirect" routerLink="/integration/redirect">Redirect</a>
    <a id="missing" routerLink="/integration/missing">Missing</a>
    <form ngNoForm method="POST" action="/api/disable-draft">
      <button id="exit" type="submit">Exit draft</button>
    </form>
    <p id="session">{{ session.expired() ? 'Expired' : 'Active' }}</p>
    @if (store.page(); as page) {
      <h2 id="page-title">{{ page.head.title }}</h2>
      <canvas-component-tree [tree]="page.content" [components]="components" />
    } @else {
      <p id="not-found">Not found</p>
    }
    <button id="entity" (click)="entity()">Load entity</button>
    <p id="entity-result">{{ entityResult() }}</p>`,
})
export class IntegrationPage {
  readonly store = inject(CanvasPageStore);
  readonly session = inject(CanvasDraftSession);
  readonly components = components;
  readonly entityResult = signal('');
  constructor() {
    afterNextRender(() => {
      Object.assign(window, {
        __integration: { store: this.store, session: this.session },
      });
    });
  }
  async entity() {
    this.entityResult.set(
      JSON.stringify(
        await this.store.fetchEntity({
          type: 'node',
          id: '42',
          viewMode: 'teaser',
        }),
      ),
    );
  }
}

@Component({
  selector: 'integration-host',
  template:
    '<h1>Shared Canvas host fixture</h1><button id="host-refresh" (click)="refresh()">Host refresh</button><iframe #preview title="Canvas preview" style="width:100%;height:800px"></iframe>',
})
export class IntegrationHost {
  private readonly frame =
    viewChild.required<ElementRef<HTMLIFrameElement>>('preview');
  private host?: ReturnType<typeof createHeadlessPreviewHost>;
  constructor() {
    const destroy = inject(DestroyRef);
    afterNextRender(() => {
      const events: unknown[] = [];
      const messages: unknown[] = [];
      const listener = (event: MessageEvent) => {
        if (event.source === this.frame().nativeElement.contentWindow)
          messages.push(event.data);
      };
      window.addEventListener('message', listener);
      destroy.onDestroy(() => window.removeEventListener('message', listener));
      this.host = createHeadlessPreviewHost({
        iframe: this.frame().nativeElement,
        frontendOrigin: location.origin,
        draftUrl: location.origin + '/api/draft',
        fetchAssertion: async (params) => {
          const response = await fetch(
            '/test/assertion?' + new URLSearchParams(params),
          );
          return (await response.json()).assertion;
        },
        onEvent: (event) => events.push(event),
      });
      Object.assign(window, {
        __host: this.host,
        __hostEvents: events,
        __hostMessages: messages,
      });
      void this.host.activate({ path: '/integration/one' });
    });
    destroy.onDestroy(() => this.host?.destroy());
  }
  refresh() {
    this.host?.refresh();
  }
}
