import { useEffect, useMemo, useState } from 'react';
import { Flex, Select, TextField } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Dialog, { DialogFieldLabel } from '@/components/Dialog';
import {
  selectIsAddWorkspaceModalOpen,
  setActiveWorkspace,
  setAddWorkspaceModalOpen,
} from '@/features/workspaces/workspacesSlice';
import {
  useCreateWorkspaceMutation,
  useGetWorkspacesQuery,
} from '@/services/workspacesApi';

import type { Workspace } from '@/types/Workspace';

const AddWorkspaceModal = () => {
  const dispatch = useAppDispatch();
  const isOpen = useAppSelector(selectIsAddWorkspaceModalOpen);
  const { data: workspacesResponse } = useGetWorkspacesQuery();
  const [createWorkspace, { isLoading: isCreating }] =
    useCreateWorkspaceMutation();

  const [name, setName] = useState('');
  const [sourceId, setSourceId] = useState<string>('');

  // Reset form when modal opens/closes
  useEffect(() => {
    if (isOpen) {
      setName('');
      // Default to the main workspace
      const mainWorkspace = workspacesResponse?.find((w) => w.isMain);
      if (mainWorkspace) {
        setSourceId(mainWorkspace.id);
      }
    }
  }, [isOpen, workspacesResponse]);

  const workspaces = useMemo(
    () => (Array.isArray(workspacesResponse) ? workspacesResponse : []),
    [workspacesResponse],
  );

  // Filter workspaces that can be used as sources (main or published)
  const sourceWorkspaces = useMemo(
    () => workspaces.filter((w) => w.isMain || w.status === 'published'),
    [workspaces],
  );

  const handleOpenChange = (open: boolean) => {
    if (!open) {
      dispatch(setAddWorkspaceModalOpen(false));
      setName('');
    }
  };

  const handleCreate = async () => {
    if (!name.trim()) return;

    try {
      const result = await createWorkspace({
        name: name.trim(),
        sourceId: sourceId || undefined,
      }).unwrap();

      if (result.data) {
        dispatch(setActiveWorkspace(result.data.id));
        dispatch(setAddWorkspaceModalOpen(false));
        setName('');
      }
    } catch (error) {
      // Error is handled by RTK error handling middleware (rtkQueryErrorHandler)
      // No custom error UI needed per the spec
    }
  };

  const getWorkspaceLabel = (workspace: Workspace): string => {
    if (workspace.isMain) {
      return `${workspace.name} (Main)`;
    }
    return workspace.name;
  };

  return (
    <Dialog
      open={isOpen}
      onOpenChange={handleOpenChange}
      title="Add workspace"
      width="360px"
      footer={{
        cancelText: 'Cancel',
        confirmText: 'Create',
        onConfirm: handleCreate,
        onCancel: () => dispatch(setAddWorkspaceModalOpen(false)),
        isConfirmDisabled: !name.trim() || isCreating,
        isConfirmLoading: isCreating,
      }}
    >
      <Flex direction="column" gap="3">
        <div>
          <DialogFieldLabel htmlFor="workspace-source">
            Source workspace
          </DialogFieldLabel>
          <Select.Root value={sourceId} onValueChange={setSourceId} size="2">
            <Select.Trigger
              id="workspace-source"
              data-testid="add-workspace-source-select"
              placeholder="Select source workspace"
              style={{ width: '100%' }}
            />
            <Select.Content position="popper">
              {sourceWorkspaces.map((workspace) => (
                <Select.Item
                  key={workspace.id}
                  value={workspace.id}
                  data-testid={`workspace-source-option-${workspace.id}`}
                >
                  {getWorkspaceLabel(workspace)}
                </Select.Item>
              ))}
            </Select.Content>
          </Select.Root>
        </div>

        <div>
          <DialogFieldLabel htmlFor="workspace-name">
            Workspace name
          </DialogFieldLabel>
          <TextField.Root
            id="workspace-name"
            data-testid="add-workspace-name-input"
            placeholder="e.g., Q1 Marketing Campaign"
            value={name}
            onChange={(e) => setName(e.target.value)}
            autoFocus
          />
        </div>
      </Flex>
    </Dialog>
  );
};

export default AddWorkspaceModal;
