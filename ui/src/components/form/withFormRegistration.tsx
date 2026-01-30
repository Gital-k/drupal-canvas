import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { useSafeFormContext, useSafeRHFContext } from '@/contexts/FormContext';
import { selectFieldError } from '@/features/form/formStateSlice';
import { useAjaxFieldRegistration } from '@/hooks/useAjaxFieldRegistration';
import { useFormBuildIdSync } from '@/hooks/useFormBuildIdSync';

import { ComponentFormField, PageDataFormField } from './fields';
import { dispatchFieldValue } from './utils/formFieldHelpers';

import type { ComponentType } from 'react';
import type * as React from 'react';
import type { FormId } from '@/features/form/formStateSlice';

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

    const fieldError = useAppSelector((state) =>
      selectFieldError(state, {
        formId: formContext?.formId as FormId,
        fieldName,
      }),
    );
    // Get the current value from various possible sources
    const getCurrentValue = () => {
      if (props.options && props.options.some((opt: any) => opt.selected)) {
        return props.options.find((opt: any) => opt.selected).value;
      }
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
    useFormBuildIdSync(fieldName, formContext?.formId, rhfContext, dispatch);

    // For AJAX-added inputs, register them dynamically with react-hook-form
    // This ensures they become controlled inputs like all other form inputs
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
      getCurrentValue,
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

  WithFormRegistration.displayName = `withFormRegistration(${WrappedComponent.displayName || WrappedComponent.name || 'Component'})`;

  return WithFormRegistration;
};

export default withFormRegistration;
