// Utilities for handling form value changes in Canvas
//
// interceptNativeSetter: Override native property setter to intercept programmatic DOM changes
// Usage: interceptNativeSetter(element, { property: 'value', afterSet: (el, newValue) => { ... } });
//
// triggerAttributesOnChange: Fallback for triggering attributes.onChange when FieldContext isn't available
// Usage: triggerAttributesOnChange(attributes, { value: newValue, target: element });
//
// Note: Prefer using FieldContext.triggerChange() when available. These utilities are for:
// - interceptNativeSetter: Base components that need to intercept vanilla JS/jQuery DOM manipulation
// - triggerAttributesOnChange: Edge cases where FieldContext isn't available

export type InterceptNativeSetterOptions<T = any> = {
  property: keyof T;
  afterSet?: (el: T, newValue: any) => void;
  skipSet?: (newValue: any) => boolean;
  fireJQueryChange?: boolean;
};

export type TriggerAttributesOnChangeOptions = {
  value?: any;
  target?: HTMLElement;
};

/**
 * Intercepts an element's native property setter so programmatic changes
 * (e.g., element.value = 'foo' from vanilla JS or jQuery) result in React
 * and jQuery handling the change as if it were user-initiated.
 *
 * Used by base components (TextField, Select, Checkbox, Toggle) to ensure
 * external code that manipulates DOM values triggers the form system.
 */
export function interceptNativeSetter<T = any>(
  element: T,
  options: InterceptNativeSetterOptions<T>,
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

/**
 * Fallback utility for directly triggering attributes.onChange when
 * FieldContext isn't available.
 *
 * Prefer using FieldContext.triggerChange() when possible. This is useful for:
 * - Components that may be used outside of wrapRHF context
 * - Edge cases where context isn't accessible
 */
export function triggerAttributesOnChange(
  attributes?: { onChange?: (e: Event) => void },
  options: TriggerAttributesOnChangeOptions = {},
) {
  // Built-in falsy check - no need for components to check
  if (!attributes?.onChange || typeof attributes.onChange !== 'function') {
    return;
  }

  const { value, target } = options;

  // Create synthetic change event
  const syntheticEvent = new Event('change', { bubbles: true });

  // Set up target with value if provided
  if (target) {
    if (value !== undefined) {
      (target as any).value = value;
    }

    Object.defineProperty(syntheticEvent, 'target', {
      writable: false,
      value: target,
    });
  }

  // Call the onChange handler
  attributes.onChange(syntheticEvent);
}
