import { createSelector } from '@reduxjs/toolkit';
import { createApi } from '@reduxjs/toolkit/query/react';

import { setPostPreviewCompleted } from '@/components/review/PublishReview.slice';
import {
  setLayoutModel,
  syncLayoutModel,
} from '@/features/layout/layoutModelSlice';
import { setHtml, setSnapshotHTML } from '@/features/pagePreview/previewSlice';
import {
  baseQueryWithAutoSaves,
  extractEntityParams,
  popCanvasLayoutRequest,
  pushCanvasLayoutRequest,
  replaceEntityParamsInUrl,
} from '@/services/baseQuery';
import { pendingChangesApi } from '@/services/pendingChangesApi';
import { handleAutoSavesHashUpdate } from '@/utils/autoSaves';

import type { RootState } from '@/app/store';
import type {
  ComponentModel,
  DuplicateNodePayload,
  EvaluatedComponentModel,
  InsertMultipleNodesPayload,
  MoveNodePayload,
  NodeParentPosition,
  PropSource,
  ResolvedValues,
  ShiftNodePayload,
} from '@/features/layout/layoutModelSlice';
import type { EditorFrameContext } from '@/features/ui/uiSlice';
import type { ConflictError } from '@/services/pendingChangesApi';
import type { AutoSavesHash } from '@/types/AutoSaves';
import type { InputUIData } from '@/types/Form';

export type UpdateComponentResultType = {
  html: string;
  layout: any;
  model: any;
  autoSaves: AutoSavesHash;
  errors?: Array<ConflictError>;
};

interface MoveNodeQueryArg extends MoveNodePayload, NodeParentPosition {
  type?: EditorFrameContext;
  operation: 'move-node';
}

interface ShiftNodeQueryArg extends ShiftNodePayload {
  type?: EditorFrameContext;
  operation: 'shift-node';
}

interface DeleteNodeQueryArg {
  type?: EditorFrameContext;
  operation: 'delete-node';
  componentInstanceUuid: string;
}

interface DuplicateNodeQueryArg extends DuplicateNodePayload {
  type?: EditorFrameContext;
  operation: 'duplicate-node';
  uuidMap: Record<string, string>;
}

interface InsertNodesQueryArg
  extends InsertMultipleNodesPayload, NodeParentPosition {
  type?: EditorFrameContext;
  operation: 'insert-nodes';
}

// @todo Split this up to only modify a single prop, not the
//   whole model.
type UpdateComponentQueryArg = {
  type?: EditorFrameContext;
  operation: 'update-model';
  componentInstanceUuid: string;
  componentType: string;
  model: Omit<ComponentModel, 'name'> | Omit<EvaluatedComponentModel, 'name'>;
};

export type PatchLayoutAndModelQueryArg =
  | UpdateComponentQueryArg
  | InsertNodesQueryArg
  | DeleteNodeQueryArg
  | ShiftNodeQueryArg
  | MoveNodeQueryArg
  | DuplicateNodeQueryArg;

// Re-export for backward compatibility
export type { UpdateComponentQueryArg };

// Monotonic ticket for updateComponent responses: with rapid edits several
// operations are in flight at once, and an older response applying after a
// newer one would flash the preview and model backwards. Every response
// renders the full auto-saved state, so only the most recently started
// operation's response needs to be applied.
let latestOperationTicket = 0;

const TEMPLATE_LAYOUT_URL =
  'canvas/api/v0/layout-content-template/{entity_type}.{template_bundle}.{template_view_mode}/{entity_id}';

// The auto-save hash for a content template is keyed by its resolved layout
// URL, derived from the current editor location.
const templateAutoSaveKey = () => {
  const { entityType, entityId, templateBundle, templateViewMode } =
    extractEntityParams(window.location.href);
  return replaceEntityParamsInUrl(
    TEMPLATE_LAYOUT_URL,
    entityType,
    entityId,
    templateBundle,
    templateViewMode,
  );
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
        layout: any;
        model: any;
        entity_form_fields: any;
      }
    >({
      query: ({ entityType, entityId, ...body }) => ({
        url: `canvas/api/v0/layout/${entityType}/${entityId}`,
        method: 'POST',
        body,
      }),
      async onQueryStarted(arg, { dispatch, queryFulfilled }) {
        pushCanvasLayoutRequest();
        try {
          const { data, meta } = await queryFulfilled;
          const { html, autoSaves } = data;
          dispatch(
            pendingChangesApi.util.invalidateTags([
              { type: 'PendingChanges', id: 'LIST' },
            ]),
          );
          dispatch(setHtml(html));
          handleAutoSavesHashUpdate(dispatch, autoSaves, meta);
          previewSuccessCount++;
          dispatch(setPostPreviewCompleted(true));
        } catch (error) {
          // A failed preview may be followed moments later by a successful one
          // (e.g. the user keeps editing). Capture the success count at the
          // time of failure and re-throw only if no successful request has
          // completed by the time the delay expires.
          const successCountAtFailure = previewSuccessCount;
          setTimeout(() => {
            if (previewSuccessCount === successCountAtFailure) {
              throw error;
            }
          }, 5000);
        } finally {
          popCanvasLayoutRequest();
        }
      },
    }),
    // Snapshot preview updates the preview frame without changing the active
    // model in the UI.
    getSnapshotPreview: builder.query<
      { html: string },
      {
        entityType: string;
        entityId: string;
        language: string;
        isTemplate?: boolean;
        templateInfo?: { bundle?: string; viewMode?: string };
      }
    >({
      query: ({ entityType, entityId, language, isTemplate, templateInfo }) => {
        // Request the translation via the `canvas_preview_langcode` query
        // parameter. The backend routes the request through the site's
        // configured language negotiation — redirecting when needed — so the
        // right translation is served regardless of the negotiation method.
        const path = isTemplate
          ? `canvas/api/v0/layout-content-template/${entityType}.${templateInfo?.bundle}.${templateInfo?.viewMode}/${entityId}`
          : `canvas/api/v0/layout/${entityType}/${entityId}`;
        const url = language
          ? `${path}?canvas_preview_langcode=${encodeURIComponent(language)}`
          : path;
        return { url, method: 'GET' };
      },
      async onQueryStarted(arg, { dispatch, queryFulfilled }) {
        try {
          const { data } = await queryFulfilled;
          const { html } = data;
          // Update the snapshot HTML for preview-only display.
          dispatch(setSnapshotHTML(html));
        } catch {
          // Error is handled by the component.
        }
      },
    }),
    updateComponent: builder.mutation<
      UpdateComponentResultType,
      PatchLayoutAndModelQueryArg
    >({
      query: ({ operation, type, ...body }) => {
        // Content templates have no operation routes yet; their only
        // operation-shaped mutation (update-model) maps 1:1 onto the classic
        // content template PATCH.
        // @todo Add content template support to the operation routes in https://drupal.org/i/3532056
        if (type === 'template') {
          return {
            url: TEMPLATE_LAYOUT_URL,
            method: 'PATCH',
            body,
          };
        }
        return {
          url: `canvas/api/v0/layout/${operation}/{entity_type}/{entity_id}`,
          method: 'PATCH',
          body,
          autoSaveUrlKey: `canvas/api/v0/layout/{entity_type}/{entity_id}`,
        };
      },
      async onQueryStarted(body, { dispatch, queryFulfilled, getState }) {
        const ticket = ++latestOperationTicket;
        // Force any ajax calls to wait.
        pushCanvasLayoutRequest();
        const {
          configuration: { entityType, entity },
        } = getState() as RootState;
        const autoSaveKey =
          body.type === 'template'
            ? templateAutoSaveKey()
            : `canvas/api/v0/layout/{entity_type}/{entity_id}`
                .replace('{entity_type}', entityType)
                .replace('{entity_id}', entity);
        let data: any;
        try {
          ({ data } = await queryFulfilled);
        } catch {
          // If the request fails (e.g. the server rejects an invalid field
          // value), we must still release the lock so that subsequent Drupal
          // AJAX calls are not permanently blocked.
          // @see https://www.drupal.org/project/canvas/issues/3579026
          return;
        } finally {
          // Tell ajax calls they're good to go regardless of success/failure.
          popCanvasLayoutRequest();
        }
        if (ticket !== latestOperationTicket) {
          // A newer operation started after this one: applying this response
          // would briefly rewind the preview and model to a stale state that
          // the newer response then has to correct.
          return;
        }
        const { html, layout, model, autoSaves } = data;
        dispatch(
          pendingChangesApi.util.invalidateTags([
            { type: 'PendingChanges', id: 'LIST' },
          ]),
        );
        // Clear any stale snapshot (e.g. from a prior language/template preview)
        // so selectPreviewHtml returns this fresh editor html instead of the
        // preview snapshot, which selectPreviewHtml prefers while set.
        dispatch(setSnapshotHTML(''));
        dispatch(setHtml(html));
        handleAutoSavesHashUpdate(dispatch, autoSaves, {
          request: {
            url: autoSaveKey,
          },
        });
        // Pass update preview false to prevent a subsequent preview update,
        // we have the data here.
        if (body.operation === 'update-model') {
          // A prop edit has no client-side reducer, so its echo is what
          // creates the undo entry (setLayoutModel is undoable). Staging the
          // edit before the request would keep rejected values (e.g. an
          // invalid URI) in the model, breaking later form rebuilds.
          dispatch(setLayoutModel({ layout, model, updatePreview: false }));
        } else {
          // Structural operations already created their undo entry in the
          // dispatching reducer; their echo must not cost an extra undo step.
          dispatch(syncLayoutModel({ layout, model, updatePreview: false }));
        }
      },
    }),
  }),
});

export const {
  usePostPreviewMutation,
  useGetSnapshotPreviewQuery,
  useUpdateComponentMutation,
} = previewApi;

let lastBody = {};
/**
 * A hook that wraps useUpdateComponentMutation with a simpler interface.
 *
 * Instead of manually constructing the full UpdateComponentQueryArg at every
 * call site, consumers pass only the model payload. The hook derives
 * `type`, `componentInstanceUuid`, and `componentType` from `inputUIData`.
 *
 * @param inputUIData - The return value of useInputUIData().
 */
export const usePatchComponent = () => {
  const [updateComponent] = useUpdateComponentMutation();

  return (
    inputUIData: InputUIData,
    model: UpdateComponentQueryArg['model'],
  ) => {
    const {
      selectedComponent,
      selectedComponentType,
      version,
      editorFrameContext,
    } = inputUIData;

    const arg: UpdateComponentQueryArg = {
      operation: 'update-model',
      type: editorFrameContext,
      componentInstanceUuid: selectedComponent,
      componentType: `${selectedComponentType}@${version}`,
      model,
    };

    // Prevent duplicate requests
    const stringBody = JSON.stringify(arg);
    if (stringBody === lastBody) {
      // Return a resolved promise to mimic successful completion
      return Promise.resolve({ data: undefined }) as any;
    }
    lastBody = stringBody;
    return updateComponent(arg);
  };
};

/**
 * A targeted variant of usePatchComponent for the common case of updating a
 * single prop on the current component.
 *
 * The caller provides only the prop name and its new source/resolved values.
 * The hook spreads the existing model's source and resolved, overriding just
 * the named prop — avoiding the repetitive spread boilerplate at every call
 * site.
 *
 * Only valid for EvaluatedComponentModel instances (components with source
 * data). Returns null without patching if the current model is not evaluated.
 */
export const usePatchProp = () => {
  const [updateComponent] = useUpdateComponentMutation();

  return (
    inputUIData: InputUIData,
    propName: string,
    sourceValue: PropSource | PropSource['value'],
    resolvedValue: ResolvedValues[string],
  ) => {
    const {
      selectedComponent,
      selectedComponentType,
      version,
      editorFrameContext,
      model,
    } = inputUIData;

    const selectedModel = model?.[selectedComponent];
    if (!selectedModel || !('source' in selectedModel)) {
      return null;
    }

    return updateComponent({
      operation: 'update-model',
      type: editorFrameContext,
      componentInstanceUuid: selectedComponent,
      componentType: `${selectedComponentType}@${version}`,
      model: {
        source: {
          ...selectedModel.source,
          [propName]: sourceValue,
        },
        resolved: {
          ...selectedModel.resolved,
          [propName]: resolvedValue,
        },
      },
    });
  };
};

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

type PostPreviewResult = { html: string; autoSaves: AutoSavesHash };
type PostPreviewArg = {
  layout: any;
  model: any;
  entity_form_fields: any;
  entityId: string;
  entityType: string;
};

// Module-level queue state for postPreview requests.
// Prevents parallel requests to the endpoint - only the most recent
// queued request executes when the active one completes.
let activePreviewRequest: Promise<PostPreviewResult> | null = null;
let pendingPreviewArg: PostPreviewArg | null = null;

// Incremented each time a postPreview request completes successfully.
// Used to detect whether an error has been superseded by a later success.
let previewSuccessCount = 0;

/**
 * Queued version of usePostPreviewMutation that prevents parallel requests.
 *
 * When a request is in flight, subsequent calls are queued. Only the most
 * recent queued request executes when the active one completes - earlier
 * queued requests never resolve (their data would be stale since preview
 * values are cumulative).
 *
 * This prevents entity lock contention on the backend.
 */
export function useQueuedPostPreviewMutation(
  options?: Parameters<typeof usePostPreviewMutation>[0],
): [
  (arg: PostPreviewArg) => Promise<PostPreviewResult>,
  ReturnType<typeof usePostPreviewMutation>[1],
] {
  const [postPreview, mutationState] = usePostPreviewMutation(options);

  const queuedPostPreview = async (
    arg: PostPreviewArg,
  ): Promise<PostPreviewResult> => {
    if (activePreviewRequest) {
      pendingPreviewArg = arg;
      await activePreviewRequest.catch(() => {});
      if (pendingPreviewArg !== arg) {
        // Superseded by a newer request - never resolve
        return new Promise(() => {});
      }
      pendingPreviewArg = null;
    }

    activePreviewRequest = postPreview(arg).unwrap();
    try {
      return await activePreviewRequest;
    } finally {
      activePreviewRequest = null;
      if (pendingPreviewArg) {
        const nextArg = pendingPreviewArg;
        pendingPreviewArg = null;
        queuedPostPreview(nextArg);
      }
    }
  };

  return [queuedPostPreview, mutationState];
}

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
