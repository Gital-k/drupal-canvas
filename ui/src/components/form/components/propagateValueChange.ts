export type PropagateValueChangeOptions<T = any> = {
  property: keyof T;
  afterSet?: (el: T, newValue: any) => void;
  skipSet?: (newValue: any) => boolean;
  fireJQueryChange?: boolean;
};

export type PropagateOnChangeOptions = {
  fireJQueryChange?: boolean;
  value?: any; // Optional value to set before firing event
};

// Enhances an element's property setter so programmatic changes result in
// React and jQuery handling the change as if it were user-initiated.
export function propagateValueChange<T = any>(
  element: T,
  options: PropagateValueChangeOptions<T>,
) {
  if (!element) return;
  const { property, afterSet, skipSet, fireJQueryChange = true } = options;
  const elementProto = Object.getPrototypeOf(element);
  const descriptor = Object.getOwnPropertyDescriptor(elementProto, property);
  if (!descriptor || !descriptor.set) return;
  const originalSetter = descriptor.set;
  Object.defineProperty(element, property, {
    get: descriptor.get,
    set: function (newValue: any) {
      if (skipSet && skipSet(newValue)) return;
      originalSetter.call(this, newValue);
      // Always fire a native change event
      const changeEvent = new Event('change', { bubbles: true });
      Object.defineProperty(changeEvent, 'target', {
        writable: false,
        value: this,
      });
      this.dispatchEvent(changeEvent);
      // Optionally fire jQuery event
      if (fireJQueryChange && typeof window !== 'undefined' && window.jQuery) {
        const $target = window.jQuery(this);
        if ($target.length) {
          $target.trigger('change');
        }
      }
      if (typeof afterSet === 'function') {
        afterSet(this, newValue);
      }
    },
    configurable: true,
  });
}

// Triggers a react-acknowledged change event in the provided element,
// optionally setting a value before dispatching. Also triggers a jQuery change
// event if jQuery is available and enabled via options.
export function propagateOnChange<T extends HTMLElement>(
  element: T,
  options: PropagateOnChangeOptions = {},
) {
  if (!element) return;

  const { fireJQueryChange = true, value } = options;

  // Set value if provided
  if (value !== undefined) {
    (element as any).value = value;
  }

  // Dispatch native change event - this will trigger onChange handlers automatically
  const changeEvent = new Event('change', { bubbles: true });
  element.dispatchEvent(changeEvent);

  // Fire jQuery change if enabled and available
  if (fireJQueryChange && typeof window !== 'undefined' && window.jQuery) {
    const $target = window.jQuery(element);
    if ($target.length) {
      $target.trigger('change');
    }
  }
}
