import { useRef } from 'react';
import clsx from 'clsx';

import type { Attributes } from '@/types/DrupalAttribute';

import styles from './TextField.module.css';

const TextField = ({
  className = '',
  attributes = {},
}: {
  className?: string;
  attributes?: Attributes;
}) => {
  const renderCountRef = useRef(0);

  renderCountRef.current += 1;

  if (renderCountRef.current > 1) {
    console.log(`[TextField] Re-render #${renderCountRef.current}`);
  }
  return (
    <div className={styles.wrap}>
      <input
        autoComplete="off"
        {...attributes}
        className={clsx(styles.root, className)}
        ref={(element) => {
          if (element && attributes.onChange) {
            // Below is logic that overrides the native setter for the value
            // property, so any programmatic changes to it trigger a change event -
            // something that is needed to update the Redux store and the preview.
            const elementProto = Object.getPrototypeOf(element);
            const descriptor = Object.getOwnPropertyDescriptor(
              elementProto,
              'value',
            );
            if (!descriptor || !descriptor.set) {
              return;
            }
            const originalSetter = descriptor.set;

            Object.defineProperty(element, 'value', {
              get: descriptor.get,
              set: function (newValue) {
                if (attributes?.type === 'number' && newValue === 'NaN') {
                  return;
                }
                // Call the original setter to update the value
                originalSetter.call(this, newValue);
                // Trigger a jQuery change event if jQuery is available, so
                // Drupal states and other jQuery-based logic can respond to the
                // change.
                if (window.jQuery) {
                  const $target = window.jQuery(this);
                  if ($target.length) {
                    $target.trigger('change');
                  }
                }
              },
              configurable: true,
            });
          }
        }}
      />
    </div>
  );
};

export default TextField;
