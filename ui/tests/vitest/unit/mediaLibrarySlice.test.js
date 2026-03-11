import { makeStore } from '@/app/store';
import {
  mediaLibrarySlice,
  selectIsReordering,
  setReorderingEnd,
  setReorderingStart,
} from '@/features/mediaLibrary/mediaLibrarySlice';

const initialState = { isReordering: false };

describe('Media library slice 🔪', () => {
  it('Should have isReordering set to false by default', () => {
    const state = mediaLibrarySlice.reducer(undefined, { type: '@@INIT' });
    expect(state.isReordering).to.eq(false);
  });

  it('Should set isReordering to true when setReorderingStart is dispatched', () => {
    const state = mediaLibrarySlice.reducer(initialState, setReorderingStart());
    expect(state.isReordering).to.eq(true);
  });

  it('Should set isReordering to false when setReorderingEnd is dispatched', () => {
    const state = mediaLibrarySlice.reducer(
      { isReordering: true },
      setReorderingEnd(),
    );
    expect(state.isReordering).to.eq(false);
  });

  it('Should reflect state changes through the store and selector', () => {
    const store = makeStore();
    expect(selectIsReordering(store.getState())).to.eq(false);

    store.dispatch(setReorderingStart());
    expect(selectIsReordering(store.getState())).to.eq(true);

    store.dispatch(setReorderingEnd());
    expect(selectIsReordering(store.getState())).to.eq(false);
  });
});
