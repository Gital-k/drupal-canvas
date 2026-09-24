import { useEffect, useRef, useState } from 'react';
import clsx from 'clsx';

import { useFieldContext } from '@/components/form/contexts/FieldContext';
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

const Checkbox = ({ attributes = {} }: { attributes?: Attributes }) => {
  const incomingChecked = castBoolean(attributes?.checked);
  const [checked, setChecked] = useState(incomingChecked);
  const fieldContext = useFieldContext();

  // Adjust the checked state when this checkbox is re-rendered with a
  // different "checked" attribute than the one it was last rendered with.
  // The page data form is re-fetched after publishing, and without this
  // the state from the previous render would win, leaving the checkbox
  // showing a value that is no longer the stored one.
  const [renderedChecked, setRenderedChecked] = useState(incomingChecked);
  if (incomingChecked !== renderedChecked) {
    setRenderedChecked(incomingChecked);
    setChecked(incomingChecked);
  }

  const inputRef = useRef<HTMLInputElement | null>(null);

  // The input's checked state is uncontrolled (see `defaultChecked` below),
  // so React only applies it once, at mount. When `checked` changes on a
  // later render (e.g. the resync above), push it onto the DOM node
  // directly so the native "checked" property doesn't silently fall out of
  // sync with what's visually shown, which would otherwise make the next
  // click toggle in the wrong direction.
  useEffect(() => {
    const node = inputRef.current;
    if (node && node.checked !== checked) {
      node.checked = checked;
    }
  }, [checked]);

  const handleChange = (e: CheckboxEvent, shimJquery = true) => {
    const newChecked = castBoolean(e.target.checked);
    setChecked(newChecked);
    fieldContext?.triggerChange(newChecked);
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
      onChange={handleChange}
      ref={(node) => {
        inputRef.current = node;
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
              handleChange(e, false);
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
            handleChange(changeEvent as CheckboxEvent);
          },
          fireJQueryChange: false, // We handle jQuery in handleChange
        });
      }}
    />
  );
};

export default Checkbox;
