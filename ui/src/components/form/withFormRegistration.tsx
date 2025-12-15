import { useEffect, useRef } from 'react';
import Ajv from 'ajv';
import { debounce } from 'lodash';
import { Controller } from 'react-hook-form';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  ComponentPreviewUpdateEvent,
  getPropSchemas,
  getPropsValues,
  propInputData,
  shouldSkipPropValidation,
  toPropName,
  validateProp,
} from '@/components/form/formUtil';
import {
  DEBOUNCE_TIMEOUT,
  POLLED_BACKGROUND_TIMEOUT,
} from '@/components/form/inputBehaviors';
import { syncPropSourcesToResolvedValues } from '@/components/form/InputBehaviorsComponentPropsForm';
import { useSafeFormContext, useSafeRHFContext } from '@/contexts/FormContext';
import { FORM_TYPES } from '@/features/form/constants';
import {
  selectFormValues,
  setFieldError,
  setFieldValue,
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
import { useComponentFormInputInfo } from '@/hooks/useComponentFormInputInfo';
import { usePageDataFormInputInfo } from '@/hooks/usePageDataFormInputInfo';
import { AJAX_UPDATE_FORM_BUILD_ID_EVENT } from '@/types/Ajax';
import { flaggedForRemoval, parseValue } from '@/utils/function-utils';
import { isAjaxing } from '@/utils/isAjaxing';

import type { ComponentType } from 'react';
import type * as React from 'react';
import type { ErrorObject } from 'ajv/dist/types';
import type { FormId } from '@/features/form/formStateSlice';
import type { ComponentModels } from '@/features/layout/layoutModelSlice';
import type { AjaxUpdateFormBuildIdEvent } from '@/types/Ajax';
import type { InputUIData, PropsValues } from '@/types/Form';

const ajv = new Ajv();

type ValidationResult = {
  valid: boolean;
  errors?: null | ErrorObject[];
  errorMessage?: string;
  skipEarlyReturn?: boolean;
};

const dispatchFieldValue = (
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

// Helper function to dispatch field error updates
const dispatchFieldError = (
  dispatch: ReturnType<typeof useAppDispatch>,
  formId: FormId,
  fieldName: string,
  validationResult: ValidationResult,
) => {
  dispatch(
    setFieldError({
      type: 'error',
      message:
        validationResult.errorMessage ||
        ajv.errorsText(validationResult.errors),
      formId,
      fieldName,
    }),
  );
};

// Shared types
interface FormFieldProps<P> {
  props: P;
  WrappedComponent: ComponentType<P>;
  fieldName: string;
  getCurrentValue: () => any;
}

/**
 * Helper to extract new value from various event types
 */
const extractValue = (e: any): any => {
  if (typeof e === 'boolean') {
    return e;
  } else if (e?.target?.type === 'checkbox') {
    return e.target.checked;
  } else if (e?.target?.value !== undefined) {
    return e.target.value;
  } else if (e?.target?.checked !== undefined) {
    return e.target.checked;
  }
  return e;
};

/**
 * Component form field - handles component instance form inputs
 */
const ComponentFormField = <P extends Record<string, any>>({
  props,
  WrappedComponent,
  fieldName,
  getCurrentValue,
}: FormFieldProps<P>) => {
  const formContext = useSafeFormContext();
  const rhfContext = useSafeRHFContext();
  const formId = 'component_instance_form';
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

  const shouldUpdateFormState = (
    e: React.ChangeEvent,
    validationResult: ValidationResult,
  ) => {
    if (
      typeof e?.target?.hasAttribute === 'function' &&
      e.target.hasAttribute('data-canvas-no-update')
    ) {
      return false;
    }
    return validationResult.valid || validationResult.skipEarlyReturn;
  };
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
        const patchPayload = {
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
        };
        console.log('PATCH PAYLOAD:', patchPayload);
        patchComponent(patchPayload);
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
    console.log('TRANSFORMS:', { transforms, propName, rawValue });
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

  // Component form specific hooks
  useEffect(() => {
    if (!formContext?.formId) return;

    const currentValue = getCurrentValue();

    // Update Redux store with initial field value
    dispatchFieldValue(dispatch, formContext.formId, fieldName, currentValue);

    console.log('[ComponentForm] Field registered:', {
      fieldName,
      propName,
      initialValue: currentValue,
      formId: formContext?.formId,
      componentType: WrappedComponent.displayName || WrappedComponent.name,
      isScalarProp,
      selectedComponent,
    });
  }, [
    dispatch,
    fieldName,
    propName,
    formContext?.formId,
    getCurrentValue,
    WrappedComponent,
    isScalarProp,
    selectedComponent,
  ]);

  if (!rhfContext) {
    return <WrappedComponent {...props} />;
  }

  return (
    <Controller
      name={fieldName}
      control={rhfContext.control}
      defaultValue={getCurrentValue()}
      render={({ field }) => {
        const enhancedOnChange = (e: any) => {
          const newValue = parseNewValue(e);

          console.log('[ComponentForm] onChange:', {
            fieldName,
            newValue,
            formId: formContext?.formId,
          });

          // Update react-hook-form state
          field.onChange(newValue);
          dispatchFieldValue(dispatch, formId, fieldName, newValue);

          // Call original onChange
          const originalOnChange = props.attributes?.onChange || props.onChange;
          if (originalOnChange) {
            originalOnChange(e);
          }

          const validationResult = validateNewValue(e, newValue);
          if (!shouldUpdateFormState(e, validationResult)) {
            if (formId) {
              dispatchFieldError(dispatch, formId, fieldName, validationResult);
            }
            return;
          }
          // If no AJAX operations are in progress, update the form state and store.
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
        if (enhancedProps.attributes) {
          (enhancedProps as any).attributes = {
            ...enhancedProps.attributes,
            onChange: enhancedOnChange,
            onBlur: enhancedOnBlur,
            value: field.value,
          };
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

/**
 * Page data form field - handles entity/page form inputs
 */
const PageDataFormField = <P extends Record<string, any>>({
  props,
  WrappedComponent,
  fieldName,
  getCurrentValue,
}: FormFieldProps<P>) => {
  const formContext = useSafeFormContext();
  const rhfContext = useSafeRHFContext();
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  const { dispatch, pageData, latestUndoRedoActionId, formState } =
    usePageDataFormInputInfo();

  const { attributes } = props;
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  const formId = 'page_data_form';

  // Page data form specific hooks
  useEffect(() => {
    if (!formContext?.formId) return;

    const currentValue = getCurrentValue();

    // Update Redux store with initial field value
    dispatchFieldValue(dispatch, formContext.formId, fieldName, currentValue);

    console.log('[PageDataForm] Field registered:', {
      fieldName,
      initialValue: currentValue,
      formId: formContext?.formId,
      componentType: WrappedComponent.displayName || WrappedComponent.name,
    });
  }, [
    dispatch,
    fieldName,
    formContext?.formId,
    getCurrentValue,
    WrappedComponent,
  ]);

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

  const forceUpdateInputValue = (fieldName: string, theNewValue: string) => {
    dispatch(externalUpdateComplete(fieldName));
    const syntheticEvent = {
      target: {
        name: fieldName,
        value: theNewValue,
      },
    } as unknown as React.ChangeEvent<HTMLInputElement>;

    // Ignore TS to avoid adding several properties that are not needed for the
    // way that the onChange handler is used.
    // @ts-ignore
    attributes?.onChange?.(syntheticEvent);
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
      defaultValue={getCurrentValue()}
      render={({ field }) => {
        const enhancedOnChange = (e: any) => {
          const newValue = extractValue(e);

          console.log('[PageDataForm] onChange:', {
            fieldName,
            newValue,
            formId: formContext?.formId,
          });

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
        if (enhancedProps.attributes) {
          (enhancedProps as any).attributes = {
            ...enhancedProps.attributes,
            onChange: enhancedOnChange,
            onBlur: enhancedOnBlur,
            value: field.value,
          };
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
 *   export default withFormRegistration(MyComponent);
 */
export const withFormRegistration = <P extends Record<string, any>>(
  WrappedComponent: ComponentType<P>,
): ComponentType<P> => {
  const WithFormRegistration = (props: P) => {
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

    // Get the current value from various possible sources
    const getCurrentValue = () => {
      if (props.checked !== undefined) return props.checked;
      if (props.value !== undefined) return props.value;
      if (props.attributes?.checked !== undefined)
        return props.attributes.checked;
      if (props.attributes?.value !== undefined) return props.attributes.value;
      if (props.element?.['#value'] !== undefined)
        return props.element['#value'];
      if (props.element?.['#default_value'] !== undefined)
        return props.element['#default_value'];
      return undefined;
    };

    // Special handling for the form_build_id which can be updated by an ajax
    // callback without using hyperscriptify to render a new React component.
    useEffect(() => {
      if (fieldName !== 'form_build_id' || !rhfContext || !formContext) {
        return;
      }
      // Listen for changes to the form build ID so we can update that in
      // our form state and value.
      const formBuildIdListener = (e: AjaxUpdateFormBuildIdEvent) => {
        if (e.detail.formId === formContext?.formId) {
          dispatchFieldValue(
            dispatch,
            formContext.formId,
            fieldName,
            e.detail.newFormBuildId,
          );
          // Update react-hook-form value
          rhfContext.setValue(fieldName, e.detail.newFormBuildId);
        }
      };
      document.addEventListener(
        AJAX_UPDATE_FORM_BUILD_ID_EVENT,
        formBuildIdListener as unknown as EventListener,
      );
      return () => {
        document.removeEventListener(
          AJAX_UPDATE_FORM_BUILD_ID_EVENT,
          formBuildIdListener as unknown as EventListener,
        );
      };
    }, [dispatch, fieldName, formContext, rhfContext]);

    // If no react-hook-form context or no field name, render without enhancement
    if (!rhfContext || !fieldName) {
      return <WrappedComponent {...props} />;
    }

    // Route to appropriate sub-component based on form type
    const sharedProps = {
      props,
      WrappedComponent,
      fieldName,
      getCurrentValue,
    };

    if (formContext?.formId === 'page_data_form') {
      return <PageDataFormField {...sharedProps} />;
    }

    // Default to component form (for component_instance_form and any other forms)
    return <ComponentFormField {...sharedProps} />;
  };

  WithFormRegistration.displayName = `withFormRegistration(${WrappedComponent.displayName || WrappedComponent.name || 'Component'})`;

  return WithFormRegistration;
};

export default withFormRegistration;
