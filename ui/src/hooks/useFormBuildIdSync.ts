import { useEffect } from 'react';

import type { useAppDispatch } from '@/app/hooks';
import { AJAX_UPDATE_FORM_BUILD_ID_EVENT } from '@/types/Ajax';

import { dispatchFieldValue } from '../components/form/utils/formFieldHelpers';

import type { UseFormReturn } from 'react-hook-form';
import type { FormId } from '@/features/form/formStateSlice';
import type { AjaxUpdateFormBuildIdEvent } from '@/types/Ajax';

/**
 * Hook that handles form_build_id updates from AJAX callbacks
 *
 * Special handling for the form_build_id field which can be updated by an AJAX
 * callback without using hyperscriptify to render a new React component.
 *
 * @param fieldName - The name of the field
 * @param formId - The form ID
 * @param rhfContext - React Hook Form context
 * @param dispatch - Redux dispatch function
 */
export const useFormBuildIdSync = (
  fieldName: string,
  formId: FormId | undefined,
  rhfContext: UseFormReturn | null,
  dispatch: ReturnType<typeof useAppDispatch>,
) => {
  useEffect(() => {
    if (fieldName !== 'form_build_id' || !rhfContext || !formId) {
      if (fieldName === 'form_build_id') {
        console.log('DISCARD BUILD ID', { rhfContext, formId });
      }
      return;
    }

    // Listen for changes to the form build ID so we can update that in
    // our form state and value.
    const formBuildIdListener = (e: AjaxUpdateFormBuildIdEvent) => {
      console.log(
        `BUILD ID EVENT ${formId} ${fieldName}, new: ${e.detail.newFormBuildId}`,
      );
      if (e.detail.formId === formId) {
        dispatchFieldValue(
          dispatch,
          formId,
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
  }, [dispatch, fieldName, formId, rhfContext]);
};
