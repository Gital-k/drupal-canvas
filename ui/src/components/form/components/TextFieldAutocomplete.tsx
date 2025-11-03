import React, {
  forwardRef,
  useEffect,
  useImperativeHandle,
  useRef,
  useState,
} from 'react';
import clsx from 'clsx';

import useMutationObserver from '@/hooks/useMutationObserver';
import { a2p } from '@/local_packages/utils';

import type { Attributes } from '@/types/DrupalAttribute';

import styles from './TextField.module.css';

const { jQuery } = window;

export interface TextFieldAutocompleteHandle {
  setValue: (newValue: string | number | readonly string[] | undefined) => void;
}

const TextFieldAutocomplete = forwardRef<
  TextFieldAutocompleteHandle,
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
  }, [attributes.value]);

  // Expose imperative setValue for programmatic updates
  useImperativeHandle(
    ref,
    () => ({
      setValue: (newValue: string | number | readonly string[] | undefined) => {
        if (
          typeof newValue === 'string' ||
          typeof newValue === 'number' ||
          Array.isArray(newValue) ||
          typeof newValue === 'undefined'
        ) {
          setValue(newValue);
          if (inputRef.current && window.jQuery) {
            const $input = window.jQuery(inputRef.current);
            $input.val(newValue);
            $input.trigger('change');
          }
          if (inputRef.current && typeof attributes.onChange === 'function') {
            const event = new Event('change', { bubbles: true });
            Object.defineProperty(event, 'target', {
              writable: false,
              value: inputRef.current,
            });
            attributes.onChange(event as any);
          }
        }
      },
    }),
    [attributes],
  );

  // This attribute prevents the input from updating the store on change.
  attributes['data-canvas-no-update'] = '';

  // Mutation observer for autocomplete selection
  useMutationObserver(
    inputRef,
    (mutations) => {
      mutations.forEach((record: MutationRecord) => {
        if (record?.attributeName === 'data-canvas-autocomplete-selected') {
          if (
            record.target instanceof HTMLInputElement &&
            record.target.getAttribute('data-canvas-autocomplete-selected')
          ) {
            const selection = record.target.getAttribute(
              'data-canvas-autocomplete-selected',
            );
            if (selection) {
              setValue(selection);
              if (inputRef.current && window.jQuery) {
                const $input = window.jQuery(inputRef.current);
                $input.val(selection);
                $input.trigger('change');
              }
            }
            record.target.removeAttribute('data-canvas-autocomplete-selected');
            const changeEvent = new Event('change');
            Object.defineProperty(changeEvent, 'target', {
              writable: false,
              value: record.target,
            });
            if (typeof attributes.onChange === 'function') {
              attributes.onChange(changeEvent);
            }
          }
        }
      });
    },
    { attributes: true },
  );

  return (
    <div className={clsx(styles.wrap, styles.autocompleteWrap)}>
      <input
        autoComplete="off"
        {...a2p(attributes, {}, { skipAttributes: ['onBlur', 'onChange'] })}
        className={clsx(styles.root, styles.autocomplete, className)}
        ref={inputRef}
        value={value}
        onChange={(e) => {
          setValue(e.target.value);
          if (inputRef.current) {
            inputRef.current.setAttribute('data-canvas-no-update', 'true');
          }
          if (typeof attributes.onChange === 'function') {
            attributes.onChange(e);
          }
          const autocompleteDelay =
            inputRef.current &&
            !!jQuery.data(inputRef.current, 'ui-autocomplete')
              ? jQuery(inputRef.current).autocomplete(
                  'option',
                  'autocompleteDelay',
                )
              : 400;
          setTimeout(() => {
            if (
              inputRef.current &&
              !inputRef.current.hasAttribute('data-canvas-no-update') &&
              typeof attributes.onChange === 'function'
            ) {
              attributes.onChange(e);
            }
          }, autocompleteDelay * 3);
        }}
        onBlur={(e) => {
          if (inputRef.current) {
            inputRef.current.removeAttribute('data-canvas-no-update');
          }
          if (typeof attributes.onChange === 'function') {
            attributes.onChange(e);
          }
          if (typeof attributes.onBlur === 'function') {
            attributes.onBlur(e);
          }
        }}
      />
    </div>
  );
});

export default TextFieldAutocomplete;
