import { useEffect, useRef } from 'react';
import { debounce } from 'lodash';

import { DEBOUNCE_TIMEOUT } from '@/components/form/formUtil';

/**
 * Hook for managing debounced form state updates.
 * Provides a debounced version of the formStateToStore function and handles cleanup.
 *
 * @param formStateToStore - The function to debounce for form state updates
 * @returns The debounced version of the formStateToStore function
 */
export const useDebouncedFormState = (
  formStateToStore: (...args: any[]) => any,
) => {
  const debounceFormStateToStore = useRef(
    debounce(formStateToStore, DEBOUNCE_TIMEOUT),
  ).current;

  useEffect(() => {
    return () => {
      // Cancel any pending debounced calls when the component unmounts
      debounceFormStateToStore.cancel();
    };
  }, [debounceFormStateToStore]);

  return debounceFormStateToStore;
};
