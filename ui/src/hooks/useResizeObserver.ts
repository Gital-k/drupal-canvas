import { useEffect } from 'react';

import type { RefObject } from 'react';

/**
 * A hook that observes resize events on a specified DOM element using ResizeObserver and triggers a callback.
 *
 * @param ref - A React ref object pointing to a DOM element to be observed. Can be null before mount.
 * @param callback - A function to be called when the observed element is resized.
 */
function useResizeObserver(
  ref: RefObject<HTMLElement | null>,
  callback: () => void,
): void {
  useEffect(() => {
    const element = ref.current;
    if (!element) {
      return;
    }

    const observer = new ResizeObserver(() => {
      callback();
    });

    observer.observe(element);

    return () => {
      observer.disconnect();
    };
  }, [ref, callback]);
}

export default useResizeObserver;
