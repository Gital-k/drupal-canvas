import { createAppSlice } from '@/app/createAppSlice';

interface FormDialogState {
  isAddFormDialogOpen: boolean;
}

const initialState: FormDialogState = {
  isAddFormDialogOpen: false,
};

export const formDialogSlice = createAppSlice({
  name: 'formDialog',
  initialState,
  reducers: (create) => ({
    openAddFormDialog: create.reducer((state) => {
      state.isAddFormDialogOpen = true;
    }),
    closeAddFormDialog: create.reducer((state) => {
      state.isAddFormDialogOpen = false;
    }),
  }),
  selectors: {
    selectIsAddFormDialogOpen: (state) => state.isAddFormDialogOpen,
  },
});

export const { openAddFormDialog, closeAddFormDialog } =
  formDialogSlice.actions;
export const { selectIsAddFormDialogOpen } = formDialogSlice.selectors;
export default formDialogSlice;
