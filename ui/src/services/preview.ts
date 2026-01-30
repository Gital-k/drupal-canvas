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

import type { RootState } from '@/app/store';
import type {
  ComponentModel,
  EvaluatedComponentModel,
} from '@/features/layout/layoutModelSlice';
import type { EditorFrameContext } from '@/features/ui/uiSlice';
import type { ConflictError } from '@/services/pendingChangesApi';
import type { AutoSavesHash } from '@/types/AutoSaves';

// Queue to store pending postPreview requests while AJAX is running
type PostPreviewQueueItem = {
  body: { layout: any; model: any; entity_form_fields: any };
  dispatch: any;
  queryFulfilled: Promise<any>;
  timestamp: number;
};

let postPreviewQueue: PostPreviewQueueItem[] = [];
let postPreviewIntervalId: number | null = null;

/**
 * Processes the most recent postPreview request from the queue once AJAX operations complete.
 * Logs debugging information about queued and executed requests.
 */
const processPostPreviewQueue = async () => {
  if (postPreviewQueue.length === 0) {
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

  postPreviewQueue.push(queueItem);

  // If we're not currently waiting for AJAX to complete, set up the interval
  if (postPreviewIntervalId === null && isAjaxing()) {
    console.log('[postPreview] AJAX in progress, delaying request execution');

    postPreviewIntervalId = window.setInterval(() => {
      if (!isAjaxing()) {
        console.log('[postPreview] AJAX completed, processing queue');
        clearInterval(postPreviewIntervalId!);
        postPreviewIntervalId = null;
        processPostPreviewQueue();
      }
    }, 50); // Check every 50ms
  } else if (!isAjaxing()) {
    // AJAX is not running, process immediately
    console.log(
      '[postPreview] No AJAX in progress, processing request immediately',
    );
    processPostPreviewQueue();
  } else {
    console.log(
      `[postPreview] Request added to queue (${postPreviewQueue.length} total), waiting for AJAX to complete`,
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
      async onQueryStarted(arg, { dispatch, queryFulfilled }) {
        // Check if AJAX is in progress and handle accordingly
        if (isAjaxing()) {
          // Queue the request instead of processing it immediately
          schedulePostPreviewExecution(arg, dispatch, queryFulfilled);
          return;
        }

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
        console.log('We will set layout model', model);
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
