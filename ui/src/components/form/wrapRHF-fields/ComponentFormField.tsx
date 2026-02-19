import React, { useCallback, useMemo, useRef } from 'react';
import { Controller } from 'react-hook-form';

import { useAppSelector } from '@/app/hooks';
import {
  useSafeFormContext,
  useSafeRHFContext,
} from '@/components/form/contexts/FormContext';
import {
  getPropSchemas,
  getPropsValues,
  propInputData,
  shouldSkipPropValidation,
  toPropName,
  validateProp,
} from '@/components/form/formUtil';
import { useComponentFormInputInfo } from '@/components/form/wrapRHF-hooks/useComponentFormInputInfo';
import { useComponentInitialValue } from '@/components/form/wrapRHF-hooks/useComponentInitialValue';
import { useDebouncedFormState } from '@/components/form/wrapRHF-hooks/useDebouncedFormState';
import { useUndoRedoSync } from '@/components/form/wrapRHF-hooks/useUndoRedoSync';
import {
  applyEnhancedProps,
  comparePropsWithAttributes,
  createEnhancedOnBlur,
  createEnhancedOnChange,
  getCurrentValueFromProps,
} from '@/components/form/wrapRHF-utils';
import { FORM_TYPES } from '@/features/form/constants';
import {
  selectFieldError,
  selectFormValues,
} from '@/features/form/formStateSlice';
import { parseValue } from '@/utils/function-utils';

import { createComponentFormStateHandler } from './componentFormHandlers';
import { FieldErrorDisplay } from './shared-utilities';

import type { ComponentType } from 'react';
import type * as ReactType from 'react';
import type { PropsValues } from '@drupal-canvas/types';
import type { FormId } from '@/features/form/formStateSlice';
import type { ComponentModels } from '@/features/layout/layoutModelSlice';

interface ComponentFormFieldProps<P> {
  props: P;
  WrappedComponent: ComponentType<P>;
  fieldName: string;
  errorsText: (errors?: any) => string;
}

/**
 * Component form field - handles component instance form inputs
 */
export const ComponentFormField = <P extends Record<string, any>>({
  props,
  WrappedComponent,
  fieldName,
  errorsText,
}: ComponentFormFieldProps<P>) => {
  const formContext = useSafeFormContext();
  const rhfContext = useSafeRHFContext();
  const getCurrentValue = () => getCurrentValueFromProps(props);
  const { attributes } = props;

  // Create a memoized version of WrappedComponent with deep comparison
  // This prevents re-renders when attributes have same values but different reference
  const MemoizedWrappedComponent = useMemo(
    () => React.memo(WrappedComponent, comparePropsWithAttributes),
    [WrappedComponent],
  ) as unknown as React.ComponentType<P>;

  // Get component form data and utilities.
  const {
    editorFrameContext,
    dispatch,
    polledBackgroundUpdate,
    components,
    transforms,
    inputAndUiData,
    selectedComponentType,
    version,
    selectedComponent,
    component,
    patchComponent,
    propName,
    isScalarProp,
  } = useComponentFormInputInfo(fieldName);

  const formState = useAppSelector((state) =>
    selectFormValues(state, FORM_TYPES.COMPONENT_INSTANCE_FORM),
  );

  const formStateToLayoutModelStore = createComponentFormStateHandler({
    dispatch,
    transforms,
    selectedComponentType,
    selectedComponent,
    propName,
    isScalarProp,
    polledBackgroundUpdate,
    component,
    patchComponent,
    editorFrameContext,
    version,
  });

  const debounceFormStateToLayoutModelStore = useDebouncedFormState(
    formStateToLayoutModelStore,
  );

  const updateLayoutModelStore = (newFormState: PropsValues) => {
    const elementType = attributes.type || attributes['data-canvas-type'];

    // We don't debounce updates for code components where the prop is scalar -
    // but all other components/props should be debounced to avoid thrashing the
    // server with multiple PATCH requests.
    const shouldDebounce =
      !isScalarProp ||
      components?.[selectedComponentType]?.source !== 'Code component' ||
      !['checkbox', 'radio'].includes(elementType as string);
    if (shouldDebounce) {
      debounceFormStateToLayoutModelStore(
        newFormState,
        inputAndUiData,
        formState,
      );
    } else {
      formStateToLayoutModelStore(newFormState, inputAndUiData, formState);
    }
  };

  const propsOverrides: { options?: object[] } = {};

  const { multipleInputsSingleValue } = propInputData(
    formState,
    inputAndUiData,
  );

  const parseNewValue = (e: ReactType.ChangeEvent) => {
    const schemas = getPropSchemas(inputAndUiData);
    const rawValue = parseValue(
      (e.target as HTMLInputElement | HTMLSelectElement).value,
      e.target as HTMLInputElement,
      schemas?.[propName],
    );
    const fieldName = (e.target as HTMLInputElement | HTMLSelectElement).name;
    if (
      // If there are no transforms, we cannot use them, just return the raw
      // value. Note that the 'undefined' check here is technically not required
      // because at this point the form has loaded and the value will be
      // defined, it is required to satisfy type-checks.
      transforms === undefined ||
      Object.entries(transforms).length === 0 ||
      // Or if there are no transforms for this prop, don't bother with the
      // overhead of transforms.
      !(propName in transforms) ||
      // Or if the prop relies on multiple input fields.
      multipleInputsSingleValue.includes(propName)
    ) {
      return rawValue;
    }
    const { propsValues: values } = getPropsValues(
      { [fieldName]: rawValue },
      inputAndUiData,
      transforms,
    );
    return propName in values ? values[propName] : rawValue;
  };

  const validateNewValue = (e: ReactType.ChangeEvent, newValue: any) => {
    const target = e.target as HTMLInputElement;
    if (
      !shouldSkipPropValidation(fieldName, target, inputAndUiData, newValue)
    ) {
      const [valid, validate] = validateProp(
        toPropName(fieldName, selectedComponent),
        newValue,
        inputAndUiData,
      );
      return {
        valid,
        errors: validate?.errors || null,
      };
    }
    return { valid: true, errors: null };
  };

  if (props.options && !props.attributes.required) {
    // If an element has a `_none` value as one of the render array options,
    // and there is no value stored for this prop, then we set that _none as the
    // selected option. This logic is only necessary in the component instance
    // form, hence it being located here.
    if (
      props.options.some((option: PropsValues) => option.value === '_none') &&
      !inputAndUiData?.model?.[selectedComponent as keyof ComponentModels]
        ?.resolved?.[propName]
    ) {
      propsOverrides.options = props.options.map((option: PropsValues) =>
        option.value === '_none'
          ? { ...option, selected: true }
          : { ...option, selected: false },
      );
    }
  }

  useUndoRedoSync(fieldName, getCurrentValue, rhfContext, 'layoutModel');
  useComponentInitialValue(
    dispatch,
    fieldName,
    formContext?.formId ?? null,
    getCurrentValue,
    propName,
  );

  // Create stable callback references using refs
  const latestPropsRef = useRef({
    props,
    formState,
    dispatch,
    formContext,
    parseNewValue,
    validateNewValue,
    errorsText,
    updateLayoutModelStore,
    field: null as any,
  });
  latestPropsRef.current = {
    ...latestPropsRef.current,
    props,
    formState,
    dispatch,
    formContext,
    parseNewValue,
    validateNewValue,
    errorsText,
    updateLayoutModelStore,
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
        updateStore: latest.updateLayoutModelStore,
        extractNewValue: latest.parseNewValue,
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
    return <MemoizedWrappedComponent {...props} />;
  }

  return (
    <Controller
      name={fieldName}
      control={rhfContext.control}
      defaultValue={getCurrentValue()}
      rules={{
        // RHF validation for immediate UI feedback (doesn't block auto-save)
        validate: {
          jsonSchemaValidation: (value) => {
            // Create a mock target for shouldSkipPropValidation check
            const mockTarget = document.createElement('input');
            mockTarget.value = value || '';
            mockTarget.required = !!props.attributes?.required;

            if (
              shouldSkipPropValidation(
                fieldName,
                mockTarget,
                inputAndUiData,
                value,
              )
            ) {
              return true;
            }

            const [valid, validate] = validateProp(
              toPropName(fieldName, selectedComponent),
              value,
              inputAndUiData,
            );

            if (!valid) {
              return errorsText(validate?.errors || null);
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

        // Track if memoization is working
        const prevEnhancedPropsRef = useRef<any>(null);

        // Memoize the enhanced props to prevent creating new attributes object on every render
        const enhancedProps = useMemo(() => {
          const newEnhancedProps = applyEnhancedProps(
            { ...props, ...propsOverrides },
            field,
            stableOnChange,
            stableOnBlur,
            !!displayError,
          );
          prevEnhancedPropsRef.current = newEnhancedProps;
          return newEnhancedProps;
          // eslint-disable-next-line react-hooks/exhaustive-deps
        }, [field, field.value, stableOnChange, stableOnBlur, !!displayError]);

        return (
          <>
            <MemoizedWrappedComponent {...(enhancedProps as any)} />
            <FieldErrorDisplay
              blockingError={blockingError}
              fieldState={fieldState}
            />
          </>
        );
      }}
    />
  );
};
