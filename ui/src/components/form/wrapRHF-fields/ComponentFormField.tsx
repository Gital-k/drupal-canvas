import React, { useCallback, useEffect, useMemo, useRef } from 'react';
import { debounce } from 'lodash';
import { Controller } from 'react-hook-form';

import { useAppSelector } from '@/app/hooks';
import {
  ComponentPreviewUpdateEvent,
  DEBOUNCE_TIMEOUT,
  getPropSchemas,
  getPropsValues,
  POLLED_BACKGROUND_TIMEOUT,
  propInputData,
  shouldSkipPropValidation,
  syncPropSourcesToResolvedValues,
  toPropName,
  validateProp,
} from '@/components/form/formUtil';
import { useComponentFormInputInfo } from '@/components/form/wrapRHF-hooks/useComponentFormInputInfo';
import { useComponentInitialValue } from '@/components/form/wrapRHF-hooks/useComponentInitialValue';
import { useUndoRedoSync } from '@/components/form/wrapRHF-hooks/useUndoRedoSync';
import {
  applyEnhancedProps,
  createEnhancedOnBlur,
  createEnhancedOnChange,
  getCurrentValueFromProps,
} from '@/components/form/wrapRHF-utils';
import { useSafeFormContext, useSafeRHFContext } from '@/contexts/FormContext';
import { FORM_TYPES } from '@/features/form/constants';
import {
  selectFieldError,
  selectFormValues,
} from '@/features/form/formStateSlice';
import { isEvaluatedComponentModel } from '@/features/layout/layoutModelSlice';
import { setPreviewBackgroundUpdate } from '@/features/pagePreview/previewSlice';
import { flaggedForRemoval, parseValue } from '@/utils/function-utils';

import type { ComponentType } from 'react';
import type * as ReactType from 'react';
import type { PropsValues } from '@drupal-canvas/types';
import type { FormId } from '@/features/form/formStateSlice';
import type { ComponentModels } from '@/features/layout/layoutModelSlice';
import type { InputUIData } from '@/types/Form';

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

  // Get all component form data and utilities from the hook
  // Note: Currently unused variables are available for component-specific logic
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

  const formStateToStore = (
    newFormState: PropsValues,
    newInputAndUiData: InputUIData,
    currFormState: PropsValues,
  ) => {
    // Apply (client-side) transforms for form state.
    const { propsValues: values, selectedModel } = getPropsValues(
      { ...currFormState, ...newFormState },
      newInputAndUiData,
      // If transforms are not available (typically because TransformsContext is
      // not available to the input), fall back to the transforms stored in the
      // global window object.
      Object.keys(transforms || {}).length > 0
        ? transforms
        : (window as any)._canvasTransforms[selectedComponentType],
    );
    // And then send data to backend - this will:
    // a) Trigger server-side validation/transformation (massaging of widget values)
    // b) Update both the preview and the model - see the pessimistic update
    //    in onQueryStarted in preview.ts
    // @see \Drupal\Core\Field\WidgetInterface::massageFormValues()
    const resolved = { ...selectedModel.resolved, ...values };

    // Check the object for any values that are flagged for removal. Note that
    // removal flagging is not necessary for all prop types. It is used for
    // props with complex prop shapes where the empty-indicating value is nested
    // with the structure.
    Object.keys(values).forEach((prop) => {
      if (flaggedForRemoval(values[prop]) && component?.propSources?.[prop]) {
        // If the prop is optional, it can be removed.
        if (!component.propSources[prop]?.required) {
          if (isEvaluatedComponentModel(selectedModel)) {
            // The source value can also be updated to empty when permitted.
            if (!Object.isFrozen(selectedModel.source[prop])) {
              selectedModel.source[prop].value = [];
            }
          }
          resolved[prop] = [];
        } else {
          // If the prop is required, we need to set it back to the default.
          resolved[prop] = component.propSources[prop].default_values.resolved;
        }
      }
    });

    let backgroundPreviewUpdate = false;
    if (isScalarProp) {
      // Fire an event to allow listeners to attempt real-time updates.
      const PreviewUpdateEvent = new ComponentPreviewUpdateEvent(
        selectedComponent,
        propName,
        resolved[propName],
      );
      document.dispatchEvent(PreviewUpdateEvent);
      dispatch(
        // Flag if any listeners were able to perform a real-time update.
        setPreviewBackgroundUpdate(
          PreviewUpdateEvent.getPreviewBackgroundUpdate(),
        ),
      );
      backgroundPreviewUpdate = PreviewUpdateEvent.getPreviewBackgroundUpdate();
    }

    if (isEvaluatedComponentModel(selectedModel) && component) {
      const updateBackend = () => {
        patchComponent({
          type: editorFrameContext,
          componentInstanceUuid: selectedComponent,
          componentType: `${selectedComponentType}@${version}`,
          model: {
            source: syncPropSourcesToResolvedValues(
              selectedModel.source,
              component,
              resolved,
            ),
            resolved,
          },
        });
      };
      if (backgroundPreviewUpdate) {
        if (polledBackgroundUpdate.current !== null) {
          clearTimeout(polledBackgroundUpdate.current);
        }
        // If we're doing a background update, debounce that so we don't make
        // multiple requests for a single update. If we're not doing a
        // background preview update, we should schedule this immediately -
        // debouncing in InputBehaviors will handle preventing this firing too
        // many times in succession.
        polledBackgroundUpdate.current = setTimeout(
          updateBackend,
          POLLED_BACKGROUND_TIMEOUT,
        ) as any as number;
        return;
      }
      updateBackend();
      return;
    }
    patchComponent({
      type: editorFrameContext,
      componentInstanceUuid: selectedComponent,
      componentType: `${selectedComponentType}@${version}`,
      model: {
        ...selectedModel,
        resolved,
      },
    });
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

  const commitFormState = (newFormState: PropsValues) => {
    const elementType = attributes.type || attributes['data-canvas-type'];

    // We don't debounce updates for code components where the prop is scalar -
    // but all other components/props should be debounced to avoid thrashing the
    // server with multiple PATCH requests.
    const shouldDebounce =
      !isScalarProp ||
      components?.[selectedComponentType]?.source !== 'Code component' ||
      !['checkbox', 'radio'].includes(elementType as string);
    if (shouldDebounce) {
      debounceFormStateToStore(newFormState, inputAndUiData, formState);
    } else {
      formStateToStore(newFormState, inputAndUiData, formState);
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
    commitFormState,
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

          if (
            prevEnhancedPropsRef.current &&
            prevEnhancedPropsRef.current !== newEnhancedProps
          ) {
            // console.log(`[ComponentFormField ${fieldName}] useMemo created NEW enhancedProps`);
            if (
              prevEnhancedPropsRef.current.attributes !==
              newEnhancedProps.attributes
            ) {
              // console.log(`[ComponentFormField ${fieldName}]   → attributes object is different`);
            }
          } else if (prevEnhancedPropsRef.current === newEnhancedProps) {
            // console.log(`[ComponentFormField ${fieldName}] useMemo returned SAME enhancedProps`);
          }

          prevEnhancedPropsRef.current = newEnhancedProps;
          return newEnhancedProps;
          // eslint-disable-next-line react-hooks/exhaustive-deps
        }, [field, field.value, stableOnChange, stableOnBlur, !!displayError]);

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
