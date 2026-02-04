import type * as React from 'react';
import type { ValidationResult } from '../types/formFieldTypes';

/**
 * Determines if form state should be updated based on validation result
 */
export function shouldUpdateFormState(
  e: React.ChangeEvent,
  validationResult: ValidationResult,
): boolean {
  if (
    typeof e?.target?.hasAttribute === 'function' &&
    e.target.hasAttribute('data-canvas-no-update')
  ) {
    return false;
  }
  return !!(validationResult.valid || validationResult.skipEarlyReturn);
}
