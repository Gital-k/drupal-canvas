import { setFieldError, setFieldValue } from '@/features/form/formStateSlice';
import { isAjaxing } from '@/utils/isAjaxing';

import type * as React from 'react';
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

export const extractPageDataValue = (e: any): any => {
  const target = e?.target as HTMLInputElement | HTMLSelectElement | undefined;
  if (!target) return null;

  if (
    // target instanceof HTMLInputElement &&
    target.type === 'checkbox' ||
    target.type === 'radio'
  ) {
    return target.checked ? '1' : '0';
  }
  if ('value' in target) {
    return target.value === '_none' ? null : target.value;
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

type CreateEnhancedOnBlurParams = {
  field: {
    onBlur: () => void;
    value: any;
  };
  props: Record<string, any>;
  dispatch: ReturnType<typeof useAppDispatch>;
  formId: string | null | undefined;
  fieldName: string;
  validateNewValue: (e: any, value: any) => ValidationResult;
  errorsText: ErrorsText;
};

export const createEnhancedOnBlur = ({
  field,
  props,
  dispatch,
  formId,
  fieldName,
  validateNewValue,
  errorsText,
}: CreateEnhancedOnBlurParams) => {
  return async (e: React.FocusEvent) => {
    // Call react-hook-form's onBlur to mark field as touched
    field.onBlur();

    // Call original onBlur if it exists
    const originalOnBlur = props.attributes?.onBlur;
    if (originalOnBlur) {
      originalOnBlur(e);
    }

    // Perform validation on blur
    const validationResult = validateNewValue(e as any, field.value);
    if (!validationResult.valid) {
      if (formId) {
        // Dispatch validation error to Redux store
        // (Mutation of props.attributes removed as it doesn't work with memoization
        // and the attribute wasn't being used anywhere in the codebase)
        dispatchFieldError(
          dispatch,
          formId as FormId,
          fieldName,
          validationResult,
          errorsText,
        );
      }
    }
  };
};

type CreateEnhancedOnChangeParams = {
  field: {
    onChange: (value: any) => void;
  };
  props: Record<string, any>;
  dispatch: ReturnType<typeof useAppDispatch>;
  formId: string | null | undefined;
  fieldName: string;
  formState: Record<string, any>;
  commitFormState: (newFormState: Record<string, any>) => void;
  extractNewValue: (e: any) => any;
  validateNewValue?: (e: any, value: any) => ValidationResult;
  errorsText?: ErrorsText;
};

export const createEnhancedOnChange = ({
  field,
  props,
  dispatch,
  formId,
  fieldName,
  formState,
  commitFormState,
  extractNewValue,
  validateNewValue,
  errorsText,
}: CreateEnhancedOnChangeParams) => {
  return (e: ChangeEvent) => {
    const newValue = extractNewValue(e);

    // Always update react-hook-form state (for UI consistency and RHF validation)
    field.onChange(newValue);

    // Always update Redux display state
    if (formId) {
      dispatchFieldValue(dispatch, formId as FormId, fieldName, newValue);
    }

    // Call original onChange (unless skipOriginalOnChange is set)
    // This allows nested components like FormatSelect to call enhancedOnChange
    // without creating a cascade
    // const originalOnChange = props.attributes?.onChange || props.onChange;
    // if (originalOnChange && !e?.skipOriginalOnChange) {
    //   originalOnChange(e);
    // }

    // VALIDATION GATES AUTO-SAVE (preserve existing auto-save blocking logic)
    if (validateNewValue && errorsText) {
      const validationResult = validateNewValue(e, newValue);

      // Check if we should skip auto-save to backend
      const shouldSkipAutoSave =
        (typeof e?.target?.hasAttribute === 'function' &&
          e.target.hasAttribute('data-canvas-no-update')) ||
        (!validationResult.valid && !validationResult.skipEarlyReturn);

      if (shouldSkipAutoSave) {
        // Show blocking error but don't auto-save to backend
        if (formId && validationResult?.valid !== true) {
          dispatchFieldError(
            dispatch,
            formId as FormId,
            fieldName,
            validationResult,
            errorsText,
          );
        }
        return; // Block auto-save
      }
    }

    // Validation passed or no validation - proceed with auto-save
    if (!isAjaxing()) {
      commitFormState({ ...formState, [fieldName]: newValue });
      return;
    }

    // AJAX in progress - queue auto-save for after AJAX completion
    const stopListener = () => {
      commitFormState({ ...formState, [fieldName]: newValue });
    };
    document.addEventListener('drupalAjaxStop', stopListener, {
      once: true,
    });
  };
};

/**
 * Applies enhanced onChange, onBlur, and value props to a component.
 * Handles different component types (attributes-based, onCheckedChange, direct props).
 */
export const applyEnhancedProps = <P extends Record<string, any>>(
  props: P,
  field: { value: any },
  enhancedOnChange: (e: any) => void,
  enhancedOnBlur: (e: any) => void,
  hasError?: boolean,
): P => {
  const enhancedProps = { ...props };
  const elementType =
    enhancedProps.attributes?.type ||
    enhancedProps.attributes?.['data-canvas-type'];
  const isRadioElement = elementType === 'radios' || elementType === 'radio';
  const isCheckboxElement = elementType === 'checkbox';

  if (enhancedProps.attributes) {
    const baseAttributes = {
      ...enhancedProps.attributes,
      onChange: enhancedOnChange,
      onBlur: enhancedOnBlur,
      ...(hasError ? { 'data-invalid-prop-value': 'true' } : {}),
    };

    if (isCheckboxElement) {
      // For checkboxes: only override if RHF value represents actual change
      const originalChecked = enhancedProps.attributes?.checked;
      const rhfChecked = !!field.value;

      // Convert original to boolean for comparison
      const originalAsBool =
        originalChecked === 'checked' ||
        originalChecked === 'true' ||
        originalChecked === true;

      // Only set checked if RHF value differs from original default
      if (rhfChecked !== originalAsBool) {
        baseAttributes.checked = rhfChecked;
      }
      // Don't override the static value attribute
    } else if (!isRadioElement) {
      // For text inputs and others: set value
      // Safety check: Only set value if we have an onChange handler
      // This prevents React warnings about controlled inputs without onChange
      baseAttributes.value = field.value;
    }

    (enhancedProps as any).attributes = baseAttributes;
  } else if ((props as any).onCheckedChange) {
    (enhancedProps as any).onCheckedChange = enhancedOnChange;
    (enhancedProps as any).onBlur = enhancedOnBlur;
    (enhancedProps as any).checked = field.value;
  } else {
    (enhancedProps as any).onChange = enhancedOnChange;
    (enhancedProps as any).onBlur = enhancedOnBlur;
    (enhancedProps as any).value = field.value;
  }

  return enhancedProps;
};
