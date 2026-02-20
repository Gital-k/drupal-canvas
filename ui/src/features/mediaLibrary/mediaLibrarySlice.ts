import { createAppSlice } from '@/app/createAppSlice';

export interface MediaLibrarySliceState {
  isReordering: boolean;
}

const initialState: MediaLibrarySliceState = {
  isReordering: false,
};

export const mediaLibrarySlice = createAppSlice({
  name: 'mediaLibrary',
  initialState,
  reducers: (create) => ({
    setReorderingStart: create.reducer((state) => {
      state.isReordering = true;
    }),
    setReorderingEnd: create.reducer((state) => {
      state.isReordering = false;
    }),
  }),
  selectors: {
    selectIsReordering: (state): boolean => state.isReordering,
  },
});

export const { setReorderingStart, setReorderingEnd } =
  mediaLibrarySlice.actions;
export const { selectIsReordering } = mediaLibrarySlice.selectors;
