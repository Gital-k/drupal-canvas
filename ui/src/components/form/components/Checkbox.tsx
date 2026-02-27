import { useState } from 'react';
import clsx from 'clsx';

import { a2p } from '@/local_packages/utils';

import { interceptNativeSetter } from './formChangeUtils';

import type { Attributes } from '@/types/DrupalAttribute';

import styles from './Checkbox.module.css';

interface CheckboxTarget extends EventTarget {
  checked: boolean;
}

interface CheckboxEvent extends Event {
  target: CheckboxTarget;
}

interface JQueryProxyCheckboxEvent extends CheckboxEvent {
  detail?: {
    jqueryProxy?: boolean;
  };
}

// The checked property might be a string, and checked might be represented as
// 'true' or 'checked'. This normalizes it to a boolean value, which is required
// by React for its handling of 'checked'.
const castBoolean = (value: unknown): boolean => {
  if (typeof value === 'string') {
    return value === 'true' || value === 'checked';
  }
  return !!value;
};

const Checkbox = ({
  attributes = {},
}: {
  attributes?: Attributes & {
    onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
  };
}) => {
  const [checked, setChecked] = useState(castBoolean(attributes?.checked));

  const changeCallback = (
    e: CheckboxEvent | JQueryProxyCheckboxEvent,
    shimJquery = true,
  ) => {
    setChecked(castBoolean(e.target.checked));
    const syntheticEvent = {
      target: {
        checked: e.target.checked,
        value: e.target.checked,
        name: attributes?.name || 'noop',
      },
    } as unknown as React.ChangeEvent<HTMLInputElement>;
    attributes?.onChange?.(syntheticEvent);
    // If jQuery is available, and we haven't explicitly instructed otherwise,
    // trigger a jQuery change event.
    if (shimJquery && window.jQuery) {
      const $target = window.jQuery(e.target);
      if ($target.length) {
        $target.trigger('change');
      }
    }
  };

  return (
    <input
      {...a2p(attributes, {}, { skipAttributes: ['checked', 'value'] })}
      defaultChecked={checked}
      className={clsx(attributes.class, styles.base, checked && styles.checked)}
      onChange={changeCallback}
      ref={(node) => {
        if (!node) {
          return;
        }
        node.addEventListener('change', ((e: JQueryProxyCheckboxEvent) => {
          // Some Drupal APIs use jQuery to change checkbox values, which are
          // acknowledged by the onChange listener, so those dispatches are
          // rerouted here.
          // @see jquery.overrides.js
          if (e?.detail?.jqueryProxy && e.target) {
            if (e.target.checked !== checked) {
              changeCallback(e, false);
            }
          }
        }) as EventListener);

        // Override the native setter for the checked property using our utility
        interceptNativeSetter(node, {
          property: 'checked',
          skipSet: (newValue: boolean) =>
            castBoolean(node.checked) === castBoolean(newValue),
          afterSet: (element: HTMLInputElement) => {
            const changeEvent = new Event('change');
            Object.defineProperty(changeEvent, 'target', {
              writable: false,
              value: element,
            });
            changeCallback(changeEvent as CheckboxEvent);
          },
          fireJQueryChange: false, // We handle jQuery in changeCallback
        });
      }}
    />
  );
};

export default Checkbox;
