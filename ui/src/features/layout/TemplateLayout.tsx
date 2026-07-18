import { useEffect } from 'react';
import { useErrorBoundary } from 'react-error-boundary';
import { useParams } from 'react-router';
import { skipToken } from '@reduxjs/toolkit/query';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { useGetTemplateLayoutQuery } from '@/services/componentAndLayout';

import {
  selectInitializedForEntity,
  selectIsInitialized,
  setInitialized,
  setInitialLayoutModel,
} from './layoutModelSlice';

const TemplateLayout = () => {
  const dispatch = useAppDispatch();
  const { entityType, bundle, viewMode, previewEntityId } = useParams();

  const isInitialized = useAppSelector(selectIsInitialized);
  const initializedForEntity = useAppSelector(selectInitializedForEntity);
  const entityKey = `template:${entityType}:${bundle}:${viewMode}:${previewEntityId}`;
  const {
    data: fetchedLayout,
    error,
    isError,
    isFetching,
    refetch,
  } = useGetTemplateLayoutQuery(
    entityType && bundle && viewMode && previewEntityId
      ? { entityType, bundle, viewMode, previewEntityId }
      : skipToken,
    // Setting `refetchOnMountOrArgChange` instead of a cache invalidation
    // prevents re-fetching due to the same query being used elsewhere in the app.
    { refetchOnMountOrArgChange: true },
  );

  const { showBoundary, resetBoundary } = useErrorBoundary();

  const { layout, model, translations } = fetchedLayout || {};

  useEffect(() => {
    // A remount for the template that is already loaded (e.g. panel or route
    // churn) must not reset editor state: that would wipe the undo history.
    if (initializedForEntity === entityKey) {
      return;
    }
    dispatch(setInitialized(false));
    if (entityType && bundle && viewMode && previewEntityId) {
      refetch();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [entityType, bundle, viewMode, previewEntityId, refetch, dispatch]);

  useEffect(() => {
    if (isError && error && !isFetching) {
      showBoundary(error);
      return;
    }
    // Reset the boundary so this component is re-rendered. Without this, the
    // error boundary will re-render while the layout is (re)fetching and as a
    // result it will require two clicks of the reset button in the alert to
    // allow the page to render.
    resetBoundary();

    if (layout && model && !isInitialized && !isFetching) {
      dispatch(
        setInitialLayoutModel({
          layout,
          model,
          translations: translations || {},
          initializedForEntity: entityKey,
          // We don't need to update the preview here - it is done in the layout
          // api's onQueryStarted method - @see componentAndLayout.ts
          updatePreview: false,
        }),
      );
    }
  }, [
    layout,
    model,
    translations,
    isInitialized,
    error,
    showBoundary,
    dispatch,
    resetBoundary,
    isError,
    isFetching,
  ]);

  return null;
};

export default TemplateLayout;
