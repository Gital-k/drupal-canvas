import { useEffect, useState } from 'react';

import useDebounce from '@/hooks/useDebounce';
import { useGetContentListQuery } from '@/services/content';

export const usePaginatedContentList = (
  entityType: string,
  searchTerm: string,
) => {
  const [offset, setOffset] = useState(0);
  const [lastSuccessfulOffset, setLastSuccessfulOffset] = useState(0);
  const [paginationError, setPaginationError] = useState<unknown>(null);
  const debouncedSearchTerm = useDebounce(searchTerm, 300);

  const { data, isLoading, error, isSuccess, isFetching } = useGetContentListQuery({
    entityType,
    search: debouncedSearchTerm,
    offset,
  });
  const items = data?.items;
  const totalCount = data?.totalCount;

  // Reset offset when search changes.
  useEffect(() => {
    setOffset(0);
    setLastSuccessfulOffset(0);
    setPaginationError(null);
  }, [debouncedSearchTerm]);

  // Track successful fetches and pagination errors
  useEffect(() => {
    if (isSuccess && !isFetching) {
      setLastSuccessfulOffset(offset);
      setPaginationError(null);
    } else if (error && offset > 0) {
      // Only treat as pagination error if we're loading more (offset > 0)
      setPaginationError(error);
    }
  }, [isSuccess, error, offset, isFetching]);

  const hasMore =
    !debouncedSearchTerm &&
    totalCount !== null &&
    (items?.length ?? 0) < (totalCount ?? 0);

  function handleLoadMore() {
    setPaginationError(null);
    setOffset(items?.length ?? 0);
  }

  function handleRetryPagination() {
    setPaginationError(null);
    // Retry from the last successful offset
    setOffset(lastSuccessfulOffset);
    // Force a refetch by briefly resetting and then setting back
    setTimeout(() => {
      setOffset(items?.length ?? 0);
    }, 0);
  }

  // Only return error for initial load failures (offset === 0)
  const criticalError = offset === 0 ? error : null;

  return {
    items,
    totalCount,
    isLoading,
    error: criticalError,
    isSuccess,
    hasMore,
    handleLoadMore,
    debouncedSearchTerm,
    paginationError,
    handleRetryPagination,
    isLoadingMore: isFetching && offset > 0,
  };
};
