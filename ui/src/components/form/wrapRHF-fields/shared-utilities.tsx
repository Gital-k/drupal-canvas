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
