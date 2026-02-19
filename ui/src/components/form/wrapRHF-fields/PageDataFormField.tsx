import React, { useCallback, useEffect, useMemo, useRef } from 'react';
import { debounce } from 'lodash';
import { Controller } from 'react-hook-form';

import { useAppSelector } from '@/app/hooks';
import { DEBOUNCE_TIMEOUT } from '@/components/form/formUtil';
import { usePageDataFormInputInfo } from '@/components/form/wrapRHF-hooks/usePageDataFormInputInfo';
import { usePageDataInitialValue } from '@/components/form/wrapRHF-hooks/usePageDataInitialValue';
import { useUndoRedoSync } from '@/components/form/wrapRHF-hooks/useUndoRedoSync';
import {
  applyEnhancedProps,
  createEnhancedOnBlur,
  createEnhancedOnChange,
  extractPageDataValue,
  getCurrentValueFromProps,
} from '@/components/form/wrapRHF-utils';
import { useSafeFormContext, useSafeRHFContext } from '@/contexts/FormContext';
import { selectFieldError } from '@/features/form/formStateSlice';
import { setUpdatePreview } from '@/features/layout/layoutModelSlice';
import {
  externalUpdateComplete,
  setPageData,
} from '@/features/pageData/pageDataSlice';

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
    () =>
      React.memo(WrappedComponent, (prevProps, nextProps) => {
        // Quick reference check
        if (prevProps === nextProps) return true;

        // Deep compare attributes
        const prevAttrs = (prevProps.attributes || {}) as Record<string, any>;
        const nextAttrs = (nextProps.attributes || {}) as Record<string, any>;

        const prevKeys = Object.keys(prevAttrs);
        const nextKeys = Object.keys(nextAttrs);

        if (prevKeys.length !== nextKeys.length) return false;

        for (const key of prevKeys) {
          if (prevAttrs[key] !== nextAttrs[key]) {
            return false;
          }
        }

        return true; // All attributes same, skip re-render
      }),
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

  // Page data form specific hooks
  usePageDataInitialValue(
    dispatch,
    fieldName,
    formContext,
    getCurrentValueFromRedux,
    WrappedComponent,
  );

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

  const validateNewValue = (e: ReactType.ChangeEvent, newValue: any) => {
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

  // Watch for external updates to pageData and sync to RHF
  useEffect(() => {
    if (!rhfContext || !pageData) return;

    // If this field was updated externally, sync it to RHF
    if (
      pageData.externalUpdates?.includes(fieldName) &&
      fieldName in pageData
    ) {
      rhfContext.setValue(fieldName, pageData[fieldName], {
        shouldValidate: false,
        shouldDirty: true,
        shouldTouch: false,
      });

      // Clear the external update flag for this field
      dispatch(externalUpdateComplete(fieldName));
    }
  }, [pageData, pageData?.externalUpdates, fieldName, rhfContext, dispatch]);

  const commitFormState = (newFormState: PropsValues) => {
    const elementType = attributes.type || attributes['data-canvas-type'];
    if (['checkbox', 'radio'].includes(elementType as string)) {
      formStateToStore(newFormState);
    } else {
      debounceFormStateToStore(newFormState);
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
    commitFormState,
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
    commitFormState,
  };

  // Create stable onChange handler that won't change reference
  const stableOnChange = useCallback(
    (e: any) => {
      const latest = latestPropsRef.current;
      const handler = createEnhancedOnChange({
        field: latest.field,
        props: latest.props,
        dispatch: latest.dispatch,
        formId: latest.formContext?.formId,
        fieldName,
        formState: latest.formState,
        commitFormState: latest.commitFormState,
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
          <>
            <MemoizedWrappedComponent {...(enhancedProps as any)} />
            {displayError && (
              <span
                data-prop-message
                className={blockingError ? 'error-blocking' : 'error-display'}
              >
                {blockingError
                  ? `${blockingError.type === 'error' ? '❌ ' : ''}${blockingError.message}`
                  : fieldState.error?.message}
              </span>
            )}
          </>
        );
      }}
    />
  );
};
