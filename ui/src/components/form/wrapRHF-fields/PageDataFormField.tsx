import { useEffect, useRef } from 'react';
import { debounce } from 'lodash';
import { Controller } from 'react-hook-form';

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
import { setUpdatePreview } from '@/features/layout/layoutModelSlice';
import {
  externalUpdateComplete,
  setPageData,
} from '@/features/pageData/pageDataSlice';

import type { ComponentType } from 'react';
import type * as React from 'react';
import type { PropsValues } from '@/types/Form';

interface PageDataFormFieldProps<P> {
  props: P;
  WrappedComponent: ComponentType<P>;
  fieldName: string;
  errorsText: (errors?: any) => string;
}

/**
 * Page data form field - handles entity/page form inputs
 */
export const PageDataFormField = <P extends Record<string, any>>({
  props,
  WrappedComponent,
  fieldName,
  errorsText,
}: PageDataFormFieldProps<P>) => {
  const formContext = useSafeFormContext();
  const rhfContext = useSafeRHFContext();
  const getCurrentValue = () => getCurrentValueFromProps(props);

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

  const validateNewValue = (e: React.ChangeEvent, newValue: any) => {
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

  if (!rhfContext) {
    return <WrappedComponent {...props} />;
  }

  return (
    <Controller
      name={fieldName}
      control={rhfContext.control}
      defaultValue={getCurrentValueFromRedux()}
      render={({ field }) => {
        const enhancedOnChange = createEnhancedOnChange({
          field,
          props,
          dispatch,
          formId: formContext?.formId,
          fieldName,
          formState,
          commitFormState,
          extractNewValue: extractPageDataValue,
        });

        const enhancedOnBlur = createEnhancedOnBlur({
          field,
          props,
          dispatch,
          formId: formContext?.formId,
          fieldName,
          validateNewValue,
          errorsText,
        });

        const enhancedProps = applyEnhancedProps(
          props,
          field,
          enhancedOnChange,
          enhancedOnBlur,
        );

        return <WrappedComponent {...enhancedProps} />;
      }}
    />
  );
};
