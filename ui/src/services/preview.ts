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
};

// Queue management for postPreview to prevent race conditions
let activePreviewPromise: Promise<any> | null = null;
let queuedPreviewRequest: {
  layout: any;
  model: any;
  entity_form_fields: any;
} | null = null;

// 500 error retry management
let pending500RetryTimeout: NodeJS.Timeout | null = null;
let has500BeenRetried = false;

export const previewApi = createApi({
  reducerPath: 'previewApi',
  baseQuery: baseQueryWithAutoSaves,
  endpoints: (builder) => ({
    postPreview: builder.mutation<
      { html: string; autoSaves: AutoSavesHash },
      { layout: any; model: any; entity_form_fields: any }
    >({
      query: (body) => ({
        url: 'canvas/api/v0/layout/{entity_type}/{entity_id}',
        method: 'POST',
        body,
      }),
      async onQueryStarted(arg, { dispatch, queryFulfilled, requestId }) {
        const generateRandomString = (length: number) => {
          let result = '';
          const characters =
            'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
          const charactersLength = characters.length;
          for (let i = 0; i < length; i++) {
            result += characters.charAt(
              Math.floor(Math.random() * charactersLength),
            );
          }
          return result;
        };

        const timeStr = () => {
          const now = new Date();
          const timeString = now.toLocaleTimeString();
          const ms = now.getMilliseconds().toString().padStart(3, '0');
          return `${timeString}.${ms}`;
        };

        const executePreview = async () => {
          const id = generateRandomString(8);
          pushCanvasLayoutRequest();
          console.log(`[${id}] (RTK-ID: ${requestId.substring(0, 8)}) preview requested`, timeStr());

          try {
            const { data, meta } = await queryFulfilled;
            // @ts-ignore
            console.log(`[${id}] (${meta?.response?.status}) preview`, timeStr());
            const { html, autoSaves } = data;
            dispatch(
              pendingChangesApi.util.invalidateTags([
                { type: 'PendingChanges', id: 'LIST' },
              ]),
            );
            dispatch(setHtml(html));
            handleAutoSavesHashUpdate(dispatch, autoSaves, meta);
            popCanvasLayoutRequest();
            dispatch(setPostPreviewCompleted(true));

            // Reset retry flag on success
            has500BeenRetried = false;
          } catch (error: any) {
            // Log the error structure to understand what we're dealing with
            console.log(`[${id}] Error caught - checking structure:`, {
              hasError: !!error?.error,
              errorStatus: error?.error?.status,
              statusType: typeof error?.error?.status,
              has500BeenRetried,
              willRetry: error?.error?.status === 500 && !has500BeenRetried,
            });

            // Add specific 500 error handling with retry logic
            if (error?.error?.status === 500 && !has500BeenRetried) {
              console.log(`[${id}] ✓ 500 error detected. Scheduling retry in 10ms...`);
              has500BeenRetried = true;
              popCanvasLayoutRequest();

              // Schedule a retry after 10ms
              return new Promise((resolve, reject) => {
                pending500RetryTimeout = setTimeout(() => {
                  pending500RetryTimeout = null;
                  console.log(`[${id}] ➤ Executing 500 error retry now`);

                  // Retry by dispatching the same request again
                  dispatch(previewApi.endpoints.postPreview.initiate(arg))
                    .then((result) => {
                      console.log(`[${id}] ✓ Retry completed successfully`);
                      resolve(result);
                    })
                    .catch((retryError) => {
                      console.error(`[${id}] ✗ Retry failed:`, retryError);
                      reject(retryError);
                    });
                }, 10);
              });
            }

            // Log errors only if not retrying or if retry also failed
            const status = error?.error?.status || 'unknown';
            console.error(`[${id}] (${status}) preview FAILED`, timeStr(), error);

            if (error?.error?.status === 500) {
              const errorMessage = error?.error?.data?.message || error?.error?.data || 'Unknown error';
              console.error(`[${id}] 500 Server Error - Message:`, errorMessage);
              console.error(`[${id}] 500 Server Error - Full error details:`, {
                status: error.error.status,
                data: error.error.data,
                error: error.error,
              });

              if (has500BeenRetried) {
                console.error(`[${id}] 500 error occurred again after retry. Not retrying further.`);
              }
            }

            popCanvasLayoutRequest();
            throw error; // Re-throw to maintain error behavior
          }
        };

        // Cancel any pending 500 retry if a new request comes in
        if (pending500RetryTimeout) {
          console.log('New request detected - cancelling pending 500 retry');
          clearTimeout(pending500RetryTimeout);
          pending500RetryTimeout = null;
          has500BeenRetried = false;
        }

        if (activePreviewPromise) {
          // Queue this request, replacing any previously queued request
          queuedPreviewRequest = arg;
          console.log(
            'Preview request queued, will use latest after current completes',
          );
          return;
        }

        // Execute the request
        activePreviewPromise = executePreview()
          .catch((error) => {
            // Ensure errors are logged even if they bubble up
            console.error('Preview request failed in promise chain:', error);
            throw error;
          })
          .finally(async () => {
            activePreviewPromise = null;

            // If there's a queued request, execute it
            if (queuedPreviewRequest) {
              const nextRequest = queuedPreviewRequest;
              queuedPreviewRequest = null;
              console.log('Executing queued preview request');

              // Trigger the mutation again with the queued request
              // We need to await this and handle errors properly
              const result = await dispatch(
                previewApi.endpoints.postPreview.initiate(nextRequest),
              );

              // Check for errors in the dispatched result
              if ('error' in result && result.error) {
                console.error(
                  'Queued preview request failed:',
                  result.error,
                );
              }
            }
          });

        await activePreviewPromise;
      },
    }),
    updateComponent: builder.mutation<
      UpdateComponentResultType,
      UpdateComponentQueryArg
    >({
      query: ({ type, ...body }) => {
        let url = '';
        if (type === 'entity') {
          url = 'canvas/api/v0/layout/{entity_type}/{entity_id}';
        } else if (type === 'template') {
          url =
            'canvas/api/v0/layout-content-template/{entity_type}.{template_bundle}.{template_view_mode}/{entity_id}';
        }
        return {
          url,
          method: 'PATCH',
          body,
        };
      },
      async onQueryStarted(body, { dispatch, queryFulfilled }) {
        // Force any ajax calls to wait.
        pushCanvasLayoutRequest();
        const { data, meta } = await queryFulfilled;
        // Tell ajax calls they're good to go.
        popCanvasLayoutRequest();
        const { html, layout, model, autoSaves } = data;
        dispatch(
          pendingChangesApi.util.invalidateTags([
            { type: 'PendingChanges', id: 'LIST' },
          ]),
        );
        dispatch(setHtml(html));
        handleAutoSavesHashUpdate(dispatch, autoSaves, meta);
        // Pass update preview false to prevent a subsequent preview update,
        // we have the data here.
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
// @see https://redux-toolkit.js.org/rtk-query/usage/usage-without-react-hooks
// @see https://redux.js.org/tutorials/fundamentals/part-7-standard-patterns#memoizing-selectors-with-createselector
export const selectUpdateComponentLoadingState = createSelector(
  (state: RootState) => state,
  (state: RootState, componentInstanceId: string) =>
    createUpdateComponentSelector(componentInstanceId),
  (state, selectUpdateComponentSelector) =>
    selectUpdateComponentSelector(state).isLoading,
);
