import { createSlice } from '@reduxjs/toolkit';

import type { PayloadAction } from '@reduxjs/toolkit';
import type { RootState } from '@/app/store';

const ACTIVE_WORKSPACE_STORAGE_KEY = 'canvas.activeWorkspaceId';

interface WorkspacesState {
  activeWorkspaceId: string | null;
  isAddWorkspaceModalOpen: boolean;
}

const getStoredWorkspaceId = (): string | null => {
  try {
    return window.localStorage.getItem(ACTIVE_WORKSPACE_STORAGE_KEY);
  } catch {
    // LocalStorage not available (e.g., private browsing)
    return null;
  }
};

const setStoredWorkspaceId = (id: string | null) => {
  try {
    if (id === null) {
      window.localStorage.removeItem(ACTIVE_WORKSPACE_STORAGE_KEY);
    } else {
      window.localStorage.setItem(ACTIVE_WORKSPACE_STORAGE_KEY, id);
    }
  } catch {
    // LocalStorage not available (e.g., private browsing)
  }
};

const initialState: WorkspacesState = {
  activeWorkspaceId: getStoredWorkspaceId(),
  isAddWorkspaceModalOpen: false,
};

export const workspacesSlice = createSlice({
  name: 'workspaces',
  initialState,
  reducers: {
    setActiveWorkspace: (state, action: PayloadAction<string>) => {
      state.activeWorkspaceId = action.payload;
      setStoredWorkspaceId(action.payload);
    },
    clearActiveWorkspace: (state) => {
      state.activeWorkspaceId = null;
      setStoredWorkspaceId(null);
    },
    setAddWorkspaceModalOpen: (state, action: PayloadAction<boolean>) => {
      state.isAddWorkspaceModalOpen = action.payload;
    },
  },
});

export const {
  setActiveWorkspace,
  clearActiveWorkspace,
  setAddWorkspaceModalOpen,
} = workspacesSlice.actions;

// Selectors
export const selectActiveWorkspaceId = (state: RootState): string | null =>
  state.workspaces.activeWorkspaceId;

export const selectIsAddWorkspaceModalOpen = (state: RootState): boolean =>
  state.workspaces.isAddWorkspaceModalOpen;
