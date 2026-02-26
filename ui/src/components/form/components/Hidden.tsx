import { useEffect, useRef, useState } from 'react';

import { useSafeRHFContext } from '@/components/form/contexts/FormContext';
import useMutationObserver from '@/hooks/useMutationObserver';
import { a2p } from '@/local_packages/utils';
import { VALUE_THAT_MEANS_REMOVE } from '@/utils/function-utils';

import { propagateOnChange } from './propagateValueChange';

import type { Attributes } from '@/types/DrupalAttribute';

const Hidden = ({ attributes }: { attributes?: Attributes }) => {
  const [value, setValue] = useState(attributes?.value || '');
  const ref = useRef<HTMLInputElement | null>(null);

  // Get react-hook-form context to update form state
  const rhfMethods = useSafeRHFContext();
  const fieldName = attributes?.name || attributes?.['data-canvas-name'];

  useEffect(() => {
    // Add a mutation observer to the form element this input belongs to. This
    // allows us to detect when the input is removed from the DOM, which allows
    // us to communicate that removal to the Redux store.
    // Because the observer is attached to a parent of the ref, it is added here
    // instead of using the `useMutationObserver` hook as placing it in the
    // useEffect ensures the full form has been rendered already.
    let observer: MutationObserver | null = null;
    if (ref.current) {
      const name = ref.current.name;
      observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
          const removedNode = mutation.removedNodes[0] as Element | null;
          // Check if  the removed element is the input managed by this
          // component.
          if (
            removedNode &&
            'querySelector' in removedNode &&
            removedNode.querySelector(`[name="${name}"]`) &&
            ref.current
          ) {
            ref.current.value = '';

            // Dispatch custom event to trigger form state update in wrapRHF
            // This triggers the full commitFormState -> formStateToStore flow
            if (fieldName) {
              const fieldUpdateEvent = new CustomEvent('canvas:fieldUpdate', {
                detail: {
                  fieldName: String(fieldName),
                  value: VALUE_THAT_MEANS_REMOVE,
                },
                bubbles: true,
              });
              document.dispatchEvent(fieldUpdateEvent);
            }

            // Also update react-hook-form state if context is available
            if (rhfMethods && fieldName) {
              rhfMethods.setValue(String(fieldName), VALUE_THAT_MEANS_REMOVE, {
                shouldValidate: true,
                shouldDirty: true,
                shouldTouch: true,
              });
            }

            if (attributes?.onChange && ref.current) {
              propagateOnChange(ref.current, {
                value: VALUE_THAT_MEANS_REMOVE,
              });
            }
          }
        });
      });

      if (
        ref.current &&
        'closest' in ref.current &&
        ref.current.closest('form') &&
        observer instanceof MutationObserver
      ) {
        observer.observe(ref.current.closest('form')!, {
          childList: true,
          subtree: true,
        });
      }
    }
    return () => {
      if (observer instanceof MutationObserver) {
        observer.disconnect();
      }
    };
  }, [rhfMethods, fieldName, attributes]);

  // Hidden field values might be updated by AJAX requests and those value
  // changes should persist on rerender instead of falling back to the initial
  // value in `attributes`. A Mutation Observer is used to monitor value changes
  // and keeps track of them in state.
  useMutationObserver(
    ref,
    (mutations) => {
      mutations.forEach((record: MutationRecord) => {
        if (record?.attributeName === 'value') {
          if (record.target instanceof HTMLElement) {
            const newValue = record.target.getAttribute(record.attributeName);
            setValue(`${newValue}`);
          }
        }
      });
    },
    { attributes: true },
  );

  return <input ref={ref} {...a2p(attributes || {})} value={value} />;
};

export default Hidden;
