import { useRef } from 'react';
import clsx from 'clsx';

import useMutationObserver from '@/hooks/useMutationObserver';
import { a2p } from '@/local_packages/utils';

import type { Attributes } from '@/types/DrupalAttribute';

import styles from './TextField.module.css';

const { jQuery } = window;

const TextFieldAutocomplete = ({
  className = '',
  attributes = {},
}: {
  className?: string;
  attributes?: Attributes;
}) => {
  // This attribute prevents the input from updating the store on change.
  // Without this, autocomplete search results will disappear moments after
  // they appear due to the component rerendering on value change.
  // The attribute is removed when a suggestion is picked, or the input is
  // blurred.
  // @see InputBehaviorsCommon in inputBehaviors.tsx where attributes.onChange
  // is defined.
  attributes['data-canvas-no-update'] = '';

  const inputRef = useRef<HTMLInputElement>(null);
  const delayedChangeTimerRef = useRef<number | null>(null);

  // This mutation observer responds to the addition of a
  // 'data-canvas-autocomplete-selected' attribute, which will have the value of the
  // chosen autocomplete suggestion.
  useMutationObserver(
    inputRef,
    (mutations) => {
      mutations.forEach((record: MutationRecord) => {
        if (record?.attributeName === 'data-canvas-autocomplete-selected') {
          if (
            record.target instanceof HTMLInputElement &&
            record.target.getAttribute('data-canvas-autocomplete-selected')
          ) {
            if (delayedChangeTimerRef.current !== null) {
              window.clearTimeout(delayedChangeTimerRef.current);
              delayedChangeTimerRef.current = null;
            }
            // Popover-managed multivalue inputs handle autocomplete selection
            // commits in DrupalInputMultivalueForm to avoid race conditions.
            if (
              record.target.hasAttribute('data-canvas-popover-autocomplete')
            ) {
              record.target.removeAttribute(
                'data-canvas-autocomplete-selected',
              );
              return;
            }
            const selection = record.target.getAttribute(
              'data-canvas-autocomplete-selected',
            );
            if (selection) {
              record.target.value = selection;
            }

            // Remove the attribute to prevent multiple attempts to update the
            // store with the same value.
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
        ref={(node) => {
          if (node) {
            // @ts-ignore
            inputRef.current = node;
          }
        }}
        onChange={(e) => {
          const shouldForceUpdate =
            inputRef.current?.hasAttribute('data-canvas-force-update') ?? false;

          if (shouldForceUpdate && inputRef.current) {
            inputRef.current.removeAttribute('data-canvas-force-update');
            inputRef.current.removeAttribute('data-canvas-no-update');
            if (typeof attributes.onChange === 'function') {
              attributes.onChange(e);
            }
            return;
          }

          // Default to setting the attribute that prevents preview and store
          // updates.
          if (inputRef.current) {
            inputRef.current.setAttribute('data-canvas-no-update', 'true');
          }
          // Call the onChange listener, which will update the UI but not the
          // store or preview, due to the attribute set above.
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
          // Include a delayed change event that will fire after the event
          // listeners in autocomplete.extend.js have had a chance to
          // determine if suggestions are available and prevent store/preview
          // updates or if they aren't it updates the store/preview with what
          // has been typed.
          if (delayedChangeTimerRef.current !== null) {
            window.clearTimeout(delayedChangeTimerRef.current);
          }
          delayedChangeTimerRef.current = window.setTimeout(() => {
            if (
              inputRef.current &&
              !inputRef.current.hasAttribute('data-canvas-no-update') &&
              typeof attributes.onChange === 'function'
            ) {
              attributes.onChange(e);
            }
            delayedChangeTimerRef.current = null;
          }, autocompleteDelay * 3);
        }}
        onBlur={(e) => {
          if (delayedChangeTimerRef.current !== null) {
            window.clearTimeout(delayedChangeTimerRef.current);
            delayedChangeTimerRef.current = null;
          }
          if (inputRef.current) {
            inputRef.current.removeAttribute('data-canvas-no-update');
          }

          const isPopoverManaged =
            inputRef.current?.hasAttribute(
              'data-canvas-popover-autocomplete',
            ) ?? false;

          const selectedCommittedValue =
            inputRef.current?.getAttribute(
              'data-canvas-selected-committed-value',
            ) ?? null;

          // As an additional assurance the value is sent to the store, an
          // additional onChange is triggered immediately after the store
          // preventing attribute is removed.
          if (!isPopoverManaged && typeof attributes.onChange === 'function') {
            attributes.onChange(e);
          }

          if (
            isPopoverManaged &&
            inputRef.current &&
            selectedCommittedValue &&
            typeof attributes.onChange === 'function'
          ) {
            inputRef.current.setAttribute('data-canvas-force-update', 'true');
            inputRef.current.value = selectedCommittedValue;
            const changeEvent = new Event('change');
            Object.defineProperty(changeEvent, 'target', {
              writable: false,
              value: inputRef.current,
            });
            attributes.onChange(changeEvent);
            inputRef.current.removeAttribute(
              'data-canvas-selected-committed-value',
            );
          }

          if (typeof attributes.onBlur === 'function') {
            attributes.onBlur(e);
          }
        }}
      />
    </div>
  );
};

export default TextFieldAutocomplete;
