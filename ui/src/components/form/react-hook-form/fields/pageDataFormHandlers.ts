import { useEffect } from 'react';

import { setUpdatePreview } from '@/features/layout/layoutModelSlice';
import {
  externalUpdateComplete,
  setPageData,
} from '@/features/pageData/pageDataSlice';

import type { UseFormReturn } from 'react-hook-form';
import type { PropsValues } from '@drupal-canvas/types';
import type { Dispatch } from '@reduxjs/toolkit';

/**
 * Creates a form state handler for page data forms.
 * Handles field filtering and Redux state updates for entity/page forms.
 */
export const createPageDataFormStateHandler = (dispatch: Dispatch) => {
  return (newFormState: PropsValues) => {
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
};

/**
 * Watches for external updates to pageData and syncs them to react-hook-form.
 * When a field is marked for external update, this hook updates RHF and clears the flag.
 */
export const useRespondToPageDataStoreUpdates = (
  fieldName: string,
  pageData: PropsValues | null,
  rhfContext: UseFormReturn | null,
  dispatch: Dispatch,
) => {
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
};
