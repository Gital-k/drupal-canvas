import { setFieldError, setFieldValue } from '@/features/form/formStateSlice';

import type { ChangeEvent } from 'react';
import type { ErrorObject } from 'ajv/dist/types';
import type { useAppDispatch } from '@/app/hooks';
import type { FormId } from '@/features/form/formStateSlice';

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

export const getCurrentValueFromProps = (props: Record<string, any>) => {
  if (props.options && props.options.some((opt: any) => opt.selected)) {
    return props.options.find((opt: any) => opt.selected).value;
  }
  if (props.checked !== undefined) return props.checked;
  if (props.value !== undefined) return props.value;
  if (props.attributes?.checked !== undefined) return props.attributes.checked;
  if (props.attributes?.value !== undefined) return props.attributes.value;
  if (props.element?.['#value'] !== undefined) return props.element['#value'];
  if (props.element?.['#default_value'] !== undefined) {
    return props.element['#default_value'];
  }
  return undefined;
};
