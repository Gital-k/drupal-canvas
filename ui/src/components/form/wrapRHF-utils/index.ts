import type { ErrorObject } from 'ajv/dist/types';
import type { ChangeEvent } from 'react';

import { setFieldError, setFieldValue } from '@/features/form/formStateSlice';

import type { FormId } from '@/features/form/formStateSlice';
import type { useAppDispatch } from '@/app/hooks';

type ErrorsText = (errors?: ErrorObject[] | null) => string;

export type ValidationResult = {
  valid: boolean;
  errors?: null | ErrorObject[];
  errorMessage?: string;
  skipEarlyReturn?: boolean;
};

export const dispatchFieldValue = (
  dispatch: ReturnType<typeof useAppDispatch>,
  formId: FormId,
  fieldName: string,
  value: any,
) => {
  dispatch(
    setFieldValue({
      formId,
      fieldName,
      value,
    }),
  );
};

export const dispatchFieldError = (
  dispatch: ReturnType<typeof useAppDispatch>,
  formId: FormId,
  fieldName: string,
  validationResult: ValidationResult,
  errorsText: ErrorsText,
) => {
  dispatch(
    setFieldError({
      type: 'error',
      message:
        validationResult.errorMessage ||
        errorsText(validationResult.errors || null),
      formId,
      fieldName,
    }),
  );
};

export const extractValue = (e: any): any => {
  if (typeof e === 'boolean') {
    return e;
  }
  if (e?.target?.type === 'checkbox') {
    return e.target.checked;
  }
  if (e?.target?.value !== undefined) {
    return e.target.value;
  }
  if (e?.target?.checked !== undefined) {
    return e.target.checked;
  }
  return e;
};

export const parseTextValue = (e: ChangeEvent) => {
  const target = e.target as HTMLInputElement;
  if (target.value !== undefined) {
    return target.value === '_none' ? null : target.value;
  }
  if ('checked' in target) {
    return target.checked;
  }
  return null;
};
