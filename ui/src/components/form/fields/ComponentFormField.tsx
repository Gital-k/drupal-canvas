import { useEffect, useRef } from 'react';
import { debounce } from 'lodash';
import { Controller } from 'react-hook-form';

import { useAppSelector } from '@/app/hooks';
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
import { selectFormValues } from '@/features/form/formStateSlice';
import { isEvaluatedComponentModel } from '@/features/layout/layoutModelSlice';
import { setPreviewBackgroundUpdate } from '@/features/pagePreview/previewSlice';
import { useComponentFormInputInfo } from '@/hooks/useComponentFormInputInfo';
import { useUndoRedoSync } from '@/hooks/useUndoRedoSync';
import { flaggedForRemoval, parseValue } from '@/utils/function-utils';
import { isAjaxing } from '@/utils/isAjaxing';

import {
  dispatchFieldError,
  dispatchFieldValue,
} from '../utils/formFieldHelpers';
import { shouldUpdateFormState } from '../utils/validationHelpers';

import type * as React from 'react';
import type { ComponentModels } from '@/features/layout/layoutModelSlice';
import type { PropsValues } from '@/types/Form';
import type { FormFieldProps, ValidationResult } from '../types/formFieldTypes';

/**
 * Component form field - handles component instance form inputs
 *
 * This component integrates form inputs with React Hook Form for the component
 * instance form. It handles:
 * - Form state synchronization with Redux
 * - Real-time preview updates for scalar props
 * - Debounced backend updates
 * - Undo/redo synchronization
 * - Validation using JSON Schema
 */
export const ComponentFormField = <P extends Record<string, any>>({
  props,
  WrappedComponent,
  fieldName,
  getCurrentValue,
}: FormFieldProps<P>) => {
  const formContext = useSafeFormContext();
  const rhfContext = useSafeRHFContext();
  const formId = 'component_instance_form';
  const { attributes } = props;

  // Get all component form data and utilities from the hook
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

  // Watch for undo/redo actions and sync react-hook-form
  useUndoRedoSync(fieldName, getCurrentValue, rhfContext, 'layoutModel');

  const formStateToStore = (
    newFormState: PropsValues,
    newInputAndUiData: any,
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

  const validateNewValue = (
    e: React.ChangeEvent,
    newValue: any,
  ): ValidationResult => {
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
        const enhancedOnChange = (e: any) => {
          const newValue = parseNewValue(e);

          // Update react-hook-form state
          field.onChange(newValue);
          console.log(
            `'dispatch field value comp ${newValue !== formState[fieldName]}`,
          );
          dispatchFieldValue(dispatch, formId, fieldName, newValue);

          // Call original onChange
          const originalOnChange = props.attributes?.onChange || props.onChange;
          if (originalOnChange) {
            originalOnChange(e);
          }

          const validationResult = validateNewValue(e, newValue);

          if (!shouldUpdateFormState(e, validationResult)) {
            if (formId && validationResult?.valid !== true) {
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
            console.log(`'commit f 484 ${newValue !== formState[fieldName]}`);
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
