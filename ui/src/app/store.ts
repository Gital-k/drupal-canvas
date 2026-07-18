import undoable from 'redux-undo';
import { v4 as uuidv4 } from 'uuid';
import { combineSlices, configureStore } from '@reduxjs/toolkit';
import { setupListeners } from '@reduxjs/toolkit/query';

import { publishReviewSlice } from '@/components/review/PublishReview.slice';
import codeEditorSlice from '@/features/code-editor/codeEditorSlice';
import { configurationSlice } from '@/features/configuration/configurationSlice';
import { queryErrorSlice } from '@/features/error-handling/queryErrorSlice';
import { extensionsSlice } from '@/features/extensions/extensionsSlice';
import { formStateSlice } from '@/features/form/formStateSlice';
import {
  layoutModelReducer,
  setInitialized,
  setInitialLayoutModel,
  setUpdatePreview,
  syncLayoutModel,
} from '@/features/layout/layoutModelSlice';
import { notificationsSlice } from '@/features/notifications/notificationsSlice';
import {
  pageDataReducer,
  setInitialPageData,
} from '@/features/pageData/pageDataSlice';
import { previewSlice } from '@/features/pagePreview/previewSlice';
import { personalizationSlice } from '@/features/personalization/personalizationSlice';
import { codeComponentDialogSlice } from '@/features/ui/codeComponentDialogSlice';
import { dialogSlice } from '@/features/ui/dialogSlice';
import { primaryPanelSlice } from '@/features/ui/primaryPanelSlice';
import {
  clearUndoRedoHistory,
  EditorFrameContext,
  performUndoOrRedo,
  pushUndo,
  selectEditorFrameContext,
  setLatestUndoRedoActionId,
  uiSlice,
} from '@/features/ui/uiSlice';
import { assetLibraryApi } from '@/services/assetLibrary';
import { brandKitApi } from '@/services/brandKit';
import { componentAndLayoutApi } from '@/services/componentAndLayout';
import { componentInstanceFormApi } from '@/services/componentInstanceForm';
import { contentApi } from '@/services/content';
import { contentEntityReferenceApi } from '@/services/contentEntityReferenceApi';
import { extensionsApi } from '@/services/extensions';
import { headlessComponentSyncApi } from '@/services/headlessComponentSync';
import { headlessFrontendsApi } from '@/services/headlessFrontends';
import { notificationsApi } from '@/services/notificationsApi';
import { pageDataFormApi } from '@/services/pageDataForm';
import { patternApi } from '@/services/patterns';
import { pendingChangesApi } from '@/services/pendingChangesApi';
import { personalizationApi } from '@/services/personalization';
import { previewApi } from '@/services/preview';
import { rtkQueryErrorHandler } from '@/utils/rtkQuery-error';

import type { Action, Middleware, ThunkAction } from '@reduxjs/toolkit';
import type { UnknownAction } from 'redux';
import type {
  DeleteNodeAction,
  DuplicateNodeAction,
  InsertNodesAction,
  LayoutModelSliceState,
  MoveNodeAction,
  PatchLayoutAction,
  ShiftNodeAction,
} from '@/features/layout/layoutModelSlice';
import type { PageDataState } from '@/features/pageData/pageDataSlice';
import type { UndoRedoStackItem, UndoRedoType } from '@/features/ui/uiSlice';
import type { PatchLayoutAndModelQueryArg } from '@/services/preview';

// Reducer enhancer to decorate undoable aware reducers and unset future state
// if an action is performed on another undoable slice.
// @see https://redux.js.org/usage/implementing-undo-history#meet-reducer-enhancers
// @see https://en.wikipedia.org/wiki/History_Eraser
const historyEraser = <T>(
  reducer: any,
  thisType: UndoRedoType,
  forceStateOnUndoRedo: Partial<T> = {},
) => {
  return (state: any, action: UnknownAction, ...slices: any[]) => {
    // Pass through to the inner (undoable) reducer.
    const newState = reducer(state, action, ...slices);
    const type = action.type;
    if (
      type === 'ui/pushUndo' &&
      action.payload !== null &&
      typeof action.payload === 'object' &&
      'targetSlice' in action.payload &&
      (action.payload as UndoRedoStackItem).targetSlice !== thisType &&
      newState.future.length > 0
    ) {
      // Discard the future (redo) states for this slice as we've moved into a
      // future state for another slice.
      // E.g. If this reducer is applied to the 'pageData' slice, but we've
      // pushed 'layoutModel' to the undo stack, then any future (redo) state
      // for this slice is no longer valid.
      // Without this historyEraser, slices would retain their future state when
      // they are not needed or reachable.
      return { ...newState, future: [] };
    }
    if (
      !type.startsWith(`${thisType}/`) &&
      !type.startsWith(`@@redux-undo/${thisType}`)
    ) {
      return newState;
    }
    // For actions in this slice we want to force a certain undo/redo state.
    return {
      ...newState,
      past: newState.past.map((i: T) => ({ ...i, ...forceStateOnUndoRedo })),
      future: newState.future.map((i: T) => ({
        ...i,
        ...forceStateOnUndoRedo,
      })),
    };
  };
};

// `combineSlices` automatically combines the reducers using
// their `reducerPath`s, therefore we no longer need to call `combineReducers`.
const rootReducer = combineSlices(
  {
    layoutModel: historyEraser<LayoutModelSliceState>(
      undoable(layoutModelReducer, {
        undoType: '@@redux-undo/layoutModel_UNDO',
        redoType: '@@redux-undo/layoutModel_REDO',
        clearHistoryType: '@@redux-undo/layoutModel_CLEAR_HISTORY',
        // TRICKY: without syncFilter, a filtered action (e.g. the operation
        // response echo, syncLayoutModel) leaves redux-undo's internal
        // "latest unfiltered" state stale, and the next approved action
        // commits it as a phantom undo entry.
        syncFilter: true,
        filter: (action, currentState, previousHistory) => {
          const { present } = previousHistory;
          return (
            Object.keys(present.model).length > 0 &&
            action.type !== setInitialLayoutModel.type &&
            action.type !== setUpdatePreview.type &&
            action.type !== setInitialized.type &&
            action.type !== syncLayoutModel.type
          );
        },
      }),
      'layoutModel',
      // We want any redo/undo actions to trigger a preview update from the
      // server.
      { updatePreview: true },
    ),
    pageData: historyEraser<PageDataState>(
      undoable(pageDataReducer, {
        undoType: '@@redux-undo/pageData_UNDO',
        redoType: '@@redux-undo/pageData_REDO',
        clearHistoryType: '@@redux-undo/pageData_CLEAR_HISTORY',
        syncFilter: true,
        filter: (action, currentState, previousHistory) => {
          const { present } = previousHistory;
          return (
            Object.keys(present).length > 0 &&
            action.type !== setInitialPageData.type
          );
        },
      }),
      'pageData',
    ),
  },
  patternApi,
  assetLibraryApi,
  brandKitApi,
  headlessComponentSyncApi,
  headlessFrontendsApi,
  personalizationApi,
  componentAndLayoutApi,
  previewApi,
  componentInstanceFormApi,
  pageDataFormApi,
  extensionsApi,
  configurationSlice,
  primaryPanelSlice,
  dialogSlice,
  codeComponentDialogSlice,
  uiSlice,
  formStateSlice,
  extensionsSlice,
  notificationsApi,
  notificationsSlice,
  pendingChangesApi,
  publishReviewSlice,
  contentApi,
  contentEntityReferenceApi,
  codeEditorSlice,
  previewSlice,
  queryErrorSlice,
  personalizationSlice,
);
// Infer the `RootState` type from the root reducer
export type RootState = ReturnType<typeof rootReducer>;

// Middleware to add unique ID to undo/redo actions and store it.
const undoRedoActionIdMiddleware: Middleware<{}, RootState> =
  (store) => (next) => (action) => {
    const type = (action as Action).type;
    // If the action being performed is an UNDO or REDO action we need to move
    // items between the undo and redo stacks.
    const matchesUndoRedo = type.match(/@@redux-undo\/[^_]+_(UNDO|REDO)/);
    if (matchesUndoRedo && matchesUndoRedo.length === 2) {
      const id = uuidv4();
      const [, undoOrRedo] = matchesUndoRedo;
      // @todo implement history rewriting per
      //    https://madebyevan.com/figma/how-figmas-multiplayer-technology-works/#implementing-undo
      store.dispatch(performUndoOrRedo(undoOrRedo === 'UNDO'));
      store.dispatch(setLatestUndoRedoActionId(id));
      return next({
        ...(action as Action),
        meta: {
          id,
        },
      });
    }
    // If the action being performed is a CLEAR_HISTORY action we need to clear
    // the undo and redo stacks and reset the latestUndoRedoActionId in the uiSlice.
    const matchesClear = type.match(/@@redux-undo\/[^_]+_CLEAR_HISTORY/);
    if (matchesClear && matchesClear.length === 1) {
      store.dispatch(clearUndoRedoHistory());
      return next(action);
    }
    if (
      type === setUpdatePreview.type ||
      type === setInitialLayoutModel.type ||
      type === setInitialPageData.type ||
      type === setInitialized.type ||
      type === syncLayoutModel.type
    ) {
      // Ignore initial actions that set the state of the model or page data
      // from the return of API responses. The user should not be able to undo
      // or redo these actions.
      return next(action);
    }
    const [slice] = type.split('/');
    if (slice === 'layoutModel' || slice === 'pageData') {
      // Get current route from state and push undo with route snapshot.
      const state = store.getState();
      const currentRoute = state.ui.currentRoute;
      store.dispatch(
        pushUndo({
          targetSlice: slice as UndoRedoType,
          routeSnapshot: currentRoute,
          ...(import.meta.env.DEV &&
            import.meta.env.TEST === false && {
              // Add debug info for the action that triggered the undo/redo,
              // only in development mode.
              debugInfoAction: action as Action,
            }),
        }),
      );
    }
    return next(action);
  };

const patchLayoutMiddleware: Middleware<{}, RootState> = ((store) =>
  (next) =>
  (action) => {
    const isPatchLayoutAction = (action: Action): action is PatchLayoutAction =>
      action.type.match(
        /^layoutModel\/(duplicateNode|insertNodes|moveNode|shiftNode|deleteNode)$/,
      ) !== null;
    const isDuplicateNodeAction = (
      action: PatchLayoutAction,
    ): action is DuplicateNodeAction =>
      action.type === 'layoutModel/duplicateNode';
    const isInsertNodesAction = (
      action: PatchLayoutAction,
    ): action is InsertNodesAction => action.type === 'layoutModel/insertNodes';
    const isMoveNodeAction = (
      action: PatchLayoutAction,
    ): action is MoveNodeAction => action.type === 'layoutModel/moveNode';
    const isShiftNodeAction = (
      action: PatchLayoutAction,
    ): action is ShiftNodeAction => action.type === 'layoutModel/shiftNode';
    const isDeleteNodeAction = (
      action: PatchLayoutAction,
    ): action is DeleteNodeAction => action.type === 'layoutModel/deleteNode';
    const queryArgFactory = (
      action: PatchLayoutAction,
    ): PatchLayoutAndModelQueryArg => {
      if (isDuplicateNodeAction(action)) {
        return {
          ...action.payload,
          operation: 'duplicate-node',
        };
      }
      if (isInsertNodesAction(action)) {
        return {
          ...action.payload,
          operation: 'insert-nodes',
        };
      }
      if (isDeleteNodeAction(action)) {
        return {
          operation: 'delete-node',
          componentInstanceUuid: action.payload,
        };
      }
      if (isMoveNodeAction(action)) {
        return {
          operation: 'move-node',
          ...action.payload,
        };
      }
      if (isShiftNodeAction(action)) {
        return {
          operation: 'shift-node',
          ...action.payload,
        };
      }
      throw new Error('Unsupported');
    };
    const patchAction = action as Action;

    if (isPatchLayoutAction(patchAction)) {
      // Content templates have no operation routes yet: let the reducer apply
      // the change, then flag a full template layout POST, the pre-operation
      // behavior.
      // @todo Add content template support to the operation routes in https://drupal.org/i/3532056
      if (
        selectEditorFrameContext(store.getState()) ===
        EditorFrameContext.TEMPLATE
      ) {
        const result = next(action);
        store.dispatch(setUpdatePreview(true));
        return result;
      }
      const patchLayoutAndModelQueryArg = queryArgFactory(patchAction);
      store.dispatch(
        previewApi.endpoints.updateComponent.initiate(
          patchLayoutAndModelQueryArg,
        ),
      );
    }
    return next(action);
  }) as Middleware<{}, RootState, AppDispatch>;

// The store setup is wrapped in `makeStore` to allow reuse
// when setting up tests that need the same store config
export const makeStore = (preloadedState?: Partial<RootState>) => {
  const store = configureStore({
    reducer: rootReducer,
    // Adding the api middleware enables caching, invalidation, polling,
    // and other useful features of `rtk-query`.
    middleware: (getDefaultMiddleware) => {
      return getDefaultMiddleware().concat(
        patternApi.middleware,
        assetLibraryApi.middleware,
        brandKitApi.middleware,
        headlessComponentSyncApi.middleware,
        headlessFrontendsApi.middleware,
        personalizationApi.middleware,
        componentAndLayoutApi.middleware,
        previewApi.middleware,
        componentInstanceFormApi.middleware,
        pageDataFormApi.middleware,
        extensionsApi.middleware,
        notificationsApi.middleware,
        undoRedoActionIdMiddleware,
        pendingChangesApi.middleware,
        contentApi.middleware,
        contentEntityReferenceApi.middleware,
        patchLayoutMiddleware,
        rtkQueryErrorHandler, // Add the error handling middleware
      );
    },
    ...(import.meta.env.DEV && {
      // Configuration passed to Redux DevTools.
      // @see https://github.com/reduxjs/redux-devtools/blob/main/extension/docs/API/Arguments.md
      devTools: {
        actionsDenylist: [
          // Do not include actions from RTK Query in the logs. They are
          // usually not useful for debugging, and can be very verbose.
          '.*Api/.*',
          '__rtkq',
          // The following actions from the UI slice can fill up the list of
          // actions very quickly. It's better to comment out the following
          // lines when they're specifically needed for debugging.
          'ui/setIsPanning',
          'ui/setHoveredComponent',
          'ui/unsetHoveredComponent',
        ],
      },
    }),
    preloadedState,
  });
  // configure listeners using the provided defaults
  // optional, but required for `refetchOnFocus`/`refetchOnReconnect` behaviors
  setupListeners(store.dispatch);
  return store;
};

// Infer the type of `store`
export type AppStore = ReturnType<typeof makeStore>;
// Infer the `AppDispatch` type from the store itself
export type AppDispatch = AppStore['dispatch'];
export type AppThunk<ThunkReturnType = void> = ThunkAction<
  ThunkReturnType,
  RootState,
  unknown,
  Action
>;
