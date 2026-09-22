# @drupal-canvas/headless-angular

Milestone 1: Angular renderer and packaged-artifact compatibility proof. This is
not yet a complete Angular framework adapter or a production starter.

```ts
import { Component, input } from '@angular/core';
import {
  CanvasComponentTree,
  CanvasSlot,
} from '@drupal-canvas/headless-angular';

@Component({
  selector: 'app-section',
  imports: [CanvasSlot],
  template:
    '<section [class.wide]="width() === \'wide\'"><canvas-slot name="content" /></section>',
})
export default class Section {
  readonly width = input('normal');
}
```

Use `<canvas-component-tree [tree]="page.content" [components]="components" />`.
Registry keys and YAML are unchanged. Components have ordinary Angular inputs;
Canvas UUID metadata is stripped. `<canvas-slot />` renders the default slot;
`<canvas-slot name="content" />` renders a named slot using a node-local
injector. Slot children belong to Angular views, not manually projected DOM.
Slots must be inside a registry component rendered by this renderer. Render each
slot once.

Only pass trusted Drupal-rendered trees: markup deliberately bypasses Angular's
HTML sanitization, matching the other Canvas renderers. Do not pass user input.
Import `@drupal-canvas/headless/preview.css` in your app's global stylesheet.
Draft trees emit shared template markers and empty drop targets; published trees
do not. Angular component hosts use `display: contents` but remain in the DOM:
port direct-child CSS selectors with that in mind. App component hosts remain
app-owned. Use `host: { style: 'display: contents' }` on ports whose original
component had no extra box; otherwise style the host deliberately (default
inline custom-element boxes can yield incorrect editor geometry). There is no
`ngSkipHydration` or private Angular API usage.

Build with Angular 21 and TypeScript 5.9
(`npm run build -w @drupal-canvas/headless-angular`). Pack **dist**, not the
source directory: `npm pack ./packages/headless-angular/dist`. Angular's partial
declarations are linked by the consumer's Angular compiler. Compatibility
consumers live under `compat`; neither upgrades the monorepo TypeScript version.
See `packages/headless-angular/compat/README.md` in the repository for
independent Angular 21 and 22 configurations, exact versions, commands, and
limitations.

## Build integration

Angular CLI's internal Vite server is not a public Vite plugin host. Generate a
real TypeScript registry before `ng build`/`ng serve` instead of injecting the
existing virtual-module plugin. The package's `tools/generate-registry.mjs` uses
the shared discovery/source generator and supports `index.ts` default exports.
Component add/remove/rename requires regeneration (automatic watching is
deferred).

## Scope boundary

Draft HTTP routes, server cookie adaptation, metadata authentication, client
session binding, thumbnail routes, routing/head integration and starter
component ports are follow-up work. They must reuse the shared server flows,
cookie builders, client lifecycle, geometry and host protocol; do not serialize
tokens or server configuration into Angular TransferState. This renderer stores
no global request state. The compatibility fixture transfers only its public
rendering tree.
