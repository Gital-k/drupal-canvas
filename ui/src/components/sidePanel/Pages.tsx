import { useEffect, useState } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useParams } from 'react-router-dom';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import PageList from '@/components/pageInfo/PageList';
import {
  extractHomepagePathFromStagedConfig,
  selectHomepagePath,
  selectHomepageStagedConfigExists,
  setHomepagePath,
} from '@/features/configuration/configurationSlice';
import useDebounce from '@/hooks/useDebounce';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { useSmartRedirect } from '@/hooks/useSmartRedirect';
import { componentAndLayoutApi } from '@/services/componentAndLayout';
import {
  useCreateContentMutation,
  useDeleteContentMutation,
  useGetContentListQuery,
  useGetStagedConfigQuery,
  useSetStagedConfigMutation,
  useUpdateContentMutation,
} from '@/services/content';
import { getCanvasSettings } from '@/utils/drupal-globals';

import type { ContentStub } from '@/types/Content';

const canvasSettings = getCanvasSettings();
export const HOMEPAGE_CONFIG_ID = 'canvas_set_homepage';

const Pages = () => {
  const { showBoundary } = useErrorBoundary();
  const { setEditorEntity } = useEditorNavigation();
  const { redirectToNextBestPage } = useSmartRedirect();
  const dispatch = useAppDispatch();
  const { entityType, entityId } = useParams();
  const [searchTerm, setSearchTerm] = useState<string>('');
  const debouncedSearchTerm = useDebounce(searchTerm, 300);

  const canCreatePages =
    !!canvasSettings.contentEntityCreateOperations?.canvas_page?.canvas_page;

  const {
    data: pageItems,
    isLoading: isPageItemsLoading,
    error: pageItemsError,
  } = useGetContentListQuery({
    entityType: 'canvas_page',
    search: debouncedSearchTerm,
  });

  const [
    createContent,
    {
      data: createContentData,
      error: createContentError,
      isSuccess: isCreateContentSuccess,
    },
  ] = useCreateContentMutation();
  const homepagePath = useAppSelector(selectHomepagePath);
  const homepageStagedConfigExists = useAppSelector(
    selectHomepageStagedConfigExists,
  );
  const { data: homepageConfig, isSuccess: isGetStagedUpdateSuccess } =
    useGetStagedConfigQuery(HOMEPAGE_CONFIG_ID, {
      // Only fetch the homepage staged config if it exists to avoid
      // unnecessary API calls that return 404s.
      skip: !homepageStagedConfigExists,
    });

  const [deleteContent, { error: deleteContentError }] =
    useDeleteContentMutation();
  const [updateContent, { error: updateContentError }] =
    useUpdateContentMutation();
  const [setHomepage, { error: setHomepageError }] =
    useSetStagedConfigMutation();

  function handleNewPage() {
    createContent({
      entity_type: 'canvas_page',
    });
  }

  async function handleDeletePage(item: ContentStub) {
    const pageToDeleteId = String(item.id);
    await deleteContent({
      entityType: 'canvas_page',
      entityId: pageToDeleteId,
    });

    // If the current page is the one being deleted, redirect using smart logic
    if (entityType === 'canvas_page' && entityId === pageToDeleteId) {
      redirectToNextBestPage(pageToDeleteId);
    }

    // Keep local storage tidy and clear out the array of collapsed layers for the deleted item.
    window.localStorage.removeItem(
      `Canvas.collapsedLayers.canvas_page.${pageToDeleteId}`,
    );
  }

  function handleDuplication(item: ContentStub) {
    createContent({
      entity_type: 'canvas_page',
      entity_id: String(item.id),
    });
  }

  function handleOnSelect(item: ContentStub) {
    setEditorEntity('canvas_page', String(item.id));
  }

  async function handleArchivePage(item: ContentStub) {
    const pageToArchiveId = String(item.id);
    await updateContent({
      entityType: 'canvas_page',
      entityId: pageToArchiveId,
      status: false,
    });

    // If the current page is being archived, update the layout cache immediately
    if (entityType === 'canvas_page' && entityId === pageToArchiveId) {
      dispatch(
        componentAndLayoutApi.util.updateQueryData(
          'getPageLayout',
          undefined,
          (draft) => {
            draft.isPublished = false;
          },
        ),
      );
      // Invalidate the layout query cache to ensure it refetches the latest data
      dispatch(componentAndLayoutApi.util.invalidateTags([{ type: 'Layout' }]));
    }
  }

  async function handlePublishPage(item: ContentStub) {
    const pageToPublishId = String(item.id);
    await updateContent({
      entityType: 'canvas_page',
      entityId: pageToPublishId,
      status: true,
    });

    // If the current page is being published, update the layout cache immediately
    if (entityType === 'canvas_page' && entityId === pageToPublishId) {
      dispatch(
        componentAndLayoutApi.util.updateQueryData(
          'getPageLayout',
          undefined,
          (draft) => {
            draft.isPublished = true;
          },
        ),
      );
      // Invalidate the layout query cache to ensure it refetches the latest data
      dispatch(componentAndLayoutApi.util.invalidateTags([{ type: 'Layout' }]));
    }
  }

  function handleSetHomepage(item: ContentStub) {
    const { internalPath } = item;
    dispatch(setHomepagePath(internalPath));
    setHomepage({
      data: {
        id: HOMEPAGE_CONFIG_ID,
        label: 'Update homepage',
        target: 'system.site',
        actions: [
          {
            name: 'simpleConfigUpdate',
            input: {
              'page.front': internalPath,
            },
          },
        ],
      },
      autoSaves: '',
    });
  }

  useEffect(() => {
    if (isGetStagedUpdateSuccess) {
      dispatch(
        setHomepagePath(extractHomepagePathFromStagedConfig(homepageConfig)),
      );
    }
  }, [dispatch, homepageConfig, isGetStagedUpdateSuccess]);

  useEffect(() => {
    if (isCreateContentSuccess) {
      setEditorEntity(
        createContentData.entity_type,
        createContentData.entity_id,
      );
    }
  }, [isCreateContentSuccess, createContentData, setEditorEntity]);

  useEffect(() => {
    if (createContentError) {
      showBoundary(createContentError);
    }
  }, [createContentError, showBoundary]);

  useEffect(() => {
    if (deleteContentError) {
      showBoundary(deleteContentError);
    }
  }, [deleteContentError, showBoundary]);

  useEffect(() => {
    if (setHomepageError) {
      showBoundary(setHomepageError);
    }
  }, [setHomepageError, showBoundary]);

  // Determine the currently selected page
  const selectedPageId = entityType === 'canvas_page' ? entityId : undefined;

  return (
    <PageList
      pageItems={pageItems || []}
      isPageItemsLoading={isPageItemsLoading}
      pageItemsError={pageItemsError ? String(pageItemsError) : null}
      homepagePath={homepagePath}
      selectedPageId={selectedPageId}
      canCreatePages={canCreatePages}
      onNewPage={handleNewPage}
      onDeletePage={handleDeletePage}
      onDuplicatePage={handleDuplication}
      onSelectPage={handleOnSelect}
      onSetHomepage={handleSetHomepage}
      onArchivePage={handleArchivePage}
      onPublishPage={handlePublishPage}
      onSearch={setSearchTerm}
    />
  );
};

export default Pages;
