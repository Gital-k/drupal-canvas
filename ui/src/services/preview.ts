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
import { isAjaxing } from '@/utils/isAjaxing';

import type { BaseQueryFn } from '@reduxjs/toolkit/query';
import type { RootState } from '@/app/store';
import type {
  ComponentModel,
  EvaluatedComponentModel,
} from '@/features/layout/layoutModelSlice';
import type { EditorFrameContext } from '@/features/ui/uiSlice';
import type { ConflictError } from '@/services/pendingChangesApi';
import type { AutoSavesHash } from '@/types/AutoSaves';

// Global debouncing state
const requestDebounceMap = new Map<
  string,
  { timestamp: number; promise?: Promise<any> }
>();
const DEBOUNCE_TIME = 300; // ms

/**
 * Creates a debounced version of the base query that prevents identical requests
 * within the debounce window from reaching the network layer
 */
const createDebouncedBaseQuery = (baseQuery: BaseQueryFn): BaseQueryFn => {
  return async (args, api, extraOptions) => {
    // Create a unique key for this request
    const requestKey = JSON.stringify({
      url: args.url,
      method: args.method,
      body: args.body,
    });
    const shortKey = requestKey.substring(0, 8);
    const currentTime = Date.now();

    console.log(
      `[baseQuery] REQUEST ATTEMPT - Key: ${shortKey}, Method: ${args.method}, URL: ${args.url}`,
    );

    // Check if we have a recent identical request
    const existingRequest = requestDebounceMap.get(requestKey);
    if (existingRequest) {
      const timeDiff = currentTime - existingRequest.timestamp;

      if (timeDiff < DEBOUNCE_TIME) {
        console.log(
          `[baseQuery] REQUEST BLOCKED - Key: ${shortKey}, TimeDiff: ${timeDiff}ms, Threshold: ${DEBOUNCE_TIME}ms`,
        );

        // If there's an ongoing promise for this request, wait for it
        if (existingRequest.promise) {
          console.log(`[baseQuery] REUSING ONGOING REQUEST - Key: ${shortKey}`);
          try {
            return await existingRequest.promise;
          } catch (error) {
            // If the existing request failed, allow this new one to proceed
            console.log(
              `[baseQuery] EXISTING REQUEST FAILED - Key: ${shortKey}, allowing new request`,
            );
          }
        } else {
          // Return a fake success response to prevent the duplicate
          console.log(
            `[baseQuery] RETURNING CACHED RESPONSE - Key: ${shortKey}`,
          );
          return { data: null };
        }
      }
    }

    console.log(`[baseQuery] REQUEST PROCEEDING - Key: ${shortKey}`);

    // Make the actual request
    const result = baseQuery(args, api, extraOptions);

    // Handle both promise and non-promise returns
    const handleRequestCompletion = () => {
      // Remove from map after a delay to allow for immediate duplicate detection
      setTimeout(() => {
        const entry = requestDebounceMap.get(requestKey);
        if (entry && entry.timestamp === currentTime) {
          requestDebounceMap.delete(requestKey);
        }
      }, DEBOUNCE_TIME);
    };

    // Check if result is a promise
    if (result && typeof (result as any).then === 'function') {
      // It's a promise
      const promiseResult = result as Promise<any>;

      requestDebounceMap.set(requestKey, {
        timestamp: currentTime,
        promise: promiseResult,
      });

      promiseResult.finally(handleRequestCompletion);
      return promiseResult;
    } else {
      // It's not a promise, handle immediately
      requestDebounceMap.set(requestKey, {
        timestamp: currentTime,
      });

      handleRequestCompletion();
      return result;
    }
  };
};

const debouncedBaseQuery = createDebouncedBaseQuery(baseQueryWithAutoSaves);

// Queue for updateComponent requests
type UpdateComponentQueueItem = {
  body: UpdateComponentQueryArg;
  dispatch: any;
  queryFulfilled: Promise<any>;
  timestamp: number;
};

let updateComponentQueue: UpdateComponentQueueItem[] = [];
let updateComponentIntervalId: number | null = null;
let updateComponentDebounceId: number | null = null;

/**
 * Processes the most recent updateComponent request from the queue
 */
const processUpdateComponentQueue = async () => {
  if (updateComponentQueue.length === 0) {
    return;
  }

  // Check if AJAX is still in progress - if so, don't process yet
  if (isAjaxing()) {
    console.log(
      '[updateComponent] AJAX still in progress, delaying queue processing',
    );
    return;
  }

  const queueLength = updateComponentQueue.length;
  const mostRecentRequest =
    updateComponentQueue[updateComponentQueue.length - 1];

  console.log(
    `[updateComponent] Processing delayed request. Total queued: ${queueLength}, executing most recent only`,
  );

  // Clear the queue
  updateComponentQueue = [];

  // Execute the most recent request
  try {
    // Force any ajax calls to wait.
    pushCanvasLayoutRequest();
    const { data, meta } = await mostRecentRequest.queryFulfilled;
    // Tell ajax calls they're good to go.
    popCanvasLayoutRequest();
    const { html, layout, model, autoSaves } = data;
    mostRecentRequest.dispatch(
      pendingChangesApi.util.invalidateTags([
        { type: 'PendingChanges', id: 'LIST' },
      ]),
    );
    mostRecentRequest.dispatch(setHtml(html));
    handleAutoSavesHashUpdate(mostRecentRequest.dispatch, autoSaves, meta);
    console.log('We will set layout model', model);
    mostRecentRequest.dispatch(
      setLayoutModel({ layout, model, updatePreview: false }),
    );

    console.log('[updateComponent] Delayed request completed successfully');
  } catch (error) {
    // Make sure to pop the request even on error
    popCanvasLayoutRequest();
    console.error('[updateComponent] Delayed request failed:', error);
  }
};

/**
 * Schedules updateComponent execution when AJAX is not running
 */
const scheduleUpdateComponentExecution = (
  body: UpdateComponentQueryArg,
  dispatch: any,
  queryFulfilled: Promise<any>,
) => {
  const queueItem: UpdateComponentQueueItem = {
    body,
    dispatch,
    queryFulfilled,
    timestamp: Date.now(),
  };

  updateComponentQueue.push(queueItem);

  // Clear any existing debounce timeout
  if (updateComponentDebounceId !== null) {
    clearTimeout(updateComponentDebounceId);
    updateComponentDebounceId = null;
  }

  // If we're not currently waiting for AJAX to complete, set up the interval
  if (updateComponentIntervalId === null && isAjaxing()) {
    console.log(
      '[updateComponent] AJAX in progress, delaying request execution',
    );

    updateComponentIntervalId = window.setInterval(() => {
      if (!isAjaxing()) {
        console.log('[updateComponent] AJAX completed, processing queue');
        clearInterval(updateComponentIntervalId!);
        updateComponentIntervalId = null;
        // Add a small debounce delay even after AJAX completes
        updateComponentDebounceId = window.setTimeout(() => {
          processUpdateComponentQueue();
          updateComponentDebounceId = null;
        }, 100);
      }
    }, 50); // Check every 50ms
  } else if (!isAjaxing()) {
    // AJAX is not running, process with debounce delay
    console.log(
      '[updateComponent] No AJAX in progress, processing request with debounce delay',
    );
    updateComponentDebounceId = window.setTimeout(() => {
      processUpdateComponentQueue();
      updateComponentDebounceId = null;
    }, 100);
  } else {
    console.log(
      `[updateComponent] Request added to queue (${updateComponentQueue.length} total), waiting for AJAX to complete`,
    );
  }
};

// Queue to store pending postPreview requests while AJAX is running
type PostPreviewQueueItem = {
  body: { layout: any; model: any; entity_form_fields: any };
  dispatch: any;
  queryFulfilled: Promise<any>;
  timestamp: number;
};

let postPreviewQueue: PostPreviewQueueItem[] = [];
let postPreviewIntervalId: number | null = null;
let postPreviewDebounceId: number | null = null;

/**
 * Processes the most recent postPreview request from the queue once AJAX operations complete.
 * Logs debugging information about queued and executed requests.
 */
const processPostPreviewQueue = async () => {
  if (postPreviewQueue.length === 0) {
    return;
  }

  // Check if AJAX is still in progress - if so, don't process yet
  if (isAjaxing()) {
    console.log(
      '[postPreview] AJAX still in progress, delaying queue processing',
    );
    return;
  }

  const queueLength = postPreviewQueue.length;
  // Get the most recent request (last item in the queue)
  const mostRecentRequest = postPreviewQueue[postPreviewQueue.length - 1];

  console.log(
    `[postPreview] Processing delayed request. Total queued: ${queueLength}, executing most recent only`,
  );

  // Clear the queue
  postPreviewQueue = [];

  // Execute the most recent request
  try {
    const { data, meta } = await mostRecentRequest.queryFulfilled;
    const { html, autoSaves } = data;
    mostRecentRequest.dispatch(
      pendingChangesApi.util.invalidateTags([
        { type: 'PendingChanges', id: 'LIST' },
      ]),
    );
    mostRecentRequest.dispatch(setHtml(html));
    handleAutoSavesHashUpdate(mostRecentRequest.dispatch, autoSaves, meta);
    mostRecentRequest.dispatch(setPostPreviewCompleted(true));

    console.log('[postPreview] Delayed request completed successfully');
  } catch (error) {
    console.error('[postPreview] Delayed request failed:', error);
  }
};

/**
 * Checks if AJAX is running and schedules postPreview execution accordingly.
 * If AJAX is active, sets up an interval to wait for completion.
 */
const schedulePostPreviewExecution = (
  body: { layout: any; model: any; entity_form_fields: any },
  dispatch: any,
  queryFulfilled: Promise<any>,
) => {
  const queueItem: PostPreviewQueueItem = {
    body,
    dispatch,
    queryFulfilled,
    timestamp: Date.now(),
  };

  const bodyHash = JSON.stringify(body);
  const shortHash = bodyHash.substring(0, 8);

  postPreviewQueue.push(queueItem);

  console.log(
    `[postPreview] QUEUE SCHEDULING - Hash: ${shortHash}, QueueLength: ${postPreviewQueue.length}, HasDebounceId: ${postPreviewDebounceId !== null}, HasIntervalId: ${postPreviewIntervalId !== null}`,
  );

  // Clear any existing debounce timeout
  if (postPreviewDebounceId !== null) {
    console.log(
      `[postPreview] QUEUE CLEARING - Hash: ${shortHash}, Clearing existing debounce timeout`,
    );
    clearTimeout(postPreviewDebounceId);
    postPreviewDebounceId = null;
  }

  // If we're not currently waiting for AJAX to complete, set up the interval
  if (postPreviewIntervalId === null && isAjaxing()) {
    console.log(
      `[postPreview] QUEUE INTERVAL START - Hash: ${shortHash}, AJAX in progress, starting interval`,
    );

    postPreviewIntervalId = window.setInterval(() => {
      if (!isAjaxing()) {
        console.log(
          `[postPreview] QUEUE INTERVAL END - AJAX completed, processing queue in 100ms`,
        );
        clearInterval(postPreviewIntervalId!);
        postPreviewIntervalId = null;
        // Add a small debounce delay even after AJAX completes
        postPreviewDebounceId = window.setTimeout(() => {
          processPostPreviewQueue();
          postPreviewDebounceId = null;
        }, 100);
      }
    }, 50); // Check every 50ms
  } else if (!isAjaxing()) {
    // AJAX is not running, process with debounce delay
    console.log(
      `[postPreview] QUEUE IMMEDIATE - Hash: ${shortHash}, No AJAX, scheduling with 100ms delay`,
    );
    postPreviewDebounceId = window.setTimeout(() => {
      processPostPreviewQueue();
      postPreviewDebounceId = null;
    }, 100);
  } else {
    console.log(
      `[postPreview] QUEUE WAITING - Hash: ${shortHash}, Added to queue, waiting for existing interval`,
    );
  }
};

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

export const previewApi = createApi({
  reducerPath: 'previewApi',
  baseQuery: debouncedBaseQuery,
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
      async onQueryStarted(arg, { dispatch, queryFulfilled }) {
        // Check if AJAX is in progress and handle accordingly
        if (isAjaxing()) {
          // Queue the request instead of processing it immediately
          console.log(
            `[postPreview] AJAX QUEUING - AJAX in progress, queuing request`,
          );
          schedulePostPreviewExecution(arg, dispatch, queryFulfilled);
          return;
        }

        console.log(
          `[postPreview] REQUEST PROCEEDING - No AJAX, proceeding normally`,
        );

        // No AJAX in progress, process normally
        const { data, meta } = await queryFulfilled;
        const { html, autoSaves } = data;
        dispatch(
          pendingChangesApi.util.invalidateTags([
            { type: 'PendingChanges', id: 'LIST' },
          ]),
        );
        // Update our preview slice.
        dispatch(setHtml(html));
        handleAutoSavesHashUpdate(dispatch, autoSaves, meta);
        dispatch(setPostPreviewCompleted(true));
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
        // Check if AJAX is in progress and handle accordingly
        if (isAjaxing()) {
          // Queue the request instead of processing it immediately
          console.log(
            `[updateComponent] AJAX QUEUING - AJAX in progress, queuing request`,
          );
          scheduleUpdateComponentExecution(body, dispatch, queryFulfilled);
          return;
        }

        console.log(
          `[updateComponent] REQUEST PROCEEDING - No AJAX, proceeding normally`,
        );

        // Force any ajax calls to wait.
        pushCanvasLayoutRequest();
        try {
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
          console.log('We will set layout model', model);
          dispatch(setLayoutModel({ layout, model, updatePreview: false }));
        } catch (error) {
          // Make sure to pop the request even on error
          popCanvasLayoutRequest();
          throw error;
        }
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
