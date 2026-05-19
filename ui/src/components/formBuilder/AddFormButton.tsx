import { InputIcon } from '@radix-ui/react-icons';
import { DropdownMenu } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import { openAddFormDialog } from '@/features/ui/formDialogSlice';

const AddFormButton = () => {
  const dispatch = useAppDispatch();

  return (
    <DropdownMenu.Item
      onSelect={() => dispatch(openAddFormDialog())}
      data-testid="canvas-library-new-form-button"
    >
      <InputIcon />
      Form
    </DropdownMenu.Item>
  );
};

export default AddFormButton;
