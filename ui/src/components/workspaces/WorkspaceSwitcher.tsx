import { useState } from 'react';
import { ChevronDownIcon } from '@radix-ui/react-icons';
import { Badge, Button, Flex, Popover } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import Panel from '@/components/Panel';
import {
  selectActiveWorkspaceId,
  setActiveWorkspace,
} from '@/features/workspaces/workspacesSlice';
import { useGetWorkspacesQuery } from '@/services/workspacesApi';
import { getCanvasSettings } from '@/utils/drupal-globals';

import WorkspaceDropdown from './WorkspaceDropdown';

import type { WorkspaceStatus } from '@/types/Workspace';

import styles from './WorkspaceSwitcher.module.css';

interface WorkspaceSwitcherProps {
  onAddWorkspace: () => void;
}

const statusColorMap: Record<WorkspaceStatus, string> = {
  draft: 'yellow',
  in_review: 'pink',
  scheduled: 'purple',
  published: 'gray',
};

const statusLabelMap: Record<WorkspaceStatus, string> = {
  draft: 'Draft',
  in_review: 'In review',
  scheduled: 'Scheduled',
  published: 'Published',
};

const WorkspaceSwitcher = ({ onAddWorkspace }: WorkspaceSwitcherProps) => {
  const [popoverOpen, setPopoverOpen] = useState(false);
  const dispatch = useAppDispatch();
  const activeWorkspaceId = useAppSelector(selectActiveWorkspaceId);

  // Check if dev workspaces feature is enabled
  const devWorkspaces = getCanvasSettings()?.permissions?.devWorkspaces;

  // Fetch workspaces - only call hook if devWorkspaces is true
  const { data: workspacesResponse } = useGetWorkspacesQuery(undefined, {
    skip: !devWorkspaces,
  });

  // Return null early if devWorkspaces is disabled - no hooks called before this
  if (!devWorkspaces) {
    return null;
  }

  // Ensure workspaces is always an array
  const workspaces = Array.isArray(workspacesResponse)
    ? workspacesResponse
    : [];

  // Resolve active workspace - fall back to main workspace if none selected
  const activeWorkspace =
    workspaces.find((w) => w.id === activeWorkspaceId) ??
    workspaces.find((w) => w.isMain) ??
    workspaces[0];

  const handleSelect = (id: string) => {
    dispatch(setActiveWorkspace(id));
    setPopoverOpen(false);
  };

  const handleAddWorkspace = () => {
    setPopoverOpen(false);
    onAddWorkspace();
  };

  // Show status badge unless published
  const showBadge = activeWorkspace?.status !== 'published';

  return (
    <Popover.Root open={popoverOpen} onOpenChange={setPopoverOpen}>
      <Popover.Trigger>
        <Button
          color="gray"
          variant="soft"
          size="1"
          data-testid="canvas-workspace-switcher"
        >
          <Flex gap="2" align="center">
            <span className={styles.workspaceName}>
              {activeWorkspace?.name ?? 'Live'}
            </span>
            <ChevronDownIcon />
            {showBadge && activeWorkspace && (
              <Badge
                size="1"
                color={statusColorMap[activeWorkspace.status] as 'yellow'}
                variant="soft"
              >
                {statusLabelMap[activeWorkspace.status]}
              </Badge>
            )}
          </Flex>
        </Button>
      </Popover.Trigger>
      <Popover.Content
        size="2"
        width="100vw"
        maxWidth="320px"
        asChild
        align="center"
      >
        <Panel className="canvas-app" mt="4">
          <WorkspaceDropdown
            workspaces={workspaces}
            activeWorkspaceId={activeWorkspaceId ?? activeWorkspace?.id ?? null}
            onSelect={handleSelect}
            onAddWorkspace={handleAddWorkspace}
          />
        </Panel>
      </Popover.Content>
    </Popover.Root>
  );
};

export default WorkspaceSwitcher;
