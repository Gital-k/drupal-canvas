import { Component, input } from '@angular/core';
import { CanvasSlot } from '@drupal-canvas/headless-angular';

@Component({
  selector: 'proof-frame',
  host: { style: 'display: contents' },
  imports: [CanvasSlot],
  template: `<section>
    <h2>{{ title() }}</h2>
    <main><canvas-slot /></main>
    <aside><canvas-slot name="aside" /></aside>
    <footer><canvas-slot name="footer" /></footer>
  </section>`,
})
export default class Frame {
  readonly title = input('Frame');
}
