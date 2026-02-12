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

/**
 * Request Queue Manager
 *
 * Prevents parallel requests to the same endpoint them.
 * Only the most recent request is executed after the current one completes.
 * This prevents lock contention (429 errors) on the backend.
 *
 * Usage:
 *   const queuedRequest = createRequestQueue();
 *   const result = await queuedRequest(() => postPreview(data));
 */
export function createRequestQueue<T>() {
  let activePromise: Promise<T> | null = null;
  let queuedRequest: (() => Promise<T>) | null = null;
  let requestCounter = 0;
  let executionCounter = 0;

  return async function queuedExecute(requestFn: () => Promise<T>): Promise<T> {
    const requestId = ++requestCounter;
    console.log(
      `[Queue ${requestId}] Request received. Active: ${!!activePromise}, Queued: ${!!queuedRequest}`,
    );

    // If there's an active request, queue this one (replacing any previously queued request)
    if (activePromise) {
      if (queuedRequest) {
        console.log(`[Queue ${requestId}] Replacing previously queued request`);
      }
      // Store this request to execute after the active one completes
      queuedRequest = requestFn;
      console.log(
        `[Queue ${requestId}] Queued, waiting for active request to complete`,
      );

      // Wait for the active request to complete, then execute the queued one
      await activePromise;

      // After waiting, check if we're the queued request that should execute
      // (another request might have queued after us)
      if (queuedRequest === requestFn) {
        console.log(
          `[Queue ${requestId}] Woke up and still queued, executing now`,
        );
        queuedRequest = null;
        return queuedExecute(requestFn); // Execute now that nothing is active
      } else {
        // Another request was queued after us, so we're superseded
        // Return a resolved promise (our data is stale anyway)
        console.log(
          `[Queue ${requestId}] Superseded by newer request, dropping`,
        );
        return Promise.resolve(null as T);
      }
    }

    // CRITICAL: Set activePromise synchronously BEFORE calling requestFn()
    // This prevents race conditions when multiple requests come in the same tick
    let resolver: (value: T) => void;
    let rejecter: (error: any) => void;

    activePromise = new Promise<T>((resolve, reject) => {
      resolver = resolve;
      rejecter = reject;
    });

    const executionId = ++executionCounter;
    console.log(`[Queue ${requestId}] Executing (execution #${executionId})`);

    // Now execute the actual request
    try {
      const result = await requestFn();
      console.log(
        `[Queue ${requestId}] Completed successfully (execution #${executionId})`,
      );
      resolver!(result);
      return result;
    } catch (error) {
      console.error(
        `[Queue ${requestId}] Failed (execution #${executionId}):`,
        error,
      );
      rejecter!(error);
      throw error;
    } finally {
      activePromise = null;

      // If there's a queued request, execute it immediately (non-recursive)
      if (queuedRequest) {
        console.log(
          `[Queue ${requestId}] Has queued request, scheduling next execution`,
        );
        const nextRequest = queuedRequest;
        queuedRequest = null;

        // Re-invoke queuedExecute properly to maintain the queue state
        // This ensures proper promise chain management without unbounded recursion
        queuedExecute(nextRequest).catch((error) => {
          console.error('Queued request failed:', error);
        });
      } else {
        console.log(
          `[Queue ${requestId}] No queued requests, queue is now idle`,
        );
      }
    }
  };
}

// Global queue for postPreview requests
const queuedPostPreview = createRequestQueue<{
  html: string;
  autoSaves: AutoSavesHash;
}>();

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

// Track the last HTML to prevent unnecessary dispatches
let lastHtmlHash: string | null = null;

function simpleHash(str: string): string {
  let hash = 0;
  for (let i = 0; i < str.length; i++) {
    const char = str.charCodeAt(i);
    hash = (hash << 5) - hash + char;
    hash = hash & hash; // Convert to 32bit integer
  }
  return hash.toString(36);
}

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
      async onQueryStarted(arg, { dispatch, queryFulfilled, getState }) {
        const requestTimestamp = Date.now();
        console.log(
          `[onQueryStarted] Preview request started at ${requestTimestamp}`,
        );

        pushCanvasLayoutRequest();

        try {
          const { data, meta } = await queryFulfilled;
          const { html, autoSaves } = data;

          console.log(
            `[onQueryStarted] Request fulfilled after ${Date.now() - requestTimestamp}ms`,
          );

          // Calculate hash of HTML to prevent infinite loops
          const currentHtmlHash = simpleHash(html);
          const htmlChanged = currentHtmlHash !== lastHtmlHash;

          console.log(
            `[onQueryStarted] HTML changed: ${htmlChanged} (hash: ${currentHtmlHash} vs ${lastHtmlHash})`,
          );

          console.log(
            `[onQueryStarted] Dispatching actions: invalidateTags${htmlChanged ? ', setHtml' : ''}, handleAutoSaves, setPostPreviewCompleted`,
          );

          dispatch(
            pendingChangesApi.util.invalidateTags([
              { type: 'PendingChanges', id: 'LIST' },
            ]),
          );
          console.log(`[onQueryStarted] invalidateTags dispatched`);

          // Only dispatch setHtml if the HTML actually changed
          if (htmlChanged) {
            lastHtmlHash = currentHtmlHash;
            dispatch(setHtml(html));
            console.log(`[onQueryStarted] setHtml dispatched (HTML changed)`);
          } else {
            console.log(`[onQueryStarted] setHtml SKIPPED (HTML unchanged)`);
          }

          handleAutoSavesHashUpdate(dispatch, autoSaves, meta);
          console.log(`[onQueryStarted] handleAutoSavesHashUpdate completed`);

          dispatch(setPostPreviewCompleted(true));
          console.log(
            `[onQueryStarted] setPostPreviewCompleted dispatched - ALL ACTIONS COMPLETE`,
          );
        } catch (error: any) {
          // Log all errors
          const status = error?.error?.status || 'unknown';
          console.error(`Preview request FAILED (${status}):`, error);

          if (error?.error?.status === 500) {
            const errorMessage =
              error?.error?.data?.message ||
              error?.error?.data ||
              'Unknown error';
            console.error(`500 Server Error - Message:`, errorMessage);
            console.error(`500 Server Error - Full error details:`, {
              status: error.error.status,
              data: error.error.data,
              error: error.error,
            });
          }

          // Don't re-throw errors - swallow them to prevent error boundary
        } finally {
          popCanvasLayoutRequest();
          console.log(
            `[onQueryStarted] Request complete, canvas layout request popped`,
          );
        }
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

/**
 * Queued version of usePostPreviewMutation that prevents parallel requests.
 * Use this instead of usePostPreviewMutation to avoid 429 errors from backend lock contention.
 *
 * Usage:
 *   const [postPreview, { isLoading }] = useQueuedPostPreviewMutation({ fixedCacheKey: 'myKey' });
 *   const result = await postPreview({ layout, model, entity_form_fields });
 */
export function useQueuedPostPreviewMutation(
  options?: Parameters<typeof usePostPreviewMutation>[0],
): [
  (arg: {
    layout: any;
    model: any;
    entity_form_fields: any;
  }) => Promise<{ html: string; autoSaves: AutoSavesHash }>,
  ReturnType<typeof usePostPreviewMutation>[1],
] {
  const [postPreview, mutationState] = usePostPreviewMutation(options);

  let callCounter = 0;

  const queuedPostPreviewWrapper = (arg: {
    layout: any;
    model: any;
    entity_form_fields: any;
  }): Promise<{ html: string; autoSaves: AutoSavesHash }> => {
    const callId = ++callCounter;
    const timestamp = new Date().toISOString().split('T')[1];

    // Get stack trace to see what triggered this
    const stackLines = new Error().stack?.split('\n').slice(2, 6) || [];
    const callerInfo = stackLines.join('\n    ');

    console.log(`[PostPreview ${callId}] Called at ${timestamp}`);
    console.log(`[PostPreview ${callId}] Caller stack:\n    ${callerInfo}`);
    console.log(
      `[PostPreview ${callId}] Form fields count: ${Object.keys(arg.entity_form_fields || {}).length}`,
    );
    console.log(
      `[PostPreview ${callId}] Layout components: ${JSON.stringify(arg.layout).substring(0, 100)}...`,
    );

    // Queue the entire mutation call to prevent RTK Query from dispatching
    // the HTTP request until the queue allows it
    return queuedPostPreview(() => {
      console.log(
        `[PostPreview ${callId}] Actually executing HTTP request now`,
      );
      return postPreview(arg).unwrap();
    });
  };

  return [queuedPostPreviewWrapper, mutationState];
}

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
