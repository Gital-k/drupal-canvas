import { createSelector } from '@reduxjs/toolkit';
import { createApi } from '@reduxjs/toolkit/query/react';

import { setPostPreviewCompleted } from '@/components/review/PublishReview.slice';
import { setLayoutModel } from '@/features/layout/layoutModelSlice';
import { setHtml } from '@/features/pagePreview/previewSlice';
import {
  baseQueryWithAutoSaves,
  popCanvasLayoutRequest,
  pushCanvasLayoutRequest,
} from '@/services/baseQuery';
import { pendingChangesApi } from '@/services/pendingChangesApi';
import { handleAutoSavesHashUpdate } from '@/utils/autoSaves';

import type { RootState } from '@/app/store';
import type {
  ComponentModel,
  EvaluatedComponentModel,
} from '@/features/layout/layoutModelSlice';
import type { EditorFrameContext } from '@/features/ui/uiSlice';
import type { ConflictError } from '@/services/pendingChangesApi';
import type { AutoSavesHash } from '@/types/AutoSaves';

export type UpdateComponentResultType = {
  html: string;
  layout: any;
  model: any;
  autoSaves: AutoSavesHash;
  errors?: Array<ConflictError>;
};

export type UpdateComponentQueryArg = {
  type: EditorFrameContext;
  componentInstanceUuid: string;
  componentType: string;
  model: Omit<ComponentModel, 'name'> | Omit<EvaluatedComponentModel, 'name'>;
  langcode?: string;
  templateBundle?: string;
  templateViewMode?: string;
};

      export const previewApi = createApi({
        reducerPath: 'previewApi',
        baseQuery: baseQueryWithAutoSaves,
        endpoints: (builder) => ({
            postPreview: builder.mutation<
        { html: string; autoSaves: AutoSavesHash },
        {
          entityType: string;
          entityId: string;
          langcode: string;
          layout: any;
          model: any;
          entity_form_fields: any;
        }
      >({
        query: ({ entityType, entityId, langcode, ...body }) => ({
          url: `canvas/api/v0/layout/${entityType}/${entityId}/${langcode}`,
          method: 'POST',
          body,
        }),
        async onQueryStarted(arg, { dispatch, queryFulfilled }) {
          const { data, meta } = await queryFulfilled;
          const { html, autoSaves } = data;
          dispatch(
            pendingChangesApi.util.invalidateTags([
              { type: 'PendingChanges', id: 'LIST' },
            ]),
          );
          dispatch(setHtml(html));
          handleAutoSavesHashUpdate(dispatch, autoSaves, meta);
          dispatch(setPostPreviewCompleted(true));
        },
      }),
      updateComponent: builder.mutation<UpdateComponentResultType, UpdateComponentQueryArg>({
        query: ({ type, langcode, templateBundle, templateViewMode, ...body }) => {
          let url = '';
          if (type === 'entity') {
            if (!langcode) {
              throw new Error('langcode is required for entity updateComponent');
            }
            url = `canvas/api/v0/layout/${body.componentType}/${body.componentInstanceUuid}/${langcode}`;
          } else if (type === 'template') {
            if (!templateBundle || !templateViewMode) {
              throw new Error('templateBundle and templateViewMode are required for template updateComponent');
            }
            if (!langcode) {
              throw new Error('langcode is required for template updateComponent');
            }
            url = `canvas/api/v0/layout-content-template/${body.componentType}.${templateBundle}.${templateViewMode}/${body.componentInstanceUuid}/${langcode}`;
          }
          return {
            url,
            method: 'PATCH',
            body,
          };
        },
      async onQueryStarted(body, { dispatch, queryFulfilled }) {
        pushCanvasLayoutRequest();
        const { data, meta } = await queryFulfilled;
        popCanvasLayoutRequest();
        const { html, layout, model, autoSaves } = data;
        dispatch(
          pendingChangesApi.util.invalidateTags([{ type: 'PendingChanges', id: 'LIST' }]),
        );
        dispatch(setHtml(html));
        handleAutoSavesHashUpdate(dispatch, autoSaves, meta);
        dispatch(setLayoutModel({ layout, model, updatePreview: false }));
      },
    }),
  }),
});

export const { usePostPreviewMutation, useUpdateComponentMutation } =
  previewApi;

// A selector that returns the current updateComponent mutation loading state
// given a component ID.
// For each API endpoint, RTK Query makes a .select method available allowing
// you to select the current state given a cache key. This returns a new
// function every time. As a result we must use createSelector to memoize it.
// @see https://redux-toolkit.js.org/rtk-query/usage/usage-without-react-hooks
const createUpdateComponentSelector = createSelector(
  (componentInstanceId: string) => componentInstanceId,
  (componentInstanceId) =>
    previewApi.endpoints.updateComponent.select({
      fixedCacheKey: componentInstanceId,
      requestId: undefined,
    }),
);

// A selector that can be called from anywhere in the code base to
// determine the current update mutation loading state given a component
// instance ID. As createUpdateComponentSelector is memoized, we must also use
// createSelector here so that the subsequent selector is memoised.
// Returns false if componentInstanceId is undefined.
// @see https://redux-toolkit.js.org/rtk-query/usage/usage-without-react-hooks
// @see https://redux.js.org/tutorials/fundamentals/part-7-standard-patterns#memoizing-selectors-with-createselector
export const selectUpdateComponentLoadingState: (
  state: RootState,
  componentInstanceId: string | undefined,
) => boolean = createSelector(
  [
    (state: RootState) => state,
    (_state: RootState, componentInstanceId: string | undefined) =>
      componentInstanceId,
  ],
  (state, componentInstanceId): boolean => {
    if (!componentInstanceId) {
      return false;
    }
    const selectUpdateComponentSelector =
      createUpdateComponentSelector(componentInstanceId);
    return selectUpdateComponentSelector(state).isLoading;
  },
);
