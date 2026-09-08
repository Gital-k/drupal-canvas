import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Dialog from '@/components/Dialog';
import {
  closeAllDialogs,
  selectDialogStates,
} from '@/features/ui/codeComponentDialogSlice';
import { translate } from '@/utils/translate';

const ComponentInLayoutDialog = () => {
  const dispatch = useAppDispatch();
  const { isInLayoutDialogOpen } = useAppSelector(selectDialogStates);

  const handleOpenChange = (open: boolean) => {
    if (!open) {
      dispatch(closeAllDialogs());
    }
  };

  return (
    <Dialog
      open={isInLayoutDialogOpen}
      onOpenChange={handleOpenChange}
      title={
        <>
          {translate('Unable to perform action:')}
          <br />
          {translate('Component in use')}
        </>
      }
      description={translate(
        'Please remove all instances of the component on the page before removing or deleting.',
      )}
      footer={{
        cancelText: translate('Cancel'),
      }}
    />
  );
};

export default ComponentInLayoutDialog;
