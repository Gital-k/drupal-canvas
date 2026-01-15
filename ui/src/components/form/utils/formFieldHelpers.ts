import Ajv from 'ajv';

import { setFieldError, setFieldValue } from '@/features/form/formStateSlice';

import type { useAppDispatch } from '@/app/hooks';
import type { FormId } from '@/features/form/formStateSlice';
import type { ValidationResult } from '../types/formFieldTypes';

const ajv = new Ajv();

/**
 * Dispatches a field value update to Redux
 */
export function dispatchFieldValue(
  dispatch: ReturnType<typeof useAppDispatch>,
  formId: FormId,
  fieldName: string,
  value: any,
): void {
  dispatch(
    setFieldValue({
      formId,
      fieldName,
      value,
    }),
  );
}

/**
 * Dispatches a field error update to Redux
 */
export function dispatchFieldError(
  dispatch: ReturnType<typeof useAppDispatch>,
  formId: FormId,
  fieldName: string,
  validationResult: ValidationResult,
): void {
  console.log(
    `dispatching field error ${formId}, ${fieldName}`,
    validationResult.errorMessage || ajv.errorsText(validationResult.errors),
  );
  dispatch(
    setFieldError({
      type: 'error',
      message:
        validationResult.errorMessage ||
        ajv.errorsText(validationResult.errors),
      formId,
      fieldName,
    }),
  );
}

/**
 * Helper to extract new value from various event types
 */
export function extractValue(e: any): any {
  if (typeof e === 'boolean') {
    return e;
  } else if (e?.target?.type === 'checkbox') {
    return e.target.checked;
  } else if (e?.target?.value !== undefined) {
    return e.target.value;
  } else if (e?.target?.checked !== undefined) {
    return e.target.checked;
  }
  return e;
}
