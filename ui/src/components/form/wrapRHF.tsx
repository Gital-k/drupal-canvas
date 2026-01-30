import Ajv from 'ajv';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { ComponentFormField } from '@/components/form/wrapRHF-fields/ComponentFormField';
import { PageDataFormField } from '@/components/form/wrapRHF-fields/PageDataFormField';
import { useAjaxFieldRegistration } from '@/components/form/wrapRHF-hooks/useAjaxFieldRegistration';
import { useFormBuildIdSync } from '@/components/form/wrapRHF-hooks/useFormBuildIdSync';
import {
  dispatchFieldValue,
  getCurrentValueFromProps,
} from '@/components/form/wrapRHF-utils';
import { useSafeFormContext, useSafeRHFContext } from '@/contexts/FormContext';
import { selectFieldError } from '@/features/form/formStateSlice';

import type * as React from 'react';
import type { FormId } from '@/features/form/formStateSlice';

const ajv = new Ajv();
const errorsText = (errors?: any) => ajv.errorsText(errors || null);

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
  WrappedComponent: React.ComponentType<P>,
): React.ComponentType<P> => {
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
          <PageDataFormField {...sharedProps} errorsText={errorsText} />
        )}
        {formContext?.formId === 'component_instance_form' && (
          <ComponentFormField {...sharedProps} errorsText={errorsText} />
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
