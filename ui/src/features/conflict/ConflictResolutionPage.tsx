import { useEffect, useMemo, useState } from 'react';
import { Navigate, useNavigate, useParams } from 'react-router-dom';
import { CheckCircledIcon, Cross2Icon } from '@radix-ui/react-icons';
import { Button, Flex, IconButton, Text } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import {
  findConflictIndex,
  getConflictQueue,
  getConflictRouteFromEntry,
  getNextConflictEntry,
} from '@/features/conflict/conflictComparison';
import { isConflictUxEnabled } from '@/features/conflict/conflictUtils';
import {
  componentAndLayoutApi,
  useGetConflictPageLayoutQuery,
} from '@/services/componentAndLayout';
import { contentApi, useUpdateContentMutation } from '@/services/content';
import {
  useDiscardPendingChangeMutation,
  useGetAllPendingChangesQuery,
} from '@/services/pendingChangesApi';

import {
  ConflictResolutionView,
  PageConflictComparisonView,
} from './ConflictResolutionView';

import type { ConflictVersionSelection } from './ConflictResolutionView';

import styles from './ConflictResolutionPage.module.css';

const EMPTY_HTML = '<main></main>';
const REVIEW_CHANGES_QUERY = 'reviewChanges=1';

const versionUpdatedDateFormatter = new Intl.DateTimeFormat('en-US', {
  month: 'numeric',
  day: 'numeric',
  year: '2-digit',
});

const versionUpdatedTimeFormatter = new Intl.DateTimeFormat('en-US', {
  hour: 'numeric',
  minute: '2-digit',
  hour12: true,
});

const formatConflictVersionUpdated = (
  timestamp: number | undefined,
): string | undefined => {
  if (typeof timestamp !== 'number' || !Number.isFinite(timestamp)) {
    return undefined;
  }

  const date = new Date(
    timestamp < 1_000_000_000_000 ? timestamp * 1000 : timestamp,
  );

  if (Number.isNaN(date.getTime())) {
    return undefined;
  }

  const formattedDate = versionUpdatedDateFormatter.format(date);
  const formattedTime = versionUpdatedTimeFormatter.format(date);

  return `Updated ${formattedDate} at ${formattedTime}`;
};

const ConflictResolutionPage = () =>
  isConflictUxEnabled() ? (
    <EnabledConflictResolutionPage />
  ) : (
    <Navigate to="/editor" replace />
  );

const EnabledConflictResolutionPage = () => {
  const { entityType, entityId } = useParams<{
    entityType?: string;
    entityId?: string;
  }>();
  const navigate = useNavigate();
  const dispatch = useAppDispatch();
  const { data: pendingChanges, refetch } =
    useGetAllPendingChangesQuery(undefined);
  const [resolveConflict, { isLoading: isResolving }] =
    useUpdateContentMutation();
  const [discardChange, { isLoading: isDiscarding }] =
    useDiscardPendingChangeMutation();
  const [resolutionFailed, setResolutionFailed] = useState(false);
  const [selectedVersion, setSelectedVersion] =
    useState<ConflictVersionSelection>();

  const conflictQueue = useMemo(
    () => getConflictQueue(pendingChanges),
    [pendingChanges],
  );
  const [reviewPointers, setReviewPointers] = useState<string[]>([]);

  useEffect(() => {
    if (conflictQueue.length === 0) {
      setReviewPointers([]);
      return;
    }
    setReviewPointers((existing) =>
      existing.length > 0
        ? existing
        : conflictQueue.map((entry) => entry.pointer),
    );
  }, [conflictQueue]);

  const currentIndex = findConflictIndex(conflictQueue, entityType, entityId);
  const currentEntry =
    currentIndex >= 0 ? conflictQueue[currentIndex] : undefined;
  const reviewIndex =
    currentEntry && reviewPointers.includes(currentEntry.pointer)
      ? reviewPointers.indexOf(currentEntry.pointer)
      : currentIndex;
  const reviewTotal = Math.max(reviewPointers.length, conflictQueue.length);
  const isResolutionInProgress = isResolving || isDiscarding;
  const pendingChangesCount = Object.keys(pendingChanges ?? {}).length;

  useEffect(() => {
    setSelectedVersion(undefined);
    setResolutionFailed(false);
  }, [currentEntry?.pointer]);

  if (!entityType || !entityId || !currentEntry) {
    return conflictQueue.length > 0 ? (
      <Navigate to={getConflictRouteFromEntry(conflictQueue[0])} replace />
    ) : (
      <ResolvedConflictState
        pendingChangesCount={pendingChangesCount}
        onReviewChanges={() => navigate(`/editor?${REVIEW_CHANGES_QUERY}`)}
        onClose={() => navigate('/editor')}
      />
    );
  }

  const activeChange = currentEntry.change;

  const finishResolution = async () => {
    dispatch(componentAndLayoutApi.util.invalidateTags([{ type: 'Layout' }]));
    dispatch(contentApi.util.invalidateTags([{ type: 'Content', id: 'LIST' }]));
    await refetch();
    const nextEntry = getNextConflictEntry(conflictQueue, currentEntry.pointer);
    navigate(nextEntry ? getConflictRouteFromEntry(nextEntry) : '/conflict');
  };

  const handleSelectVersion = (
    version: Exclude<ConflictVersionSelection, undefined>,
  ) => {
    setSelectedVersion((currentVersion) =>
      currentVersion === version ? undefined : version,
    );
  };

  const handleResolveConflict = async () => {
    setResolutionFailed(false);

    if (!selectedVersion) {
      setResolutionFailed(true);
      return;
    }

    try {
      if (selectedVersion === 'new') {
        const conflictId = activeChange.conflict_id;
        if (!conflictId) {
          setResolutionFailed(true);
          return;
        }
        await resolveConflict({
          entityType: 'canvas_page',
          entityId: String(activeChange.entity_id),
          conflictToResolve: conflictId,
        }).unwrap();
      } else {
        await discardChange({
          ...activeChange,
          pointer: currentEntry.pointer,
        }).unwrap();
      }
      await finishResolution();
    } catch {
      setResolutionFailed(true);
    }
  };

  const handleClose = () => {
    navigate(`/editor/${activeChange.entity_type}/${activeChange.entity_id}`);
  };

  return (
    <ConflictResolutionView
      label={activeChange.label}
      comparison={
        <>
          {resolutionFailed && (
            <Flex
              p="4"
              justify="center"
              data-testid="conflict-resolution-error"
            >
              <Text color="red">
                Unable to resolve this conflict. Please try again.
              </Text>
            </Flex>
          )}
          <PageConflictComparison
            entityId={String(activeChange.entity_id)}
            entityType={activeChange.entity_type}
            autoSaveUpdated={activeChange.updated}
            selectedVersion={selectedVersion}
            onSelectVersion={handleSelectVersion}
          />
        </>
      }
      reviewIndex={reviewIndex}
      reviewTotal={reviewTotal}
      currentIndex={currentIndex}
      unresolvedTotal={conflictQueue.length}
      onPrevious={() =>
        navigate(getConflictRouteFromEntry(conflictQueue[currentIndex - 1]))
      }
      onNext={() =>
        navigate(getConflictRouteFromEntry(conflictQueue[currentIndex + 1]))
      }
      onResolveConflict={handleResolveConflict}
      onClose={handleClose}
      isResolving={isResolutionInProgress}
      canResolveConflict={!!selectedVersion}
    />
  );
};

const PageConflictComparison = ({
  entityId,
  entityType,
  autoSaveUpdated,
  selectedVersion,
  onSelectVersion,
}: {
  entityId: string;
  entityType: string;
  autoSaveUpdated?: number;
  selectedVersion: ConflictVersionSelection;
  onSelectVersion: (
    version: Exclude<ConflictVersionSelection, undefined>,
  ) => void;
}) => {
  const {
    data: draftLayout,
    isFetching: isDraftLoading,
    isError: isDraftError,
  } = useGetConflictPageLayoutQuery({
    entityId,
    entityType: 'canvas_page',
  });
  const {
    data: publishedLayout,
    isFetching: isPublishedLoading,
    isError: isPublishedError,
  } = useGetConflictPageLayoutQuery({
    entityId,
    entityType: 'canvas_page',
    publishedVersion: true,
  });

  if (isDraftError || isPublishedError) {
    return (
      <Flex
        align="center"
        justify="center"
        height="100%"
        data-testid="conflict-preview-error"
      >
        <Text color="red">
          Unable to load both versions of this page for comparison.
        </Text>
      </Flex>
    );
  }

  return (
    <PageConflictComparisonView
      entityId={entityId}
      entityType={entityType}
      publishedVersion={{
        html: publishedLayout?.html || EMPTY_HTML,
        props: {
          entity_form_fields: publishedLayout?.entity_form_fields ?? {},
          layout: publishedLayout?.layout ?? [],
          model: publishedLayout?.model ?? {},
        },
        loading: isPublishedLoading,
        updated: formatConflictVersionUpdated(publishedLayout?.updated),
      }}
      newVersion={{
        html: draftLayout?.html || EMPTY_HTML,
        props: {
          entity_form_fields: draftLayout?.entity_form_fields ?? {},
          layout: draftLayout?.layout ?? [],
          model: draftLayout?.model ?? {},
        },
        loading: isDraftLoading,
        changed: true,
        updated: formatConflictVersionUpdated(
          draftLayout?.updated ?? autoSaveUpdated,
        ),
      }}
      selectedVersion={selectedVersion}
      onSelectVersion={onSelectVersion}
    />
  );
};

const getReviewChangesButtonText = (count: number): string => {
  if (count === 1) {
    return 'Review 1 change';
  }
  return `Review ${count} changes`;
};

const ResolvedConflictState = ({
  pendingChangesCount,
  onReviewChanges,
  onClose,
}: {
  pendingChangesCount: number;
  onReviewChanges: () => void;
  onClose: () => void;
}) => (
  <div className={styles.page} data-testid="conflict-resolved-state">
    <div className={styles.header}>
      <Flex align="center" gap="1">
        <Text size="1" color="blue">
          Canvas
        </Text>
        <Text size="1" color="gray">
          /
        </Text>
        <Text size="1">Conflict</Text>
        <Text size="1" color="gray">
          /
        </Text>
        <Text size="1">All files resolved</Text>
      </Flex>
      <IconButton
        variant="ghost"
        color="gray"
        highContrast
        aria-label="Close"
        onClick={onClose}
      >
        <Cross2Icon />
      </IconButton>
    </div>
    <Flex
      direction="column"
      align="center"
      justify="center"
      className={styles.emptyState}
      gap="3"
    >
      <CheckCircledIcon className={styles.emptyIcon} />
      <Text size="4" weight="medium">
        Everything is up to date
      </Text>
      <Text size="2">
        All conflicts have been resolved. Your changes are ready to be
        published.
      </Text>
      <Flex align="center" gap="2">
        <Button onClick={onReviewChanges} disabled={pendingChangesCount === 0}>
          {getReviewChangesButtonText(pendingChangesCount)}
        </Button>
        <Button variant="outline" onClick={onClose}>
          Close
        </Button>
      </Flex>
    </Flex>
  </div>
);

export default ConflictResolutionPage;
