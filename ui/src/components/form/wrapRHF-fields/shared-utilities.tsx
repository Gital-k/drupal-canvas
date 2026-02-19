import { useEffect, useRef } from 'react';
import { debounce } from 'lodash';

import { DEBOUNCE_TIMEOUT } from '@/components/form/formUtil';

import type React from 'react';
import type { FieldError } from 'react-hook-form';

/**
 * Props for the FieldErrorDisplay component
 */
interface FieldErrorDisplayProps {
  blockingError?: {
    type: string;
    message: string;
  } | null;
  fieldState: {
    error?: FieldError;
  };
}

/**
 * Reusable component for displaying form field errors.
 * Handles both blocking errors from Redux and display errors from RHF.
 */
export const FieldErrorDisplay: React.FC<FieldErrorDisplayProps> = ({
  blockingError,
  fieldState,
}) => {
  const displayError = blockingError || fieldState.error;

  if (!displayError) return null;

  return (
    <span
      data-prop-message
      className={blockingError ? 'error-blocking' : 'error-display'}
    >
      {blockingError
        ? `${blockingError.type === 'error' ? '❌ ' : ''}${blockingError.message}`
        : fieldState.error?.message}
    </span>
  );
};

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
