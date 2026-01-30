import { useEffect, useRef } from 'react';
import Ajv from 'ajv';
import { debounce } from 'lodash';
import { Controller } from 'react-hook-form';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
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
import { useAjaxFieldRegistration } from '@/components/form/wrapRHF-hooks/useAjaxFieldRegistration';
import { useComponentFormInputInfo } from '@/components/form/wrapRHF-hooks/useComponentFormInputInfo';
import { useFormBuildIdSync } from '@/components/form/wrapRHF-hooks/useFormBuildIdSync';
import { usePageDataFormInputInfo } from '@/components/form/wrapRHF-hooks/usePageDataFormInputInfo';
import { useUndoRedoSync } from '@/components/form/wrapRHF-hooks/useUndoRedoSync';
import {
  applyEnhancedProps,
  createEnhancedOnBlur,
  createEnhancedOnChange,
  dispatchFieldValue,
  extractPageDataValue,
  getCurrentValueFromProps,
} from '@/components/form/wrapRHF-utils';
import { useSafeFormContext, useSafeRHFContext } from '@/contexts/FormContext';
import { FORM_TYPES } from '@/features/form/constants';
import {
  selectFieldError,
  selectFormValues,
} from '@/features/form/formStateSlice';
import {
  isEvaluatedComponentModel,
  setUpdatePreview,
} from '@/features/layout/layoutModelSlice';
import {
  externalUpdateComplete,
  setPageData,
} from '@/features/pageData/pageDataSlice';
import { setPreviewBackgroundUpdate } from '@/features/pagePreview/previewSlice';
import { flaggedForRemoval, parseValue } from '@/utils/function-utils';

import type { ComponentType } from 'react';
import type * as React from 'react';
import type { FormId } from '@/features/form/formStateSlice';
import type { ComponentModels } from '@/features/layout/layoutModelSlice';
import type { InputUIData, PropsValues } from '@/types/Form';

const ajv = new Ajv();
const errorsText = (errors?: any) => ajv.errorsText(errors || null);

interface FormFieldProps<P> {
  props: P;
  WrappedComponent: ComponentType<P>;
  fieldName: string;
}

/**
 * Component form field - handles component instance form inputs
 */
const ComponentFormField = <P extends Record<string, any>>({
  props,
  WrappedComponent,
  fieldName,
}: FormFieldProps<P>) => {
  const formContext = useSafeFormContext();
  const rhfContext = useSafeRHFContext();
  const getCurrentValue = () => getCurrentValueFromProps(props);
  // const formValues = useAppSelector((state) =>
  //   selectFormValues(state, formId as FormId),
  // );
  const { attributes } = props;
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

  useUndoRedoSync(fieldName, getCurrentValue, rhfContext, 'layoutModel');

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

  const propsOverrides: { options?: Object[] } = {};

  const { multipleInputsSingleValue } = propInputData(
    formState,
    inputAndUiData,
  );

  const parseNewValue = (e: React.ChangeEvent) => {
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

  const validateNewValue = (e: React.ChangeEvent, newValue: any) => {
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

  // Listen for custom events from nested components (like Hidden) that need to update form state
  useEffect(() => {
    const handleFieldUpdate = (e: CustomEvent) => {
      if (e.detail.fieldName === fieldName) {
        commitFormState({ ...formState, [fieldName]: e.detail.value });
      }
    };
    document.addEventListener(
      'canvas:fieldUpdate',
      handleFieldUpdate as EventListener,
    );
    return () => {
      document.removeEventListener(
        'canvas:fieldUpdate',
        handleFieldUpdate as EventListener,
      );
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [fieldName, formState]);

  // Component form specific hooks
  useEffect(() => {
    if (!formContext?.formId) return;

    const currentValue = getCurrentValue();

    // Update Redux store with initial field value
    dispatchFieldValue(dispatch, formContext.formId, fieldName, currentValue);
    // This intentionally has fewer dependencies - the useEffect exists to populate
    // the initial value after mounting, after which the value is managed by
    // react-hook-form.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dispatch, fieldName, propName]);

  if (!rhfContext) {
    return <WrappedComponent {...props} />;
  }

  return (
    <Controller
      name={fieldName}
      control={rhfContext.control}
      defaultValue={getCurrentValue()}
      render={({ field }) => {
        const enhancedOnChange = createEnhancedOnChange({
          field,
          props,
          dispatch,
          formId: formContext?.formId,
          fieldName,
          formState,
          commitFormState,
          extractNewValue: parseNewValue,
          validateNewValue,
          errorsText,
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

/**
 * Page data form field - handles entity/page form inputs
 */
const PageDataFormField = <P extends Record<string, any>>({
  props,
  WrappedComponent,
  fieldName,
}: FormFieldProps<P>) => {
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
  // const previousUndoRedoIdRef = useRef<string>('');
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

/**
 * Higher-Order Component that integrates react-hook-form with form inputs.
 *
 * This HOC:
 * - Uses Controller to properly connect wrapped components to react-hook-form
 * - Routes to appropriate sub-component based on form type
 * - Maintains backward compatibility with inputs outside FormProvider
 * - Works with any input component that has an `attributes` prop with a `name` field
 *
 * Usage:
 *   export default wrapRHF(MyComponent);
 */
export const wrapRHF = <P extends Record<string, any>>(
  WrappedComponent: ComponentType<P>,
): ComponentType<P> => {
  const WrapRHF = (props: P) => {
    // Get form contexts - they may be null for external forms
    const dispatch = useAppDispatch();
    const formContext = useSafeFormContext();
    const rhfContext = useSafeRHFContext();

    // Extract field name from various possible prop structures
    // Note: data-canvas-name is used by component instance forms
    const fieldName =
      props.attributes?.name ||
      props.attributes?.['data-canvas-name'] ||
      props.name ||
      props.attributes?.id;

    const fieldError = useAppSelector((state) =>
      selectFieldError(state, {
        formId: formContext?.formId as FormId,
        fieldName,
      }),
    );
    // Get the current value from various possible sources
    const getCurrentValue = () => getCurrentValueFromProps(props);

    // Special handling for the form_build_id which can be updated by an ajax
    // callback without using hyperscriptify to render a new React component.
    useFormBuildIdSync(fieldName, formContext?.formId, rhfContext, dispatch);

    useAjaxFieldRegistration(fieldName, rhfContext, getCurrentValue);
    // If no react-hook-form context, the form isn't using RHF - render without enhancement
    // If no field name, we can't register it - render without enhancement
    if (!rhfContext || !fieldName) {
      // For inputs that have lost RHF context (e.g., tabledrag rebuild), convert to uncontrolled
      // This prevents React warnings about controlled inputs without onChange handlers
      if (props.attributes && 'value' in props.attributes) {
        const inputType = props.attributes.type as string;

        // Only convert text-like inputs to defaultValue
        // Submit, button, radio, checkbox, hidden need to keep their value prop
        const shouldConvertToDefaultValue =
          !inputType || // No type means text input
          [
            'text',
            'email',
            'password',
            'tel',
            'url',
            'search',
            'number',
            'date',
            'time',
            'datetime-local',
            'month',
            'week',
            'textarea',
          ].includes(inputType);

        if (shouldConvertToDefaultValue) {
          const { value, ...restAttributes } = props.attributes;

          // Create enhanced onChange that dispatches to Redux even when uncontrolled
          const enhancedOnChange = (e: React.ChangeEvent<HTMLInputElement>) => {
            const newValue = e.target.value;

            // Dispatch to Redux so the value is tracked
            if (formContext?.formId) {
              dispatchFieldValue(
                dispatch,
                formContext.formId,
                fieldName,
                newValue,
              );
            }

            // Call original onChange if it exists
            if (props.attributes?.onChange) {
              props.attributes.onChange(e);
            }
          };

          const uncontrolledProps = {
            ...props,
            attributes: {
              ...restAttributes,
              defaultValue: value,
              onChange: enhancedOnChange,
            },
          };

          return <WrappedComponent {...uncontrolledProps} />;
        }
      }

      return <WrappedComponent {...props} />;
    }

    // Route to appropriate sub-component based on form type
    const sharedProps = {
      props,
      WrappedComponent,
      fieldName,
    };

    return (
      <>
        {formContext?.formId === 'page_data_form' && (
          <PageDataFormField {...sharedProps} />
        )}
        {formContext?.formId === 'component_instance_form' && (
          <ComponentFormField {...sharedProps} />
        )}
        {fieldError && (
          <span data-prop-message>
            {`${fieldError.type === 'error' ? '❌ ' : ''}${fieldError.message}`}
          </span>
        )}
      </>
    );
  };

  WrapRHF.displayName = `wrapRHF(${WrappedComponent.displayName || WrappedComponent.name || 'Component'})`;

  return WrapRHF;
};

export default wrapRHF;
