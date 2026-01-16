import { useEffect, useRef } from 'react';
import { debounce } from 'lodash';
import { Controller } from 'react-hook-form';

import { DEBOUNCE_TIMEOUT } from '@/components/form/inputBehaviors';
import {
  dispatchFieldError,
  dispatchFieldValue,
  extractValue,
} from '@/components/form/utils';
import { useSafeFormContext, useSafeRHFContext } from '@/contexts/FormContext';
import { setUpdatePreview } from '@/features/layout/layoutModelSlice';
import {
  externalUpdateComplete,
  setPageData,
} from '@/features/pageData/pageDataSlice';
import { usePageDataFormInputInfo } from '@/hooks/usePageDataFormInputInfo';
import { useUndoRedoSync } from '@/hooks/useUndoRedoSync';
import { isAjaxing } from '@/utils/isAjaxing';

import type * as React from 'react';
import type { PropsValues } from '@/types/Form';
import type { FormFieldProps, ValidationResult } from '../types/formFieldTypes';

/**
 * Page data form field - handles entity/page form inputs
 *
 * This component integrates form inputs with React Hook Form for the page
 * data form. It handles:
 * - Form state synchronization with Redux pageData slice
 * - Undo/redo synchronization
 * - Validation using HTML5 validation
 * - External update handling
 * - Preview triggering
 */
export const PageDataFormField = <P extends Record<string, any>>({
  props,
  WrappedComponent,
  fieldName,
  getCurrentValue,
}: FormFieldProps<P>) => {
  const formContext = useSafeFormContext();
  const rhfContext = useSafeRHFContext();

  const { dispatch, pageData, formState } = usePageDataFormInputInfo();

  const { attributes } = props;
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  const formId = 'page_data_form';

  // Override getCurrentValue to use pageData from Redux instead of props
  // This ensures undo/redo updates are properly reflected
  const getCurrentValueFromRedux = () => {
    // For page data forms, get value from Redux pageData state
    if (pageData && fieldName in pageData) {
      return pageData[fieldName];
    }
    // Fallback to the original getCurrentValue if not in pageData
    return getCurrentValue();
  };

  // Watch for undo/redo actions and sync react-hook-form
  useUndoRedoSync(fieldName, getCurrentValueFromRedux, rhfContext, 'pageData');

  // Page data form specific hooks
  useEffect(() => {
    if (!formContext?.formId) return;

    const currentValue = getCurrentValueFromRedux();

    // Update Redux store with initial field value
    dispatchFieldValue(dispatch, formContext.formId, fieldName, currentValue);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dispatch, fieldName, formContext?.formId, WrappedComponent]);

  const formStateToStore = (newFormState: PropsValues) => {
    const values = Object.keys(newFormState).reduce(
      (acc: Record<string, any>, key) => {
        if (
          !['changed', 'formId', 'formType', 'externalUpdates'].includes(key)
        ) {
          return { ...acc, [key]: newFormState[key] };
        }
        return acc;
      },
      {},
    );
    // Flag that we need to update the preview.
    dispatch(setUpdatePreview(true));
    dispatch(setPageData(values));
  };

  const debounceFormStateToStore = useRef(
    debounce(formStateToStore, DEBOUNCE_TIMEOUT),
  ).current;

  useEffect(() => {
    return () => {
      // Cancel any pending debounced calls when the component unmounts.
      debounceFormStateToStore.cancel();
    };
  }, [debounceFormStateToStore]);

  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  const parseNewValue = (e: React.ChangeEvent) => {
    const target = e.target as HTMLInputElement;
    // If the target is an input element, return its value
    if (target.value !== undefined) {
      // We have a special case for `_none`, which represents an empty value in a
      // select element. It is converted to an empty string so it can leverage
      // the logic for textfields where an empty string results in the prop
      // being removed from the model.
      return target.value === '_none' ? null : target.value;
    }
    // If the target is a checkbox or radio button, return its checked
    if ('checked' in target) {
      return target.checked;
    }
    // If the target is neither an input element nor a checkbox/radio button, return null
    return null;
  };

  const validateNewValue = (
    e: React.ChangeEvent,
    newValue: any,
  ): ValidationResult => {
    if (!(e.target instanceof HTMLInputElement)) {
      return { valid: true, errors: null };
    }

    // For the page data form, we use native HTML5 validation, but the messages
    // are displayed by the same component that renders JSON Schema errors in
    // the component instance form.
    if (!e.target.checkValidity()) {
      const inputElement = e.target;
      const requiredAndOnlyProblemIsEmpty =
        inputElement.required &&
        Object.keys(inputElement.validity).every((validityProperty: string) =>
          ['valueMissing'].includes(validityProperty)
            ? inputElement.validity[validityProperty as keyof ValidityState]
            : !inputElement.validity[validityProperty as keyof ValidityState],
        );
      return {
        valid: false,
        errorMessage: e.target.validationMessage,
        skipEarlyReturn: requiredAndOnlyProblemIsEmpty,
      };
    }

    return { valid: true, errors: null };
  };

  const forceUpdateInputValue = (fieldName: string, theNewValue: string) => {
    dispatch(externalUpdateComplete(fieldName));

    // Update react-hook-form context if available
    if (rhfContext) {
      rhfContext.setValue(fieldName, theNewValue, {
        shouldValidate: false,
        shouldDirty: true,
        shouldTouch: false,
      });
    }
  };

  if (
    pageData &&
    pageData?.externalUpdates &&
    pageData.externalUpdates.includes(fieldName) &&
    pageData[fieldName]
  ) {
    setTimeout(() => {
      forceUpdateInputValue(fieldName, pageData[fieldName]);
    });
  }

  const commitFormState = (newFormState: PropsValues) => {
    const elementType = attributes.type || attributes['data-canvas-type'];
    if (['checkbox', 'radio'].includes(elementType as string)) {
      formStateToStore(newFormState);
    } else {
      debounceFormStateToStore(newFormState);
    }
  };

  if (!rhfContext) {
    return <WrappedComponent {...props} />;
  }

  return (
    <Controller
      name={fieldName}
      control={rhfContext.control}
      defaultValue={getCurrentValueFromRedux()}
      render={({ field }) => {
        const enhancedOnChange = (e: any) => {
          const newValue = extractValue(e);

          // Update react-hook-form state
          field.onChange(newValue);

          // Update Redux store
          if (formContext?.formId) {
            dispatchFieldValue(
              dispatch,
              formContext.formId,
              fieldName,
              newValue,
            );
          }

          // Call original onChange
          const originalOnChange = props.attributes?.onChange || props.onChange;
          if (originalOnChange) {
            originalOnChange(e);
          }
          if (!isAjaxing()) {
            commitFormState({ ...formState, [fieldName]: newValue });
            return;
          }
          // This is only reached if AJAX operations are in progress. Add an event
          // listener to update the form state once ajax is complete.
          const stopListener = () => {
            commitFormState({ ...formState, [fieldName]: newValue });
          };
          document.addEventListener('drupalAjaxStop', stopListener, {
            once: true,
          });
        };

        const enhancedOnBlur = async (e: React.FocusEvent) => {
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
            if (formContext?.formId) {
              if (props.attributes) {
                props.attributes['data-invalid-prop-value'] = 'true';
              }
              dispatchFieldError(
                dispatch,
                formContext.formId,
                fieldName,
                validationResult,
              );
            }
          }
        };

        // Apply enhanced props
        const enhancedProps = { ...props };
        const elementType =
          enhancedProps.attributes?.type ||
          enhancedProps.attributes?.['data-canvas-type'];
        const isRadioElement =
          elementType === 'radios' || elementType === 'radio';

        if (enhancedProps.attributes) {
          const baseAttributes = {
            ...enhancedProps.attributes,
            onChange: enhancedOnChange,
            onBlur: enhancedOnBlur,
          };

          // Don't override value for radio elements - each radio needs its own value
          if (!isRadioElement) {
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

        return <WrappedComponent {...enhancedProps} />;
      }}
    />
  );
};
