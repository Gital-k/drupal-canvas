import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Dialog from '@/components/Dialog';
import {
  selectCodeComponentProperty,
  setCodeComponentProperty,
  setForceRefresh,
} from '@/features/code-editor/codeEditorSlice';
import {
  closeAllDialogs,
  selectDialogStates,
  selectSelectedCodeComponent,
} from '@/features/ui/codeComponentDialogSlice';
import { setActivePanel } from '@/features/ui/primaryPanelSlice';
import {
  useGetComponentUsageDetailsQuery,
  useGetComponentUsageListQuery,
  useUpdateCodeComponentMutation,
} from '@/services/componentAndLayout';

import type { ComponentUsageDetailsResponse } from '@/services/componentAndLayout';

// Helper function to get the machine name from a component.
// Handles both JSComponent (with 'id') and CodeComponentSerialized (with 'machineName').
function getComponentMachineName(component: any): string {
  // Get the id or machineName
  const componentId = component.machineName || component.id;
  // Remove 'js.' prefix if present
  return componentId?.startsWith('js.')
    ? componentId.substring(3)
    : componentId;
}

// This handles the dialog for removing a JS component from components. This changes
// the component from being "exposed" to "internal".
const RemoveFromComponentsDialog = () => {
  const navigate = useNavigate();
  const [navigateTo, setNavigateTo] = useState(null as string | null);
  const selectedComponent = useAppSelector(selectSelectedCodeComponent);
  const isCodeEditorOpen = !!useAppSelector(
    selectCodeComponentProperty('machineName'),
  );
  const [updateCodeComponent, { isLoading, isSuccess, isError, error, reset }] =
    useUpdateCodeComponentMutation();
  const dispatch = useAppDispatch();
  const { isRemoveFromComponentsDialogOpen } =
    useAppSelector(selectDialogStates);
  const { codeComponentId: codeComponentBeingEditedId } = useParams();

  const componentListName = `js.${getComponentMachineName(selectedComponent)}`;
  const [statefulUsageDetails, setStatefulUsageDetails] =
    useState<ComponentUsageDetailsResponse | null>(null);

  // Check whether this component has any recorded usage (i.e. has ever been
  // placed on a page). If it has never been exposed, there is no usage data
  // and we skip the details query to avoid a 404.
  const {
    data: componentUsageList,
    isSuccess: componentUsageListSuccess,
    isError: isComponentUsageListError,
    error: componentUsageListError,
    isFetching,
  } = useGetComponentUsageListQuery(undefined, {
    skip: !isRemoveFromComponentsDialogOpen,
  });
  const componentInComponentList = !!(componentUsageList?.data || {})[
    componentListName
  ];
  const noUsageToCheck =
    !isFetching && componentUsageListSuccess && !componentInComponentList;

  const {
    data: usageDetails,
    isSuccess: usageLoadingSuccess,
    isError: isUsageError,
    error: usageError,
    isFetching: isUsageFetching,
  } = useGetComponentUsageDetailsQuery(componentListName, {
    skip:
      !isRemoveFromComponentsDialogOpen ||
      noUsageToCheck ||
      !componentUsageList,
    refetchOnMountOrArgChange: true,
  });

  useEffect(() => {
    if (usageDetails && !isUsageFetching) {
      setStatefulUsageDetails(usageDetails);
    }
  }, [usageDetails, isUsageFetching]);

  const handleSave = async () => {
    if (!selectedComponent) return;

    const machineName = getComponentMachineName(selectedComponent);
    await updateCodeComponent({
      id: machineName,
      changes: {
        status: false,
      },
    });

    if (isCodeEditorOpen) {
      // The code editor typically won't check auto-save updates if the component
      // being edited is the same as the one being updated. Force a refresh to
      // avoid auto-save mismatch errors.
      if (machineName === codeComponentBeingEditedId) {
        dispatch(setForceRefresh(true));
      }
      // If the code editor is open when the component is being set to internal,
      // also set the status in the codeEditorSlice to internal. While the
      // `updateCodeComponent` mutation invalidates cache of the code component
      // data, the code editor won't refetch while it's open.
      dispatch(setCodeComponentProperty(['status', false]));
      // Navigate to the code editor route that handles internal code components.
      setNavigateTo(`/code-editor/component/${machineName}`);
    }
  };

  const handleOpenChange = (open: boolean) => {
    if (!open) {
      reset();
      setStatefulUsageDetails(null);
      dispatch(closeAllDialogs());
    }
  };

  useEffect(() => {
    if (navigateTo) {
      navigate(navigateTo);
      setNavigateTo(null);
      dispatch(setActivePanel('code'));
    }
  }, [navigateTo, navigate, dispatch]);

  useEffect(() => {
    if (isSuccess) {
      dispatch(closeAllDialogs());
    }
  }, [isSuccess, dispatch]);

  useEffect(() => {
    if (isError) {
      console.error('Failed to remove from components:', error);
    }
  }, [isError, error]);

  useEffect(() => {
    if (isUsageError) {
      console.error('Failed to get component usage data:', usageError);
    }
  }, [isUsageError, usageError]);

  useEffect(() => {
    if (isComponentUsageListError) {
      console.error(
        'Failed to get component usage list:',
        componentUsageListError,
      );
    }
  }, [isComponentUsageListError, componentUsageListError]);

  // The component is currently placed on one or more pages.
  const isInUse =
    !isUsageFetching &&
    statefulUsageDetails?.content &&
    statefulUsageDetails.content.length > 0;

  const componentUsageListErrorOutput = {
    title: 'Failed to fetch component list',
    message: `An error ${
      componentUsageListError && 'status' in componentUsageListError
        ? '(HTTP ' + componentUsageListError.status + ')'
        : ''
    } occurred while fetching the component usage list. Please check the browser console for more details.`,
  };

  const usageErrorOutput = {
    title: 'Failed to fetch component usage details',
    message: `An error ${
      usageError && 'status' in usageError
        ? '(HTTP ' + usageError.status + ')'
        : ''
    } occurred while fetching component usage. Please check the browser console for more details.`,
  };

  const formImpactingErrors = isUsageError || isComponentUsageListError;

  // Only render the dialog once usage data has been resolved (or errored).
  const dialogReady =
    (noUsageToCheck ||
      usageLoadingSuccess ||
      isUsageError ||
      isComponentUsageListError) &&
    !isFetching &&
    !isUsageFetching;

  const description = isInUse ? (
    <>
      This component is currently used on{' '}
      <b>{statefulUsageDetails!.content.length}</b> page
      {statefulUsageDetails!.content.length > 1 ? 's' : ''}. Please remove all
      instances of the component from those pages before removing it from
      components.
    </>
  ) : (
    <>
      This component will be moved to the <b>Code</b> section and will no
      longer be available to use on the page.
      <br />
      <br />
      You can re-add it to <b>Components</b> from the <b>Code</b> section at
      any time.
    </>
  );

  return (
    dialogReady && (
      <Dialog
        open={isRemoveFromComponentsDialogOpen}
        onOpenChange={handleOpenChange}
        title="Remove from components"
        description={!formImpactingErrors && description}
        error={
          (isError && {
            title: 'Failed to remove from components',
            message: `An error ${
              'status' in error ? '(HTTP ' + error.status + ')' : ''
            } occurred while removing from components. Please check the browser console for more details.`,
            resetButtonText: 'Try again',
            onReset: handleSave,
          }) ||
          (isUsageError && usageErrorOutput) ||
          (isComponentUsageListError && componentUsageListErrorOutput) ||
          undefined
        }
        footer={{
          cancelText: 'Cancel',
          confirmText:
            (!formImpactingErrors && !isInUse && 'Remove') || undefined,
          onConfirm:
            (!formImpactingErrors && !isInUse && handleSave) || undefined,
          isConfirmDisabled: false,
          isConfirmLoading: isLoading,
          isDanger: true,
        }}
      />
    )
  );
};

export default RemoveFromComponentsDialog;
