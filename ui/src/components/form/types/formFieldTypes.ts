import type { ComponentType } from 'react';
import type { ErrorObject } from 'ajv/dist/types';

/**
 * Validation result from field validation
 */
export type ValidationResult = {
  valid: boolean;
  errors?: null | ErrorObject[];
  errorMessage?: string;
  skipEarlyReturn?: boolean;
};

/**
 * Props shared between form field components
 */
export interface FormFieldProps<P> {
  props: P;
  WrappedComponent: ComponentType<P>;
  fieldName: string;
  getCurrentValue: () => any;
}
