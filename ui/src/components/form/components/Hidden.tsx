import { useEffect, useRef, useState } from 'react';

import { useAppSelector } from '@/app/hooks';
import { selectIsReordering } from '@/features/mediaLibrary/mediaLibrarySlice';
import useMutationObserver from '@/hooks/useMutationObserver';
import { a2p } from '@/local_packages/utils';
import { VALUE_THAT_MEANS_REMOVE } from '@/utils/function-utils';
import { isAjaxing } from '@/utils/isAjaxing';

import type { Attributes } from '@/types/DrupalAttribute';

/**
 * Dispatches a synthetic change event on the given input ref so the
 * inputBehaviors onChange handler picks it up and commits to Redux.
 */
function dispatchChangeEvent(
  ref: React.RefObject<HTMLInputElement | null>,
  onChange: Function,
) {
  const event = new Event('change');
  Object.defineProperty(event, 'target', {
    writable: false,
    value: ref.current,
  });
  onChange(event);
}

/**
 * Handles the removal of a hidden input from the DOM. Determines whether
 * the removal was caused by an AJAX form rebuild or by direct DOM
 * manipulation, and acts accordingly:
 *
 * - AJAX rebuild: defers until AJAX completes. If the input reappears
 *   (form rebuilt with the same field), re-syncs Redux with the new
 *   value. If it doesn't reappear (server removed it, e.g. user clicked
 *   "Remove"), does nothing — the rebuilt form already reflects the
 *   correct state.
 *
 * - Non-AJAX removal: flags the value with VALUE_THAT_MEANS_REMOVE so
 *   Redux cleans it up.
 */
function onHiddenFieldRemoved(
  name: string,
  ref: React.RefObject<HTMLInputElement | null>,
  onChange: Function,
) {
  if (isAjaxing()) {
    document.addEventListener(
      'drupalAjaxStop',
      () => {
        const existingInput = document.querySelector(
          `[name="${name}"]`,
        ) as HTMLInputElement | null;
        if (!existingInput) {
          return;
        }
        // Input was re-added (form rebuild). These inputs lack the
        // data-ajax attribute, so HyperscriptifyUpdateStore won't sync
        // them. Re-dispatch onChange to keep Redux in sync.
        ref.current!.value = existingInput.value;
        dispatchChangeEvent(ref, onChange);
      },
      { once: true },
    );
  } else {
    ref.current!.value = VALUE_THAT_MEANS_REMOVE;
    dispatchChangeEvent(ref, onChange);
  }
}

const Hidden = ({ attributes }: { attributes?: Attributes }) => {
  const [value, setValue] = useState(attributes?.value || '');
  const ref = useRef<HTMLInputElement | null>(null);
  const isReordering = useAppSelector(selectIsReordering);
  const isReorderingRef = useRef(isReordering);
  isReorderingRef.current = isReordering;

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
            // Call the onChange listener so the Redux store is updated.
            if (
              attributes?.onChange &&
              typeof attributes.onChange === 'function' &&
              // Drag-and-drop reordering temporarily removes and re-adds DOM
              // elements, which should not be treated as a true removal.
              !isReorderingRef.current
            ) {
              onHiddenFieldRemoved(name, ref, attributes.onChange);
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
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

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
