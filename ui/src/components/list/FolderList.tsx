import { useState } from 'react';
import { Form } from 'radix-ui';
import { Box, TextField } from '@radix-ui/themes';

import { useAppSelector } from '@/app/hooks';
import Dialog, { DialogFieldLabel } from '@/components/Dialog';
import SidebarFolder from '@/components/sidePanel/SidebarFolder';
import UnifiedMenu from '@/components/UnifiedMenu';
import { selectActivePanel } from '@/features/ui/primaryPanelSlice';
import {
  useDeleteFolderMutation,
  useUpdateFolderMutation,
} from '@/services/componentAndLayout';

import type { ReactNode } from 'react';
import type { CodeComponentSerialized } from '@/types/CodeComponent';
import type {
  ComponentsList,
  FolderInList,
  FoldersInList,
} from '@/types/Component';
import type { PatternsList } from '@/types/Pattern';

interface FolderData {
  componentIndexedFolders: Record<string, string>;
  folders: Record<
    string,
    { name?: string; weight?: number; [key: string]: any }
  >;
}

// Displays a list of components or patterns in a folder structure.
const FolderList = ({
  folder,
  children,
}: {
  folder: FolderInList;
  children: ReactNode;
}) => {
  const [
    updateFolder,
    {
      isSuccess: updateFolderSuccess,
      isError: isUpdateFolderError,
      error: updateFolderError,
    },
  ] = useUpdateFolderMutation();
  const [
    deleteFolder,
    {
      isSuccess: deleteFolderSuccess,
      isError: isDeleteFolderError,
      error: deleteFolderError,
    },
  ] = useDeleteFolderMutation();
  const [renameFolderDialogOpen, setRenameFolderDialogOpen] = useState(false);
  const [deleteFolderDialogOpen, setDeleteFolderDialogOpen] = useState(false);
  const [folderName, setFolderName] = useState(folder.name);

  const activePanel = useAppSelector(selectActivePanel);
  const menuItems = [];

  const moveFolderHandler = (direction: 'up' | 'down') => {
    console.log('move folder', direction, folder.weight);
    // @todo only changes the weight of this folder, but does not yet adjust the
    //  weights of other folders - this won't work if weights are nearby.
    // @todo the get folders endpoint returns everything with a weight of 0
    // so that probably needs to change before the above @todo can happen.
    updateFolder({
      id: folder.id,
      body: {
        weight:
          direction === 'up'
            ? (folder.weight || 0) - 1
            : (folder.weight || 0) + 1,
        items: Object.keys(folder.items),
        name: folder.name,
      },
    });
  };

  const renameFolderHandler = () => {
    updateFolder({
      id: folder.id,
      body: {
        weight: folder.weight,
        items: Object.keys(folder.items),
        name: folderName.trim(),
      },
    });
  };

  const deleteFolderHandler = () => {
    console.log('delete folder', folder.id);
  };

  if (activePanel === 'manageLibrary') {
    menuItems.push(
      <UnifiedMenu.Item
        key="rename"
        onClick={() => setRenameFolderDialogOpen(true)}
      >
        Rename
      </UnifiedMenu.Item>,
    );
    menuItems.push(<UnifiedMenu.Separator key="move-section-separator" />);
    // @todo disable if first folder
    menuItems.push(
      <UnifiedMenu.Item key="move-up" onClick={() => moveFolderHandler('up')}>
        Move up
      </UnifiedMenu.Item>,
    );
    // @todo disable if last folder
    menuItems.push(
      <UnifiedMenu.Item
        key="move-down"
        onClick={() => moveFolderHandler('down')}
      >
        Move down
      </UnifiedMenu.Item>,
    );
    menuItems.push(<UnifiedMenu.Separator key="delete-section-separator" />);
    menuItems.push(
      <UnifiedMenu.Item
        onClick={() => setDeleteFolderDialogOpen(true)}
        color="red"
        key="delete"
      >
        Delete
      </UnifiedMenu.Item>,
    );
  }
  // Determine the length of items in the folder, be it object or array.
  const getItemsLength = () => {
    if (Array.isArray(folder.items)) {
      return folder.items.length;
    }
    return Object.keys(folder.items).length;
  };

  return (
    <>
      <SidebarFolder
        menuItems={menuItems.length ? menuItems : undefined}
        name={folder.name}
        count={getItemsLength()}
      >
        {children}
      </SidebarFolder>
      {renameFolderDialogOpen && (
        <Dialog
          open={renameFolderDialogOpen}
          onOpenChange={(open) => setRenameFolderDialogOpen(open)}
          title="Rename folder"
          footer={{
            confirmText: 'Add',
            cancelText: 'Cancel',
            onConfirm: renameFolderHandler,
          }}
        >
          <Box pb="3" m="0" data-testid="xb-manage-library-add-folder-content">
            {renameFolderDialogOpen && (
              // <Form.Root
              //   onSubmit={(e) => {
              //     e.preventDefault();
              //     if (folderName.trim() && !validationError) {
              //       renameFolderHandler();
              //     }
              //   }}
              //   id="add-new-folder-in-tab-form"
              // >
              //   <Form.Field name="folder-name">
              <>
                <DialogFieldLabel htmlFor="folder-name">
                  Folder name
                </DialogFieldLabel>
                <TextField.Root
                  data-testid="canvas-manage-library-new-folder-name"
                  id="folder-name"
                  variant="surface"
                  onChange={(e) => setFolderName(e.target.value)}
                  value={folderName}
                  size="1"
                />
                {/*{validationError && (*/}
                {/*  <Text size="1" color="red" weight="medium">*/}
                {/*    {validationError}*/}
                {/*  </Text>*/}
                {/*)}*/}
                {/*  </Form.Field>*/}
                {/*</Form.Root>*/}
              </>
            )}
          </Box>
        </Dialog>
      )}
      {deleteFolderDialogOpen && (
        <Dialog
          open={deleteFolderDialogOpen}
          onOpenChange={(open) => setDeleteFolderDialogOpen(open)}
          title="Delete folder (not done yet)"
          description="the dialog and functionality for deleting a folder is not implemented yet"
        />
      )}
    </>
  );
};

export interface FolderComponentsResult {
  folderComponents: Record<string, FolderInList>;
  topLevelComponents: Record<string, any>;
}

// Take a list of components a list of all folders, both in the formats returned
// by componentAndLayoutApi, and return an object with folderComponents
// (structure of folders with the components inside them) and topLevelComponents
export const folderfyComponents = (
  components:
    | ComponentsList
    | PatternsList
    | Record<string, CodeComponentSerialized>
    | undefined,
  folders: FolderData | undefined,
  isLoading: boolean,
  foldersLoading: boolean,
  type: string,
): FolderComponentsResult => {
  if (isLoading || foldersLoading || (!folders && !components)) {
    return { folderComponents: {}, topLevelComponents: {} };
  }

  const folderComponents: Record<string, FolderInList> = {};
  const topLevelComponents: Record<string, any> = {};

  Object.entries(components || {}).forEach(([id, component]) => {
    if (folders && folders.componentIndexedFolders[id]) {
      const folderId = folders.componentIndexedFolders[id];
      if (!folderComponents[folderId]) {
        folderComponents[folderId] = {
          id: folderId,
          name: folders.folders[folderId]?.name || 'Unknown folder',
          items: {},
          weight: folders.folders[folderId]?.weight || 0,
        };
      }
      folderComponents[folderId].items[id] = component;
    } else {
      topLevelComponents[id] = component;
    }
  });
  Object.entries(folders?.folders || []).forEach(([id, folder]) => {
    if (folder.items.length === 0 && folder.type === type) {
      folderComponents[id] = {
        id,
        name: folder.name || '',
        items: {} as ComponentsList,
        weight: folder.weight || 0,
      };
    }
  });
  return { folderComponents, topLevelComponents };
};

export const sortFolderList = (
  folderComponents: Record<string, FolderInList>,
): FoldersInList => {
  // Sorts the folders first by weight, then by name within the weights.
  return folderComponents
    ? (Object.values(folderComponents).sort(
        (a: FolderInList, b: FolderInList) => {
          const aWeight = a?.weight || 0;
          const bWeight = b?.weight || 0;
          if (aWeight !== bWeight) {
            return aWeight - bWeight;
          }
          const aName = a?.name || '';
          const bName = b?.name || '';
          return aName.localeCompare(bName);
        },
      ) as FoldersInList)
    : [];
};

export type { FolderData };

export default FolderList;
