import React, { useCallback, useMemo, useRef } from 'react';
import { Controller } from 'react-hook-form';

import { useAppSelector } from '@/app/hooks';
import {
  createSyntheticBlurEvent,
  createSyntheticChangeEvent,
  FieldContextProvider,
} from '@/components/form/contexts/FieldContext';
import {
  useSafeFormContext,
  useSafeRHFContext,
} from '@/components/form/contexts/FormContext';
import { useDebouncedFormState } from '@/components/form/react-hook-form/hooks/useDebouncedFormState';
import { usePageDataFormInputInfo } from '@/components/form/react-hook-form/hooks/usePageDataFormInputInfo';
import { usePageDataInitialValue } from '@/components/form/react-hook-form/hooks/usePageDataInitialValue';
import { useUndoRedoSync } from '@/components/form/react-hook-form/hooks/useUndoRedoSync';
import {
  applyEnhancedProps,
  comparePropsWithAttributes,
  createEnhancedOnBlur,
  createEnhancedOnChange,
  extractPageDataValue,
  getCurrentValueFromProps,
} from '@/components/form/react-hook-form/utils';
import {
  clearFieldError,
  selectFieldError,
} from '@/features/form/formStateSlice';

import { FieldErrorDisplay } from './FieldErrorDisplay';
import {
  createPageDataFormStateHandler,
  useRespondToPageDataStoreUpdates,
} from './pageDataFormHandlers';

import type { ComponentType } from 'react';
import type * as ReactType from 'react';
import type { PropsValues } from '@drupal-canvas/types';
import type { FormId } from '@/features/form/formStateSlice';

interface PageDataFormFieldProps<P> {
  props: P;
  WrappedComponent: ComponentType<P>;
  fieldName: string;
  errorsText: (errors?: any) => string;
  hasError?: boolean;
}

/**
 * Validates form input using HTML5 validation API.
 * For the page data form, we use native HTML5 validation, but the messages
 * are displayed by the same component that renders JSON Schema errors in
 * the component instance form.
 */
const validateNewValue = (e: ReactType.ChangeEvent, newValue: any) => {
  if (!(e.target instanceof HTMLInputElement)) {
    return { valid: true, errors: null };
  }

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

/**
 * Page data form field - handles entity/page form inputs
 */
export const PageDataFormField = <P extends Record<string, any>>({
  props,
  WrappedComponent,
  fieldName,
  errorsText,
  hasError,
}: PageDataFormFieldProps<P>) => {
  const formContext = useSafeFormContext();
  const rhfContext = useSafeRHFContext();
  const getCurrentValue = () => getCurrentValueFromProps(props);

  const { dispatch, pageData, formState } = usePageDataFormInputInfo();

  const { attributes } = props;

  // Create a memoized version of WrappedComponent with deep comparison
  const MemoizedWrappedComponent = useMemo(
    () => React.memo(WrappedComponent, comparePropsWithAttributes),
    [WrappedComponent],
  ) as unknown as React.ComponentType<P>;

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

  // Set the initial value on mount.
  usePageDataInitialValue(
    dispatch,
    fieldName,
    formContext,
    getCurrentValueFromRedux,
    WrappedComponent,
  );

  // Create the form state to Redux handler.
  const formStateToPageDataStore = createPageDataFormStateHandler(dispatch);
  // Create a debounced version of the above handler.
  const debounceFormStateToPageDataStore = useDebouncedFormState(
    formStateToPageDataStore,
  );

  // Watch for external updates to pageData and sync to RHF
  useRespondToPageDataStoreUpdates(fieldName, pageData, rhfContext, dispatch);

  const updatePageDataStore = (newFormState: PropsValues) => {
    const elementType = attributes.type || attributes['data-canvas-type'];
    if (['checkbox', 'radio'].includes(elementType as string)) {
      formStateToPageDataStore(newFormState);
    } else {
      debounceFormStateToPageDataStore(newFormState);
    }
  };

  // Create stable callback references using refs
  const latestPropsRef = useRef({
    props,
    formState,
    dispatch,
    formContext,
    validateNewValue,
    errorsText,
    updatePageDataStore,
    field: null as any,
  });
  latestPropsRef.current = {
    ...latestPropsRef.current,
    props,
    formState,
    dispatch,
    formContext,
    validateNewValue,
    errorsText,
    updatePageDataStore,
  };

  // Create stable onChange handler that won't change reference
  const stableOnChange = useCallback(
    (e: any) => {
      const latest = latestPropsRef.current;
      // Clear Redux error before handling the new value
      if (latest.dispatch && latest.formContext) {
        latest.dispatch(
          clearFieldError({
            formId: latest.formContext.formId,
            fieldName,
          }),
        );
      }
      const handler = createEnhancedOnChange({
        field: latest.field,
        props: latest.props,
        dispatch: latest.dispatch,
        formId: latest.formContext?.formId,
        fieldName,
        formState: latest.formState,
        updateStore: latest.updatePageDataStore,
        extractNewValue: extractPageDataValue,
        validateNewValue: latest.validateNewValue,
        errorsText: latest.errorsText,
      });
      handler(e);
    },
    [fieldName],
  );

  // Create stable onBlur handler that won't change reference
  const stableOnBlur = useCallback(
    (e: any) => {
      const latest = latestPropsRef.current;
      const handler = createEnhancedOnBlur({
        field: latest.field,
        props: latest.props,
        dispatch: latest.dispatch,
        formId: latest.formContext?.formId,
        fieldName,
        validateNewValue: latest.validateNewValue,
        errorsText: latest.errorsText,
      });
      handler(e);
    },
    [fieldName],
  );

  // Create FieldContext value for child components to trigger form updates
  const fieldContextValue = useMemo(
    () => ({
      fieldName,
      triggerChange: (valueOrEvent: any, target?: HTMLElement) => {
        // Check if first arg is an event (has 'target' property with 'value')
        if (
          valueOrEvent &&
          typeof valueOrEvent === 'object' &&
          'target' in valueOrEvent
        ) {
          // It's an event, pass it through directly
          stableOnChange(valueOrEvent);
        } else {
          // It's a value, create synthetic event
          const event = createSyntheticChangeEvent(valueOrEvent, target);
          stableOnChange(event);
        }
      },
      triggerBlur: (target?: HTMLElement) => {
        const event = createSyntheticBlurEvent(target);
        stableOnBlur(event);
      },
    }),
    [fieldName, stableOnChange, stableOnBlur],
  );

  if (!rhfContext) {
    return <MemoizedWrappedComponent {...(props as any)} />;
  }

  return (
    <Controller
      name={fieldName}
      control={rhfContext.control}
      defaultValue={getCurrentValueFromRedux()}
      rules={{
        // RHF validation for immediate UI feedback (doesn't block auto-save)
        validate: {
          html5Validation: (value) => {
            // Create a temporary input element to leverage HTML5 validation
            const tempInput = document.createElement('input');
            const attrs = props.attributes || {};

            // HTML5 validation attributes to copy
            const validationAttrs = [
              'type',
              'required',
              'pattern',
              'min',
              'max',
              'minLength',
              'maxLength',
            ];

            // Apply all relevant HTML5 validation attributes
            validationAttrs.forEach((attr) => {
              if (attrs[attr] !== undefined) {
                (tempInput as any)[attr] = attrs[attr];
              }
            });

            tempInput.value = value || '';

            if (!tempInput.checkValidity()) {
              return tempInput.validationMessage;
            }
            return true;
          },
        },
      }}
      shouldUnregister={false} // Prevent RHF from interfering with auto-save logic
      render={({ field, fieldState }) => {
        // Store the field in the ref so stable callbacks can access it
        latestPropsRef.current.field = field;

        // Get Redux blocking error (takes priority over RHF display errors)
        const blockingError = useAppSelector((state) =>
          selectFieldError(state, {
            formId: formContext?.formId as FormId,
            fieldName,
          }),
        );

        // Show RHF error if no blocking error exists
        const displayError = blockingError || fieldState.error;

        // Memoize the enhanced props to prevent creating new attributes object on every render
        const enhancedProps = useMemo(
          () =>
            applyEnhancedProps(
              props,
              field,
              stableOnChange,
              stableOnBlur,
              !!displayError,
            ),
          // eslint-disable-next-line react-hooks/exhaustive-deps
          [field, field.value, stableOnChange, stableOnBlur, !!displayError],
        );

        return (
          <FieldContextProvider value={fieldContextValue}>
            <MemoizedWrappedComponent {...(enhancedProps as any)} />
            <FieldErrorDisplay
              blockingError={blockingError}
              fieldState={fieldState}
            />
          </FieldContextProvider>
        );
      }}
    />
  );
};
