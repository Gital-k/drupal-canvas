import { useState } from 'react';
import {
  ChevronDownIcon,
  DotsVerticalIcon,
  MagnifyingGlassIcon,
  PlusIcon,
} from '@radix-ui/react-icons';
import {
  Badge,
  Button,
  DropdownMenu,
  Flex,
  IconButton,
  ScrollArea,
  TextField,
} from '@radix-ui/themes';

import EmptyStateCallout from '@/components/EmptyStateCallout';

import type { Workspace, WorkspaceStatus } from '@/types/Workspace';

import styles from './WorkspaceDropdown.module.css';

interface WorkspaceDropdownProps {
  workspaces: Workspace[];
  activeWorkspaceId: string | null;
  onSelect: (id: string) => void;
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

const WorkspaceDropdown = ({
  workspaces,
  activeWorkspaceId,
  onSelect,
  onAddWorkspace,
}: WorkspaceDropdownProps) => {
  const [searchTerm, setSearchTerm] = useState('');

  const filteredWorkspaces = workspaces.filter((workspace) =>
    workspace.name.toLowerCase().includes(searchTerm.toLowerCase()),
  );

  const showEmptyState = workspaces.length === 1 && searchTerm === '';
  const showNoResults = searchTerm !== '' && filteredWorkspaces.length === 0;

  return (
    <div data-testid="canvas-workspace-dropdown" className={styles.root}>
      <Flex direction="row" gap="2" mb="4">
        <form
          className={styles.search}
          onChange={(event) => {
            event.preventDefault();
            const form = event.currentTarget;
            const formElements = form.elements as typeof form.elements & {
              'canvas-workspace-search': HTMLInputElement;
            };
            setSearchTerm(formElements['canvas-workspace-search'].value);
          }}
          onSubmit={(event) => {
            event.preventDefault();
          }}
        >
          <TextField.Root
            autoComplete="off"
            id="canvas-workspace-search"
            placeholder="Search…"
            radius="medium"
            aria-label="Search workspaces"
            size="1"
          >
            <TextField.Slot>
              <MagnifyingGlassIcon height="16" width="16" />
            </TextField.Slot>
          </TextField.Root>
        </form>
        <DropdownMenu.Root>
          <DropdownMenu.Trigger>
            <Button
              variant="soft"
              data-testid="canvas-workspace-new-button"
              size="1"
            >
              <PlusIcon />
              New
              <ChevronDownIcon />
            </Button>
          </DropdownMenu.Trigger>
          <DropdownMenu.Content>
            <DropdownMenu.Item
              onClick={onAddWorkspace}
              data-testid="canvas-workspace-add-workspace-item"
            >
              Add workspace
            </DropdownMenu.Item>
          </DropdownMenu.Content>
        </DropdownMenu.Root>
      </Flex>

      {showEmptyState && (
        <EmptyStateCallout
          data-testid="canvas-workspace-empty-state"
          title="No workspaces yet"
          description={
            <>
              Workspaces allow you to stage changes before publishing.{' '}
              <button
                onClick={onAddWorkspace}
                className={styles.addWorkspaceLink}
                data-testid="canvas-workspace-add-link"
              >
                Add workspace
              </button>
            </>
          }
          variant="surface"
        />
      )}

      {showNoResults && (
        <EmptyStateCallout
          data-testid="canvas-workspace-no-results"
          title="No workspaces found"
          variant="surface"
        />
      )}

      {!showEmptyState && !showNoResults && (
        <ScrollArea scrollbars="vertical" style={{ height: 175 }}>
          <Flex direction="column" gap="1">
            {filteredWorkspaces.map((workspace) => {
              const isActive = workspace.id === activeWorkspaceId;
              const showBadge =
                workspace.status !== 'published' && !workspace.isMain;

              return (
                <Flex
                  key={workspace.id}
                  direction="row"
                  align="center"
                  justify="between"
                  className={styles.workspaceItem}
                  data-active={isActive}
                  data-testid={`canvas-workspace-item-${workspace.id}`}
                >
                  <button
                    className={styles.workspaceButton}
                    onClick={() => onSelect(workspace.id)}
                    aria-label={`Select ${workspace.name} workspace`}
                  >
                    <Flex
                      direction="row"
                      align="center"
                      justify="between"
                      flexGrow="1"
                      gap="2"
                    >
                      <span className={styles.workspaceName}>
                        {workspace.name}
                      </span>
                      {showBadge && (
                        <Badge
                          size="1"
                          color={statusColorMap[workspace.status] as 'yellow'}
                          variant="soft"
                        >
                          {statusLabelMap[workspace.status]}
                        </Badge>
                      )}
                    </Flex>
                  </button>

                  <DropdownMenu.Root>
                    <DropdownMenu.Trigger>
                      <IconButton
                        variant="ghost"
                        color="gray"
                        size="1"
                        className={styles.optionsButton}
                        aria-label={`Options for ${workspace.name}`}
                      >
                        <DotsVerticalIcon />
                      </IconButton>
                    </DropdownMenu.Trigger>
                    <DropdownMenu.Content>
                      <DropdownMenu.Item disabled>Rename</DropdownMenu.Item>
                      <DropdownMenu.Item disabled>Delete</DropdownMenu.Item>
                      <DropdownMenu.Item disabled>Duplicate</DropdownMenu.Item>
                    </DropdownMenu.Content>
                  </DropdownMenu.Root>
                </Flex>
              );
            })}
          </Flex>
        </ScrollArea>
      )}
    </div>
  );
};

export default WorkspaceDropdown;
