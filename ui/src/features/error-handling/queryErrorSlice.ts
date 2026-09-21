import { createSlice } from '@reduxjs/toolkit';

import type { PayloadAction } from '@reduxjs/toolkit';

export interface queryError {
  status: string;
  errors?: any;
  message: string;
  /**
   * The RTK Query endpoint that failed, when the error came from one.
   *
   * Lets a component react to a failure of one specific request instead of to
   * any failure that happens to be the most recent — a 403 from the layout
   * request means the page cannot be edited at all, while a 403 elsewhere may
   * be incidental.
   */
  endpointName?: string;
}

export interface queryErrorSliceState {
  latestError: queryError | undefined;
}

export const initialState: queryErrorSliceState = {
  latestError: undefined,
};

export const queryErrorSlice = createSlice({
  name: 'queryError',
  initialState,
  reducers: (create) => ({
    setLatestError: create.reducer(
      (state, action: PayloadAction<queryError>) => {
        state.latestError = action.payload;
      },
    ),
    clearLatestError: create.reducer((state) => {
      state.latestError = undefined;
    }),
  }),
  selectors: {
    selectLatestError: (state): queryError | undefined => {
      return state.latestError;
    },
  },
});
export const { setLatestError, clearLatestError } = queryErrorSlice.actions;
export const { selectLatestError } = queryErrorSlice.selectors;
