import { useEffect } from 'react';

import type { UseFormReturn } from 'react-hook-form';

/**
 * Hook that dynamically registers AJAX-added inputs with React Hook Form
 *
 * For AJAX-added inputs, register them dynamically with react-hook-form.
 * This ensures they become controlled inputs like all other form inputs.
 *
 * @param fieldName - The name of the field
 * @param rhfContext - React Hook Form context
 * @param getCurrentValue - Function to get the current value
 */
export const useAjaxFieldRegistration = (
  fieldName: string | undefined,
  rhfContext: UseFormReturn | null,
  getCurrentValue: () => any,
) => {
  useEffect(() => {
    if (!rhfContext || !fieldName) {
      if (!rhfContext || !fieldName) {
        console.log('🔴 AJAX Input Registration Skipped:', {
          rhfContext,
          fieldName,
        });
        setTimeout(() => {
          console.log('🔴 500 ms later:', { rhfContext, fieldName });
        }, 500);
      }
      return;
    }

    const currentValue = getCurrentValue();

    // Check if field is already registered
    const fieldState = rhfContext.getFieldState(fieldName);

    // If field is not yet registered (AJAX-added), register it now
    if (!fieldState || fieldState.invalid === undefined) {
      console.log('🟢 Registering AJAX-added input:', fieldName);
      rhfContext.register(fieldName);
      // Set initial value
      rhfContext.setValue(fieldName, currentValue, {
        shouldValidate: false,
        shouldDirty: false,
        shouldTouch: false,
      });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [rhfContext, fieldName]);
};
