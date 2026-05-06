import { createSlice } from '@reduxjs/toolkit';

import type { PayloadAction } from '@reduxjs/toolkit';

interface ConflictUiState {
  resolverOpenByContext: Record<string, boolean>;
  resolverStartPointerByContext: Record<string, string | undefined>;
  readNotificationIdsByContext: Record<string, Record<string, true>>;
}

const initialState: ConflictUiState = {
  resolverOpenByContext: {},
  resolverStartPointerByContext: {},
  readNotificationIdsByContext: {},
};

export const conflictUiSlice = createSlice({
  name: 'conflictUi',
  initialState,
  reducers: {
    openConflictResolver(
      state,
      action: PayloadAction<{ contextKey: string; pointer?: string }>,
    ) {
      const { contextKey, pointer } = action.payload;
      state.resolverOpenByContext[contextKey] = true;
      state.resolverStartPointerByContext[contextKey] = pointer;
    },
    closeConflictResolver(state, action: PayloadAction<{ contextKey: string }>) {
      const { contextKey } = action.payload;
      state.resolverOpenByContext[contextKey] = false;
      state.resolverStartPointerByContext[contextKey] = undefined;
    },
    markConflictNotificationRead(
      state,
      action: PayloadAction<{ contextKey: string; id: string }>,
    ) {
      const { contextKey, id } = action.payload;
      state.readNotificationIdsByContext[contextKey] ??= {};
      state.readNotificationIdsByContext[contextKey][id] = true;
    },
    markAllConflictNotificationsRead(
      state,
      action: PayloadAction<{ contextKey: string; ids: string[] }>,
    ) {
      const { contextKey, ids } = action.payload;
      state.readNotificationIdsByContext[contextKey] ??= {};
      for (const id of ids) {
        state.readNotificationIdsByContext[contextKey][id] = true;
      }
    },
  },
  selectors: {
    selectConflictResolverOpen: (state, contextKey?: string): boolean =>
      !!state.resolverOpenByContext[contextKey ?? 'global'],
    selectConflictResolverStartPointer: (
      state,
      contextKey?: string,
    ): string | undefined =>
      state.resolverStartPointerByContext[contextKey ?? 'global'],
    selectConflictNotificationRead: (
      state,
      id: string,
      contextKey?: string,
    ): boolean =>
      !!state.readNotificationIdsByContext[contextKey ?? 'global']?.[id],
  },
});

export const {
  openConflictResolver,
  closeConflictResolver,
  markConflictNotificationRead,
  markAllConflictNotificationsRead,
} = conflictUiSlice.actions;

export const {
  selectConflictResolverOpen,
  selectConflictResolverStartPointer,
  selectConflictNotificationRead,
} = conflictUiSlice.selectors;
