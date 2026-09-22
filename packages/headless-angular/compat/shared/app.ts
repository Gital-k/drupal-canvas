import {
  afterNextRender,
  Component,
  DestroyRef,
  inject,
  makeStateKey,
  REQUEST,
  signal,
  TransferState,
} from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { CanvasComponentTree } from '@drupal-canvas/headless-angular';
import { createCanvasGeometryObserver } from '@drupal-canvas/headless/client';

import components from './canvas-components.generated';
import { makeTree } from './tree';

import type { CanvasComponentTreeElement } from '@drupal-canvas/headless';

const TREE = makeStateKey<CanvasComponentTreeElement>('public-render-tree');

@Component({
  selector: 'proof-app',
  imports: [RouterOutlet],
  template: '<router-outlet />',
})
export class Shell {}

@Component({
  selector: 'proof-page',
  imports: [CanvasComponentTree],
  template: `<h1>Angular Canvas hydration proof</h1>
    <button id="refresh" (click)="refresh()">Refresh data</button>
    <canvas-component-tree [tree]="tree()" [components]="components" />`,
})
export class App {
  readonly components = components;
  private readonly request = inject(REQUEST);
  private readonly state = inject(TransferState);
  readonly tree = signal(this.initialTree());

  private initialTree(): CanvasComponentTreeElement {
    if (this.request) {
      const tree = makeTree(new URL(this.request.url));
      this.state.set(TREE, tree);
      return tree;
    }
    if (!this.state.hasKey(TREE))
      throw new Error('SSR tree missing: CSR is not a hydration proof');
    const tree = this.state.get(TREE, { element: 'div' });
    this.state.remove(TREE);
    return tree;
  }

  constructor() {
    const destroyRef = inject(DestroyRef);
    afterNextRender(() => {
      const evidence = window as typeof window & {
        __ssrNodes?: Element[];
        __proof?: unknown;
        __geometry?: unknown;
      };
      const nodes = evidence.__ssrNodes;
      if (!nodes?.length) throw new Error('SSR node capture missing');
      const angularNodes = nodes.filter(
        (node) => !['trusted', 'chrome'].includes(node.id),
      );
      evidence.__proof = {
        hydrated: true,
        reused: angularNodes.every((node) => node.isConnected),
        captured: angularNodes.length,
        // Angular's innerHTML binding reassigns raw descendants during hydration.
        replacedMarkup: nodes
          .filter((node) => !node.isConnected)
          .map((node) => node.id),
      };
      const geometry = createCanvasGeometryObserver({
        root: document,
        onChange: (snapshot) => {
          evidence.__geometry = snapshot;
        },
      });
      destroyRef.onDestroy(() => geometry.disconnect());
    });
  }

  async refresh(): Promise<void> {
    const url = new URL('/api/tree', location.origin);
    url.searchParams.set('path', location.pathname);
    url.searchParams.set('revision', '2');
    url.searchParams.set(
      'label',
      new URL(location.href).searchParams.get('label') ?? 'Request',
    );
    const response = await fetch(url);
    if (!response.ok) throw new Error('Refresh failed');
    this.tree.set(await response.json());
  }
}
