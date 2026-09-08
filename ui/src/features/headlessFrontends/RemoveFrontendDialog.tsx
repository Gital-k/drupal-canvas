import Dialog from '@/components/Dialog';
import { translate } from '@/utils/translate';

import type { HeadlessFrontend } from './types';

interface RemoveFrontendDialogProps {
  frontend: HeadlessFrontend | null;
  onOpenChange: (open: boolean) => void;
  onConfirm: () => void;
  isRemoving: boolean;
}

const RemoveFrontendDialog = ({
  frontend,
  onOpenChange,
  onConfirm,
  isRemoving,
}: RemoveFrontendDialogProps) => (
  <Dialog
    open={frontend !== null}
    onOpenChange={onOpenChange}
    title={translate('Remove frontend')}
    description={
      <>
        {translate('You are about to remove')} <b>{frontend?.url}</b>{' '}
        {translate('from your headless frontends.')}{' '}
        {translate('You can add it again at any time.')}
      </>
    }
    footer={{
      cancelText: translate('Cancel'),
      confirmText: translate('Remove'),
      onConfirm,
      isConfirmDisabled: isRemoving,
      isConfirmLoading: isRemoving,
      isDanger: true,
    }}
  />
);

export default RemoveFrontendDialog;
