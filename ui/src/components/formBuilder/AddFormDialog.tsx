import { useState } from 'react';
import parse from 'html-react-parser';
import { useNavigate } from 'react-router-dom';
import { Flex, TextField } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Dialog, { DialogFieldLabel } from '@/components/Dialog';
import { extractErrorMessageFromApiResponse } from '@/features/error-handling/error-handling';
import {
  closeAddFormDialog,
  selectIsAddFormDialogOpen,
} from '@/features/ui/formDialogSlice';
import { useCreateFormMutation } from '@/services/canvasFormApi';

const AddFormDialog = () => {
  const dispatch = useAppDispatch();
  const navigate = useNavigate();
  const isOpen = useAppSelector(selectIsAddFormDialogOpen);
  const [formName, setFormName] = useState('');
  const [createForm, { isLoading, isError, error, reset }] =
    useCreateFormMutation();

  const handleOpenChange = (open: boolean) => {
    if (!open) {
      setFormName('');
      reset();
      dispatch(closeAddFormDialog());
    }
  };

  const handleCreate = async () => {
    if (!formName.trim()) return;
    try {
      const result = await createForm({
        name: formName.trim(),
        formElements: [],
        submitSettings: {
          button_text: 'Submit',
          confirmation_message: 'Thank you for your submission.',
          redirect_url: '',
        },
      }).unwrap();
      setFormName('');
      reset();
      dispatch(closeAddFormDialog());
      navigate(`/form-builder/${result.id}`);
    } catch {
      // error state handled by isError below
    }
  };

  return (
    <Dialog
      open={isOpen}
      onOpenChange={handleOpenChange}
      title="Create new form"
      error={
        isError
          ? {
              title: 'Failed to create form',
              message: parse(extractErrorMessageFromApiResponse(error)),
              resetButtonText: 'Try again',
              onReset: handleCreate,
            }
          : undefined
      }
      footer={{
        cancelText: 'Cancel',
        confirmText: 'Create',
        onConfirm: handleCreate,
        isConfirmDisabled: !formName.trim(),
        isConfirmLoading: isLoading,
      }}
    >
      <form
        onSubmit={(e) => {
          e.preventDefault();
          if (formName.trim()) handleCreate();
        }}
      >
        <Flex direction="column" gap="2">
          <DialogFieldLabel htmlFor="newFormName">Form name</DialogFieldLabel>
          <TextField.Root
            autoFocus
            autoComplete="off"
            id="newFormName"
            value={formName}
            onChange={(e) => setFormName(e.target.value)}
            placeholder="Enter a name"
            size="1"
          />
        </Flex>
      </form>
    </Dialog>
  );
};

export default AddFormDialog;
