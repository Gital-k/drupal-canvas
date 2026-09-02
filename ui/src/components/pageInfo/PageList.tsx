import { useEffect, useState } from 'react';
import { useDraggable } from '@dnd-kit/core';
import {
  ChevronDownIcon,
  ClockIcon,
  FileTextIcon,
  LockClosedIcon,
  MagnifyingGlassIcon,
  PlusIcon,
} from '@radix-ui/react-icons';
import {
  AlertDialog,
  Badge,
  Box,
  Button,
  ContextMenu,
  DropdownMenu,
  Flex,
  Skeleton,
  TextField,
  Tooltip,
} from '@radix-ui/themes';

import EmptyStateCallout from '@/components/EmptyStateCallout';
import ErrorCard from '@/components/error/ErrorCard';
import InfiniteScrollObserver from '@/components/InfiniteScrollObserver';
import FolderList, { sortFolderList } from '@/components/list/FolderList';
import UncategorizedDropZone from '@/components/list/UncategorizedDropZone';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import UnifiedMenu from '@/components/UnifiedMenu';
import FolderNameInput from '@/features/brandKit/components/FolderNameInput';
import { usePermissionCheck } from '@/hooks/usePermissionCheck';
import {
  useGetFoldersQuery,
  useUpdateFolderMutation,
} from '@/services/componentAndLayout';
import { useSetContentFolderMutation } from '@/services/content';

import type { FormEvent } from 'react';
import type { FolderInList } from '@/types/Component';
import type { ContentStub } from '@/types/Content';

const hasPermission = (
  permission:
    | 'edit'
    | 'duplicate'
    | 'homepage'
    | 'delete'
    | 'unpublish'
    | 'publish',
  item: ContentStub,
) => {
  const links = item.links || {};
  switch (permission) {
    case 'edit':
      return !!links['edit-form'];
    case 'duplicate':
      return !!links['https://drupal.org/project/canvas#link-rel-duplicate'];
    case 'homepage':
      return !!links[
        'https://drupal.org/project/canvas#link-rel-set-as-homepage'
      ];
    case 'delete':
      return !!links['delete-form'];
    case 'unpublish':
      return !!links['disable'];
    case 'publish':
      return !!links['enable'];
    default:
      return false;
  }
};

// Helper function to create dropdown menu content for a page item
const createPageMenuContent = (
  item: ContentStub,
  onDuplicate?: (page: ContentStub) => void,
  onSetHomepage?: (page: ContentStub) => void,
  onUnpublish?: (page: ContentStub) => void,
  onPublish?: (page: ContentStub) => void,
  onDelete?: (page: ContentStub) => void,
  homepagePath?: string,
  folders: FolderInList[] = [],
  onMoveToFolder?: (page: ContentStub, folderId: string | null) => void,
) => {
  const hasDuplicate = hasPermission('duplicate', item);
  const hasHomepage =
    hasPermission('homepage', item) && item.internalPath !== homepagePath;
  const hasUnpublish = hasPermission('unpublish', item);
  const hasPublish = hasPermission('publish', item);
  const hasDelete = hasPermission('delete', item);
  // Filing needs edit access to the page and somewhere to file it to.
  const moveTargets = folders.filter((folder) => folder.id !== item.folder);
  const canMove =
    !!onMoveToFolder &&
    hasPermission('edit', item) &&
    (moveTargets.length > 0 || !!item.folder);

  // If no permissions, don't render dropdown
  if (
    !hasDuplicate &&
    !hasHomepage &&
    !hasUnpublish &&
    !hasPublish &&
    !hasDelete &&
    !canMove
  ) {
    return null;
  }

  return (
    <>
      <UnifiedMenu.Label>{item.autoSaveLabel || item.title}</UnifiedMenu.Label>
      <UnifiedMenu.Separator />
      {canMove && (
        <>
          <UnifiedMenu.Sub>
            <UnifiedMenu.SubTrigger data-testid="canvas-page-move-to-folder">
              Move to folder
            </UnifiedMenu.SubTrigger>
            <UnifiedMenu.SubContent>
              {moveTargets.map((folder) => (
                <UnifiedMenu.Item
                  key={folder.id}
                  onClick={(event) => event.stopPropagation()}
                  onSelect={() => onMoveToFolder?.(item, folder.id)}
                >
                  {folder.name}
                </UnifiedMenu.Item>
              ))}
              {item.folder && (
                <>
                  {moveTargets.length > 0 && <UnifiedMenu.Separator />}
                  <UnifiedMenu.Item
                    onClick={(event) => event.stopPropagation()}
                    onSelect={() => onMoveToFolder?.(item, null)}
                  >
                    Remove from folder
                  </UnifiedMenu.Item>
                </>
              )}
            </UnifiedMenu.SubContent>
          </UnifiedMenu.Sub>
          <UnifiedMenu.Separator />
        </>
      )}
      {hasDuplicate && (
        <UnifiedMenu.Item
          onClick={(event) => event.stopPropagation()}
          onSelect={onDuplicate ? () => onDuplicate(item) : undefined}
        >
          Duplicate page
        </UnifiedMenu.Item>
      )}
      {hasHomepage && (
        <>
          {hasDuplicate && <UnifiedMenu.Separator />}
          <UnifiedMenu.Item
            onClick={(event) => event.stopPropagation()}
            onSelect={onSetHomepage ? () => onSetHomepage(item) : undefined}
          >
            Set as homepage
          </UnifiedMenu.Item>
        </>
      )}
      {hasUnpublish && (
        <>
          {(hasDuplicate || hasHomepage) && <UnifiedMenu.Separator />}
          <UnifiedMenu.Item
            onClick={(event) => event.stopPropagation()}
            onSelect={onUnpublish ? () => onUnpublish(item) : undefined}
          >
            Unpublish page
          </UnifiedMenu.Item>
        </>
      )}
      {hasPublish && (
        <>
          {(hasDuplicate || hasHomepage || hasUnpublish) && (
            <UnifiedMenu.Separator />
          )}
          <UnifiedMenu.Item
            onClick={(event) => event.stopPropagation()}
            onSelect={onPublish ? () => onPublish(item) : undefined}
          >
            Publish page
          </UnifiedMenu.Item>
        </>
      )}
      {hasDelete && (
        <>
          {(hasDuplicate || hasHomepage || hasUnpublish || hasPublish) && (
            <UnifiedMenu.Separator />
          )}
          <AlertDialog.Root>
            <AlertDialog.Trigger>
              <UnifiedMenu.Item
                onClick={(event) => event.stopPropagation()}
                onSelect={(event) => event.preventDefault()}
                color="red"
              >
                Delete page
              </UnifiedMenu.Item>
            </AlertDialog.Trigger>
            <AlertDialog.Content>
              <AlertDialog.Title>Delete {item.title} page</AlertDialog.Title>
              <AlertDialog.Description size="2">
                This action will permanently delete the page and all of its
                contents. This action cannot be undone.
              </AlertDialog.Description>
              <Flex gap="3" mt="4" justify="end">
                <AlertDialog.Cancel>
                  <Button variant="soft" color="gray">
                    Cancel
                  </Button>
                </AlertDialog.Cancel>
                <AlertDialog.Action>
                  <Button
                    variant="solid"
                    color="red"
                    onClick={() => onDelete?.(item)}
                  >
                    Delete page
                  </Button>
                </AlertDialog.Action>
              </Flex>
            </AlertDialog.Content>
          </AlertDialog.Root>
        </>
      )}
    </>
  );
};

// Component for individual page item to manage menu state
const PageListItem = ({
  item,
  isSelected,
  isHomepage,
  homepagePath,
  onSelect,
  onDuplicate,
  onSetHomepage,
  onUnpublish,
  onPublish,
  onDelete,
  folders = [],
  onMoveToFolder,
}: {
  item: ContentStub;
  isSelected: boolean;
  isHomepage: boolean;
  homepagePath?: string;
  onSelect?: (value: ContentStub) => void;
  onDuplicate?: (page: ContentStub) => void;
  onSetHomepage?: (page: ContentStub) => void;
  onUnpublish?: (page: ContentStub) => void;
  onPublish?: (page: ContentStub) => void;
  onDelete?: (page: ContentStub) => void;
  folders?: FolderInList[];
  onMoveToFolder?: (page: ContentStub, folderId: string | null) => void;
}) => {
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const title = `${item.autoSaveLabel || item.title} ${item.autoSavePath || item.path}`;
  // Pages the user may see but not edit are listed — hiding them would make
  // the list lie about what exists — but they lead nowhere: opening one would
  // load an editor that cannot fetch its layout. They are greyed out, carry a
  // lock and ignore clicks and drags instead.
  const canEdit = hasPermission('edit', item);
  // The row can be dragged onto a folder to file the page there, which needs
  // the same rights as the "Move to folder" menu.
  const canBeFiled = canEdit && folders.length > 0;
  const {
    attributes: dragAttributes,
    listeners: dragListeners,
    setNodeRef: setDragRef,
  } = useDraggable({
    id: `page:${item.id}`,
    data: {
      type: 'page',
      origin: 'pages',
      entityType: 'canvas_page',
      pageId: item.id,
      folderId: item.folder ?? null,
      // The drag overlay labels itself from `name`.
      name: item.autoSaveLabel || item.title,
    },
    disabled: !canBeFiled,
  });
  const dropdownMenuContent = createPageMenuContent(
    item,
    onDuplicate,
    onSetHomepage,
    onUnpublish,
    onPublish,
    onDelete,
    homepagePath,
    folders,
    onMoveToFolder,
  );

  // Determine unpublished status:
  // - Show "Clock icon + Unpublish" if there's an unsaved status change to unpublished
  // - Show "Unpublished" if page is unpublished (but not a new draft) and no unsaved changes
  // - Draft pages (never published) should not show any unpublished badge
  const isUnpublished =
    !item.status && !item.isNew && !item.hasUnsavedStatusChange;
  const willBeUnpublished =
    !item.status && !item.isNew && item.hasUnsavedStatusChange;

  const row = (
    /* The drag props go on this wrapper, not on SidebarNode: SidebarNode
       declares a forwarded ref but never attaches it, and dnd-kit needs a
       real node. Same arrangement as the component library's ListItem. */
    <div
      {...(canBeFiled ? dragAttributes : {})}
      {...(canBeFiled ? dragListeners : {})}
      role={undefined}
      data-canvas-page-title={item.autoSaveLabel || item.title}
      ref={canBeFiled ? setDragRef : undefined}
    >
      <SidebarNode
        draggable={canBeFiled}
        disabled={!canEdit}
        data-canvas-page-locked={!canEdit ? 'true' : undefined}
        leadingContent={
          !canEdit ? (
            <Tooltip content="You do not have permission to edit this page">
              <LockClosedIcon aria-label="Not editable" />
            </Tooltip>
          ) : undefined
        }
        title={title}
        variant={isHomepage ? 'homepage' : 'page'}
        selected={isSelected}
        trailingContent={
          !isMenuOpen && (isUnpublished || willBeUnpublished) ? (
            willBeUnpublished ? (
              <Tooltip content="Applies on next publish">
                <Badge
                  size="1"
                  variant="solid"
                  color={isSelected ? 'blue' : 'gray'}
                >
                  <Flex align="center" gap="1">
                    <ClockIcon width="11" height="11" />
                    Unpublish
                  </Flex>
                </Badge>
              </Tooltip>
            ) : (
              <Badge
                size="1"
                variant="solid"
                color={isSelected ? 'blue' : 'gray'}
              >
                Unpublished
              </Badge>
            )
          ) : undefined
        }
        dropdownMenuContent={
          dropdownMenuContent ? (
            <UnifiedMenu.Content menuType="dropdown">
              {dropdownMenuContent}
            </UnifiedMenu.Content>
          ) : null
        }
        onMenuOpenChange={setIsMenuOpen}
        onClick={canEdit && onSelect ? () => onSelect(item) : undefined}
        data-canvas-page-id={item.id}
      />
    </div>
  );

  // Without a single permitted operation there is nothing to put in a context
  // menu, so the row does not open an empty one.
  if (!dropdownMenuContent) {
    return row;
  }

  return (
    <ContextMenu.Root>
      <ContextMenu.Trigger>{row}</ContextMenu.Trigger>
      <UnifiedMenu.Content menuType="context" align="start" side="right">
        {dropdownMenuContent}
      </UnifiedMenu.Content>
    </ContextMenu.Root>
  );
};

const ContentGroup = ({
  items,
  homepagePath,
  selectedPageId,
  onSelect,
  onDuplicate,
  onSetHomepage,
  onUnpublish,
  onPublish,
  onDelete,
}: {
  items: ContentStub[];
  homepagePath?: string;
  selectedPageId?: string | number;
  onSelect?: (value: ContentStub) => void;
  onDuplicate?: (page: ContentStub) => void;
  onSetHomepage?: (page: ContentStub) => void;
  onUnpublish?: (page: ContentStub) => void;
  onPublish?: (page: ContentStub) => void;
  onDelete?: (page: ContentStub) => void;
}) => {
  const { data: folderData } = useGetFoldersQuery();
  const [updateFolder] = useUpdateFolderMutation();
  const [setContentFolder] = useSetContentFolderMutation();
  const administerFolders = usePermissionCheck({ hasPermission: 'folders' });

  if (items.length === 0) {
    return (
      <EmptyStateCallout
        data-testid="canvas-page-list"
        title="No pages found"
        variant="surface"
      />
    );
  }

  const renderItem = (item: ContentStub, folders: FolderInList[]) => {
    const isSelected =
      selectedPageId !== undefined &&
      String(selectedPageId) === String(item.id);
    const isHomepage = item.internalPath === homepagePath;

    return (
      <PageListItem
        key={`${item.id}-${item.status}`}
        item={item}
        isSelected={isSelected}
        isHomepage={isHomepage}
        homepagePath={homepagePath}
        onSelect={onSelect}
        onDuplicate={onDuplicate}
        onSetHomepage={onSetHomepage}
        onUnpublish={onUnpublish}
        onPublish={onPublish}
        onDelete={onDelete}
        folders={folders}
        onMoveToFolder={(page, folderId) =>
          setContentFolder({
            entityType: 'canvas_page',
            entityId: String(page.id),
            folder: folderId,
          })
        }
      />
    );
  };

  const pageFolders = sortFolderList(
    Object.fromEntries(
      Object.entries(folderData?.folders ?? {}).filter(
        ([, folder]) => folder.type === 'canvas_page',
      ),
    ) as Record<string, FolderInList>,
  );

  // Without folders the list stays flat: nothing changes for a site that has
  // not organized its pages.
  if (pageFolders.length === 0) {
    return (
      <Flex data-testid="canvas-page-list" direction="column" gap="1">
        {items.map((item) => renderItem(item, []))}
      </Flex>
    );
  }

  const folderIds = new Set(pageFolders.map((folder) => String(folder.id)));
  const uncategorized = items.filter(
    (item) => !item.folder || !folderIds.has(item.folder),
  );

  // Move up/down keeps folder re-ordering reachable by keyboard; dragging one
  // folder onto another does the same through the shared folder drop handler.
  const moveFolder = (folder: FolderInList, direction: -1 | 1) => {
    const order = pageFolders.map((candidate) => String(candidate.id));
    const from = order.indexOf(String(folder.id));
    const to = from + direction;
    if (from === -1 || to < 0 || to >= order.length) {
      return;
    }
    order.splice(to, 0, ...order.splice(from, 1));
    order.forEach((id, weight) => {
      const candidate = folderData?.folders[id];
      if (candidate && candidate.weight !== weight) {
        updateFolder({
          id,
          changes: { name: candidate.name, items: [], weight },
        });
      }
    });
  };

  return (
    <Flex data-testid="canvas-page-list" direction="column" gap="1">
      {pageFolders.map((folder, index) => {
        const pagesInFolder = items.filter(
          (item) => item.folder === String(folder.id),
        );
        return (
          <FolderList
            key={folder.id}
            folder={folder}
            itemCount={pagesInFolder.length}
            deleteWarning="Cannot delete folder containing pages"
            extraMenuItems={
              administerFolders ? (
                <>
                  <UnifiedMenu.Item
                    disabled={index === 0}
                    onClick={() => moveFolder(folder, -1)}
                  >
                    Move up
                  </UnifiedMenu.Item>
                  <UnifiedMenu.Item
                    disabled={index === pageFolders.length - 1}
                    onClick={() => moveFolder(folder, 1)}
                  >
                    Move down
                  </UnifiedMenu.Item>
                </>
              ) : undefined
            }
          >
            <Flex direction="column" gap="1">
              {pagesInFolder.map((item) => renderItem(item, pageFolders))}
            </Flex>
          </FolderList>
        );
      })}
      <UncategorizedDropZone
        itemType="canvas_page"
        hasItems={uncategorized.length > 0}
        origins={['pages']}
      >
        <Flex direction="column" gap="1">
          {uncategorized.map((item) => renderItem(item, pageFolders))}
        </Flex>
      </UncategorizedDropZone>
    </Flex>
  );
};

interface PageListProps {
  // Data
  pageItems?: ContentStub[];
  isPageItemsLoading?: boolean;
  pageItemsError?: string | null;
  homepagePath?: string;
  selectedPageId?: string | number;
  // Permissions
  canCreatePages?: boolean;
  // Pagination
  hasMore?: boolean;
  onLoadMore?: () => void;
  // Event handlers
  onNewPage?: () => void;
  onDeletePage?: (item: ContentStub) => void;
  onDuplicatePage?: (item: ContentStub) => void;
  onSelectPage?: (item: ContentStub) => void;
  onSetHomepage?: (item: ContentStub) => void;
  onUnpublishPage?: (item: ContentStub) => void;
  onPublishPage?: (item: ContentStub) => void;
  onSearch?: (value: string) => void;
}

const PageList = ({
  pageItems = [],
  isPageItemsLoading = false,
  pageItemsError = null,
  homepagePath,
  selectedPageId,
  canCreatePages = false,
  hasMore = false,
  onLoadMore,
  onNewPage,
  onDeletePage,
  onDuplicatePage,
  onSelectPage,
  onSetHomepage,
  onUnpublishPage,
  onPublishPage,
  onSearch,
}: PageListProps) => {
  const administerFolders = usePermissionCheck({ hasPermission: 'folders' });
  const [isAddingFolder, setIsAddingFolder] = useState(false);

  // Reset search when the component unmounts
  useEffect(() => {
    return () => {
      if (onSearch) {
        onSearch('');
      }
    };
  }, [onSearch]);

  return (
    <div data-testid="canvas-page-list-panel">
      <Flex direction="row" gap="2" mb="4">
        <form
          style={{ flexGrow: 1 }}
          onChange={(event: FormEvent<HTMLFormElement>) => {
            event.preventDefault();
            const form = event.currentTarget;
            const formElements = form.elements as typeof form.elements & {
              'canvas-navigation-search': HTMLInputElement;
            };
            onSearch?.(formElements['canvas-navigation-search'].value);
          }}
          onSubmit={(event: FormEvent<HTMLFormElement>) => {
            event.preventDefault();
          }}
        >
          <TextField.Root
            autoComplete="off"
            id="canvas-navigation-search"
            placeholder="Search…"
            radius="medium"
            aria-label="Search content"
            size="1"
          >
            <TextField.Slot>
              <MagnifyingGlassIcon height="16" width="16" />
            </TextField.Slot>
          </TextField.Root>
        </form>
        {(canCreatePages || administerFolders) && (
          <DropdownMenu.Root>
            <DropdownMenu.Trigger>
              <Button
                variant="soft"
                data-testid="canvas-page-list-new-button"
                size="1"
              >
                <PlusIcon />
                New
                <ChevronDownIcon />
              </Button>
            </DropdownMenu.Trigger>
            <DropdownMenu.Content>
              {canCreatePages && (
                <DropdownMenu.Item
                  onClick={onNewPage}
                  data-testid="canvas-page-list-new-page-button"
                >
                  <FileTextIcon />
                  New page
                </DropdownMenu.Item>
              )}
              {administerFolders && (
                <DropdownMenu.Item
                  onClick={() => setIsAddingFolder(true)}
                  data-testid="canvas-page-list-new-folder-button"
                >
                  <PlusIcon />
                  New folder
                </DropdownMenu.Item>
              )}
            </DropdownMenu.Content>
          </DropdownMenu.Root>
        )}
      </Flex>
      {isAddingFolder && (
        <FolderNameInput
          type="canvas_page"
          onSuccess={() => setIsAddingFolder(false)}
          onCancel={() => setIsAddingFolder(false)}
        />
      )}
      <Skeleton
        height="1.2rem"
        loading={isPageItemsLoading}
        width="100%"
        my="3"
      >
        <Box>
          {!pageItemsError && (
            <ContentGroup
              items={pageItems}
              homepagePath={homepagePath}
              selectedPageId={selectedPageId}
              onSelect={onSelectPage}
              onDuplicate={onDuplicatePage}
              onSetHomepage={onSetHomepage}
              onUnpublish={onUnpublishPage}
              onPublish={onPublishPage}
              onDelete={onDeletePage}
            />
          )}
          {pageItemsError && (
            <ErrorCard
              title="An unexpected error has occurred while loading pages."
              error={pageItemsError}
            />
          )}
          {hasMore && <InfiniteScrollObserver onLoadMore={onLoadMore} />}
        </Box>
      </Skeleton>
      <Skeleton
        loading={isPageItemsLoading}
        height="1.2rem"
        width="100%"
        my="3"
      />
      <Skeleton
        loading={isPageItemsLoading}
        height="1.2rem"
        width="100%"
        my="3"
      />
    </div>
  );
};

export default PageList;
