import { Component, input, signal } from '@angular/core';

@Component({
  selector: 'proof-counter',
  host: { style: 'display: contents' },
  template:
    '<button (click)="count.update(increment)">{{ label() }}: {{ count() }}</button>',
})
export default class Counter {
  readonly label = input('Counter');
  readonly count = signal(0);
  readonly increment = (value: number) => value + 1;
}
