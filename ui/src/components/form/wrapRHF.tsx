import React from 'react';
import Ajv from 'ajv';

import { useAppDispatch } from '@/app/hooks';
import { ComponentFormField } from '@/components/form/wrapRHF-fields/ComponentFormField';
import { PageDataFormField } from '@/components/form/wrapRHF-fields/PageDataFormField';
import { useAjaxFieldRegistration } from '@/components/form/wrapRHF-hooks/useAjaxFieldRegistration';
import { useFormBuildIdSync } from '@/components/form/wrapRHF-hooks/useFormBuildIdSync';
import { getCurrentValueFromProps } from '@/components/form/wrapRHF-utils';
import { useSafeFormContext, useSafeRHFContext } from '@/contexts/FormContext';

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
    // Note: data-canvas-name is used for radios and potentially other inputs
    // where value management is not specific to one form field.
    const fieldName =
      props.attributes?.name ||
      props.attributes?.['data-canvas-name'] ||
      props.name ||
      props.attributes?.id;

    // Get the current value from various possible sources
    const getCurrentValue = () => getCurrentValueFromProps(props);

    // Special handling for the form_build_id which can be updated by a core
    // AJAX callback.
    useFormBuildIdSync(fieldName, formContext?.formId, rhfContext, dispatch);

    // Checks for fields added via AJAX, thus not registered with
    // react-hook-form on initialization.
    useAjaxFieldRegistration(fieldName, rhfContext, getCurrentValue);

    // If there's no react-hook-form context, then the input should be rendered
    // without enhancement. Also, if there's no field name, it can't be
    // registered either.
    if (!rhfContext || !fieldName) {
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
      </>
    );
  };

  WrapRHF.displayName = `wrapRHF(${WrappedComponent.displayName || WrappedComponent.name || 'Component'})`;

  // Wrap with React.memo to prevent re-renders when props haven't actually changed
  // Use deep comparison since props objects are often recreated with same values
  return React.memo(WrapRHF, (prevProps, nextProps) => {
    // Quick reference check first
    if (prevProps === nextProps) {
      return true; // Skip re-render
    }

    // Deep compare props
    const prevKeys = Object.keys(prevProps);
    const nextKeys = Object.keys(nextProps);

    if (prevKeys.length !== nextKeys.length) {
      return false; // Allow re-render
    }

    // Compare each top-level prop
    for (const key of prevKeys) {
      const prevValue = prevProps[key];
      const nextValue = nextProps[key];

      // Reference equality check
      if (prevValue === nextValue) {
        continue;
      }

      // Special handling for attributes object - deep compare
      if (
        key === 'attributes' &&
        typeof prevValue === 'object' &&
        typeof nextValue === 'object'
      ) {
        if (prevValue === null || nextValue === null) {
          return false; // Allow re-render
        }

        const prevAttrKeys = Object.keys(prevValue);
        const nextAttrKeys = Object.keys(nextValue);

        if (prevAttrKeys.length !== nextAttrKeys.length) {
          return false; // Allow re-render
        }

        // Compare each attribute
        for (const attrKey of prevAttrKeys) {
          if (prevValue[attrKey] !== nextValue[attrKey]) {
            return false; // Allow re-render
          }
        }

        continue; // Attributes are equal, check next prop
      }

      // For other props, simple inequality means re-render
      return false; // Allow re-render
    }

    // All props are equal
    return true; // Skip re-render
  }) as unknown as React.ComponentType<P>;
};

export default wrapRHF;
