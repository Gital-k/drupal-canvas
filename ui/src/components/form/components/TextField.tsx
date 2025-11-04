import {
  forwardRef,
  useEffect,
  useImperativeHandle,
  useRef,
  useState,
} from 'react';
import clsx from 'clsx';

import type { Attributes } from '@/types/DrupalAttribute';

import styles from './TextField.module.css';

export interface TextFieldHandle {
  setValue: (newValue: string | number | readonly string[] | undefined) => void;
}

const TextField = forwardRef<
  TextFieldHandle,
  {
    className?: string;
    attributes?: Attributes;
  }
>(({ className = '', attributes = {} }, ref) => {
  const inputRef = useRef<HTMLInputElement>(null);
  const [value, setValue] = useState<
    string | number | readonly string[] | undefined
  >(
    typeof attributes.value === 'string' ||
      typeof attributes.value === 'number' ||
      Array.isArray(attributes.value)
      ? attributes.value
      : '',
  );

  // Keep local state in sync with external value prop
  useEffect(() => {
    if (
      (typeof attributes.value === 'string' ||
        typeof attributes.value === 'number' ||
        Array.isArray(attributes.value)) &&
      attributes.value !== value
    ) {
      setValue(attributes.value);
    }
  }, [attributes.value, value]);

  useImperativeHandle(
    ref,
    () => ({
      setValue: (newValue: string | number | readonly string[] | undefined) => {
        // Only allow valid types for input value
        if (
          typeof newValue === 'string' ||
          typeof newValue === 'number' ||
          Array.isArray(newValue) ||
          typeof newValue === 'undefined'
        ) {
          setValue(newValue);
          // Fire jQuery change event for Drupal compatibility
          if (inputRef.current && window.jQuery) {
            const $input = window.jQuery(inputRef.current);
            $input.val(newValue);
            $input.trigger('change');
          }
          // Also call onChange if provided
          if (inputRef.current && typeof attributes.onChange === 'function') {
            const event = new Event('change', { bubbles: true });
            Object.defineProperty(event, 'target', {
              writable: false,
              value: inputRef.current,
            });
            attributes.onChange(event as any);
          }
        } else {
          // Ignore invalid types
          // Optionally, log a warning here
        }
      },
    }),
    [attributes],
  );

  return (
    <div className={styles.wrap}>
      <input
        autoComplete="off"
        {...attributes}
        className={clsx(styles.root, className)}
        ref={inputRef}
        value={value}
        onChange={(e) => {
          setValue(e.target.value);
          if (typeof attributes.onChange === 'function') {
            attributes.onChange(e);
          }
        }}
      />
    </div>
  );
});

export default TextField;
