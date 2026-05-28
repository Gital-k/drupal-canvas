import { useMemo, useRef, useState } from 'react';
import ScaleToFitIcon from '@assets/icons/justify-stretch.svg?react';
import {
  ArrowLeftIcon,
  ArrowRightIcon,
  Cross2Icon,
  ExternalLinkIcon,
} from '@radix-ui/react-icons';
import {
  Badge,
  Box,
  Button,
  Flex,
  IconButton,
  Spinner,
  Tabs,
  Text,
  Tooltip,
} from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ZoomControl from '@/components/zoom/ZoomControl';
import {
  buildContentOnlyPreviewDocument,
  toPlainText,
} from '@/features/conflict/conflictComparison';
import ViewportSelector from '@/features/layout/preview/ViewportSelector';
import {
  scaleValues,
  selectEditorViewPortScale,
  selectViewportMinHeight,
  selectViewportWidth,
  setEditorFrameViewPort,
} from '@/features/ui/uiSlice';

import type React from 'react';

import styles from './ConflictResolutionPage.module.css';

const EMPTY_HTML = '<main></main>';
const PREVIEW_FIT_PADDING = 96;

type ConflictVersion = 'published' | 'new';

export type ConflictVersionSelection = ConflictVersion | undefined;

export interface ConflictResolutionViewProps {
  label: string;
  comparison: React.ReactNode;
  currentIndex: number;
  reviewIndex: number;
  reviewTotal: number;
  unresolvedTotal: number;
  isResolving?: boolean;
  canResolveConflict?: boolean;
  onPrevious: () => void;
  onNext: () => void;
  onResolveConflict: () => void;
  onClose: () => void;
}

export const ConflictResolutionView = ({
  label,
  comparison,
  currentIndex,
  reviewIndex,
  reviewTotal,
  unresolvedTotal,
  isResolving = false,
  canResolveConflict = false,
  onPrevious,
  onNext,
  onResolveConflict,
  onClose,
}: ConflictResolutionViewProps) => {
  return (
    <div className={styles.page} data-testid="conflict-resolution-page">
      <ConflictHeader label={label} onClose={onClose} />
      <div className={styles.content}>{comparison}</div>
      <ConflictFooter
        reviewIndex={reviewIndex}
        reviewTotal={reviewTotal}
        currentIndex={currentIndex}
        unresolvedTotal={unresolvedTotal}
        onPrevious={onPrevious}
        onNext={onNext}
        onResolveConflict={onResolveConflict}
        isResolving={isResolving}
        canResolveConflict={canResolveConflict}
      />
    </div>
  );
};

interface ConflictHeaderProps {
  label: string;
  onClose: () => void;
}

const ConflictHeader = ({ label, onClose }: ConflictHeaderProps) => {
  return (
    <div className={styles.header}>
      <Flex align="center" gap="1" minWidth="0">
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
        <Text size="1" className={styles.breadcrumbLabel}>
          {label}
        </Text>
        <Badge color="gray" variant="soft" radius="small">
          Page
        </Badge>
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
  );
};

export interface PageConflictVersion {
  html: string;
  props?: unknown;
  text?: string;
  updated?: string;
  loading?: boolean;
  changed?: boolean;
}

export interface PageConflictComparisonViewProps {
  entityId: string;
  entityType: string;
  publishedVersion: PageConflictVersion;
  newVersion: PageConflictVersion;
  selectedVersion?: ConflictVersionSelection;
  onSelectVersion?: (version: ConflictVersion) => void;
  onPublishedPreviewClick?: () => void;
  onNewPreviewClick?: () => void;
}

const stringifyComparisonProps = (value: unknown): string =>
  typeof value === 'string' ? value : JSON.stringify(value ?? {}, null, 2);

const buildConflictPreviewUrl = (
  entityType: string,
  entityId: string,
  published: boolean,
): string => {
  // Derive the SPA base path (e.g. '/canvas') from the current URL by
  // stripping everything from '/conflict/' onward.
  const basePath = window.location.pathname.split('/conflict/')[0];
  const versionParam = published ? '?version=published' : '';
  return `${window.location.origin}${basePath}/conflict-preview/${entityType}/${entityId}/full${versionParam}`;
};

export const PageConflictComparisonView = ({
  entityId,
  entityType,
  publishedVersion,
  newVersion,
  selectedVersion,
  onSelectVersion,
  onPublishedPreviewClick,
  onNewPreviewClick,
}: PageConflictComparisonViewProps) => {
  const handlePublishedPreview = onPublishedPreviewClick
    ?? (() => window.open(buildConflictPreviewUrl(entityType, entityId, true), '_blank'));
  const handleNewPreview = onNewPreviewClick
    ?? (() => window.open(buildConflictPreviewUrl(entityType, entityId, false), '_blank'));
  const [tab, setTab] = useState('visual');
  const publishedContentHtml = useMemo(
    () => buildContentOnlyPreviewDocument(publishedVersion.html || EMPTY_HTML),
    [publishedVersion.html],
  );
  const newContentHtml = useMemo(
    () => buildContentOnlyPreviewDocument(newVersion.html || EMPTY_HTML),
    [newVersion.html],
  );
  const publishedText = useMemo(
    () => publishedVersion.text ?? toPlainText(publishedVersion.html),
    [publishedVersion.html, publishedVersion.text],
  );
  const newText = useMemo(
    () => newVersion.text ?? toPlainText(newVersion.html),
    [newVersion.html, newVersion.text],
  );
  const publishedProps = useMemo(
    () => stringifyComparisonProps(publishedVersion.props),
    [publishedVersion.props],
  );
  const newProps = useMemo(
    () => stringifyComparisonProps(newVersion.props),
    [newVersion.props],
  );

  return (
    <Tabs.Root value={tab} onValueChange={setTab} className={styles.tabRoot}>
      <Flex justify="between" align="center" className={styles.toolbar}>
        <Tabs.List size="1" className={styles.tabList}>
          <Tabs.Trigger className={styles.tabTrigger} value="visual">
            Visual
          </Tabs.Trigger>
          <Tabs.Trigger className={styles.tabTrigger} value="text">
            Text
          </Tabs.Trigger>
          <Tabs.Trigger className={styles.tabTrigger} value="props">
            Props
          </Tabs.Trigger>
        </Tabs.List>
        <ViewportSelector />
      </Flex>
      <Tabs.Content value="visual" className={styles.tabContent}>
        <ComparisonGrid>
          <PreviewCard
            title="Published version"
            updated={publishedVersion.updated}
            version="published"
            selected={selectedVersion === 'published'}
            onSelect={onSelectVersion}
            onPreviewClick={handlePublishedPreview}
          >
            <VisualFrame
              html={publishedContentHtml}
              loading={!!publishedVersion.loading}
            />
          </PreviewCard>
          <PreviewCard
            title="New version"
            updated={newVersion.updated}
            changed={!!newVersion.changed}
            version="new"
            selected={selectedVersion === 'new'}
            onSelect={onSelectVersion}
            onPreviewClick={handleNewPreview}
          >
            <VisualFrame html={newContentHtml} loading={!!newVersion.loading} />
          </PreviewCard>
        </ComparisonGrid>
      </Tabs.Content>
      <Tabs.Content value="text" className={styles.tabContent}>
        <ComparisonGrid>
          <PreviewCard
            title="Published version"
            updated={publishedVersion.updated}
            version="published"
            selected={selectedVersion === 'published'}
            onSelect={onSelectVersion}
            onPreviewClick={handlePublishedPreview}
          >
            <TextPanel value={publishedText} />
          </PreviewCard>
          <PreviewCard
            title="New version"
            updated={newVersion.updated}
            changed={!!newVersion.changed}
            version="new"
            selected={selectedVersion === 'new'}
            onSelect={onSelectVersion}
            onPreviewClick={handleNewPreview}
          >
            <TextPanel value={newText} />
          </PreviewCard>
        </ComparisonGrid>
      </Tabs.Content>
      <Tabs.Content value="props" className={styles.tabContent}>
        <ComparisonGrid>
          <PreviewCard
            title="Published version"
            updated={publishedVersion.updated}
            version="published"
            selected={selectedVersion === 'published'}
            onSelect={onSelectVersion}
            onPreviewClick={handlePublishedPreview}
          >
            <JsonPanel value={publishedProps} />
          </PreviewCard>
          <PreviewCard
            title="New version"
            updated={newVersion.updated}
            changed={!!newVersion.changed}
            version="new"
            selected={selectedVersion === 'new'}
            onSelect={onSelectVersion}
            onPreviewClick={handleNewPreview}
          >
            <JsonPanel value={newProps} />
          </PreviewCard>
        </ComparisonGrid>
      </Tabs.Content>
    </Tabs.Root>
  );
};

const ComparisonGrid = ({ children }: { children: React.ReactNode }) => (
  <div className={styles.comparisonGrid}>{children}</div>
);

const findClosestPreviewScaleValue = (desiredScale: number) => {
  const filteredScales = scaleValues.filter(
    (value) => value.scale <= desiredScale - 0.01,
  );

  if (filteredScales.length > 0) {
    return filteredScales.reduce((previous, current) =>
      current.scale > previous.scale ? current : previous,
    );
  }

  return scaleValues[0];
};

interface PreviewCardProps {
  title: string;
  updated?: string;
  children: React.ReactNode;
  version: ConflictVersion;
  selected?: boolean;
  changed?: boolean;
  onSelect?: (version: ConflictVersion) => void;
  onPreviewClick?: () => void;
}

const PreviewCard = ({
  title,
  updated,
  children,
  version,
  selected = false,
  changed = false,
  onSelect,
  onPreviewClick,
}: PreviewCardProps) => {
  const cardClassName = [
    styles.card,
    onSelect ? styles.cardSelectable : undefined,
    selected ? styles.cardSelected : undefined,
  ]
    .filter(Boolean)
    .join(' ');
  const headerClassName = [
    styles.cardHeader,
    selected ? styles.cardHeaderSelected : undefined,
  ]
    .filter(Boolean)
    .join(' ');

  const handleSelect = () => {
    onSelect?.(version);
  };

  const handleSelectButtonClick = (
    event: React.MouseEvent<HTMLButtonElement>,
  ) => {
    event.stopPropagation();
    handleSelect();
  };

  const handlePreviewButtonClick = (
    event: React.MouseEvent<HTMLButtonElement>,
  ) => {
    event.stopPropagation();
    onPreviewClick?.();
  };

  return (
    <section
      className={cardClassName}
      data-testid={`conflict-${version}-version-card`}
      onClick={onSelect ? handleSelect : undefined}
    >
      <div className={headerClassName}>
        <Box>
          <Text as="p" size="2" weight="medium">
            {title}
          </Text>
          {updated && (
            <Text as="p" size="1" color="gray">
              {updated}
            </Text>
          )}
        </Box>
        <Flex align="center" gap="3" className={styles.cardHeaderActions}>
          <Tooltip content="Open preview in new tab">
            <IconButton
              variant="ghost"
              color="gray"
              size="1"
              aria-label="Open preview in new tab"
              onClick={handlePreviewButtonClick}
              style={{cursor:'pointer'}}
            >
              <ExternalLinkIcon />
            </IconButton>
          </Tooltip>
          {changed && (
            <Badge color="yellow" radius="small">
              Changed
            </Badge>
          )}
        </Flex>
        {onSelect && (
          <button
            type="button"
            aria-pressed={selected}
            aria-label={`Select ${title}`}
            className={styles.cardSelectButton}
            onClick={handleSelectButtonClick}
          />
        )}
      </div>
      <div className={styles.cardBody}>{children}</div>
    </section>
  );
};

const VisualFrame = ({ html, loading }: { html: string; loading: boolean }) => {
  const previewScale = useAppSelector(selectEditorViewPortScale);
  const previewWidth = useAppSelector(selectViewportWidth);
  const previewMinHeight = useAppSelector(selectViewportMinHeight);
  const previewScrollAreaRef = useRef<HTMLDivElement>(null);
  const previewFrameWrapperRef = useRef<HTMLDivElement>(null);

  return (
    <div className={styles.visualSurface}>
      <div
        className={styles.previewScrollArea}
        ref={previewScrollAreaRef}
        data-testid="canvas-conflict-preview-scroll-area"
      >
        {loading ? (
          <Spinner />
        ) : (
          <div
            ref={previewFrameWrapperRef}
            className={styles.previewFrameWrapper}
            style={
              {
                '--conflict-preview-scale': previewScale,
                ...(previewWidth ? { width: `${previewWidth}px` } : {}),
                ...(previewMinHeight
                  ? {
                      height: `${previewMinHeight}px`,
                      minHeight: `${previewMinHeight}px`,
                    }
                  : {}),
              } as React.CSSProperties
            }
          >
            <iframe
              title="Conflict preview"
              className={styles.previewFrame}
              srcDoc={html}
            />
          </div>
        )}
      </div>
      <ConflictPreviewControls
        previewScrollAreaRef={previewScrollAreaRef}
        previewFrameWrapperRef={previewFrameWrapperRef}
      />
    </div>
  );
};

const TextPanel = ({ value }: { value: string }) => (
  <pre className={styles.textPanel}>{value || 'No text content.'}</pre>
);

const JsonPanel = ({ value }: { value: string }) => (
  <pre className={styles.textPanel}>{value}</pre>
);

interface ConflictPreviewControlsProps {
  previewScrollAreaRef: React.RefObject<HTMLDivElement>;
  previewFrameWrapperRef: React.RefObject<HTMLDivElement>;
}

const ConflictPreviewControls = ({
  previewScrollAreaRef,
  previewFrameWrapperRef,
}: ConflictPreviewControlsProps) => {
  const dispatch = useAppDispatch();

  const handleScaleToFit = () => {
    const previewScrollArea = previewScrollAreaRef.current;
    const previewFrameWrapper = previewFrameWrapperRef.current;

    if (!previewScrollArea || !previewFrameWrapper) {
      dispatch(setEditorFrameViewPort({ scale: 1 }));
      return;
    }

    const availableWidth = Math.max(
      previewScrollArea.clientWidth - PREVIEW_FIT_PADDING,
      0,
    );
    const availableHeight = Math.max(
      previewScrollArea.clientHeight - PREVIEW_FIT_PADDING,
      0,
    );
    const frameWidth = previewFrameWrapper.offsetWidth;
    const frameHeight = previewFrameWrapper.offsetHeight;

    if (!availableWidth || !availableHeight || !frameWidth || !frameHeight) {
      dispatch(setEditorFrameViewPort({ scale: 1 }));
      return;
    }

    const scaleToFit = Math.min(
      availableWidth / frameWidth,
      availableHeight / frameHeight,
    );
    const closestScale = findClosestPreviewScaleValue(scaleToFit);

    dispatch(
      setEditorFrameViewPort({
        scale: closestScale.scale < 1 ? closestScale.scale : 1,
      }),
    );
  };

  return (
    <Flex
      align="center"
      gap="2"
      className={styles.previewControls}
      data-testid="canvas-conflict-preview-controls"
      onClick={(event) => event.stopPropagation()}
      onKeyDown={(event) => event.stopPropagation()}
    >
      <Tooltip side="bottom" content="Scale to fit">
        <Button
          size="1"
          onClick={handleScaleToFit}
          color="gray"
          variant="surface"
          highContrast
          className={styles.previewControlButton}
          aria-label="Scale to fit"
          data-testid="conflict-scale-to-fit"
        >
          <ScaleToFitIcon />
        </Button>
      </Tooltip>
      <ZoomControl buttonClass={styles.previewControlButton} />
    </Flex>
  );
};

interface ConflictFooterProps {
  currentIndex: number;
  reviewIndex: number;
  reviewTotal: number;
  unresolvedTotal: number;
  isResolving: boolean;
  canResolveConflict: boolean;
  onPrevious: () => void;
  onNext: () => void;
  onResolveConflict: () => void;
}

const ConflictFooter = ({
  currentIndex,
  reviewIndex,
  reviewTotal,
  unresolvedTotal,
  isResolving,
  canResolveConflict,
  onPrevious,
  onNext,
  onResolveConflict,
}: ConflictFooterProps) => (
  <div className={styles.footer}>
    <Text size="2" color="gray">
      Review {reviewIndex + 1} of {reviewTotal}
    </Text>
    <Flex align="center" gap="5">
      <Button
        variant="ghost"
        color="gray"
        disabled={currentIndex === 0 || isResolving}
        onClick={onPrevious}
      >
        <ArrowLeftIcon />
        Previous
      </Button>
      <Button
        variant="ghost"
        color="blue"
        disabled={currentIndex >= unresolvedTotal - 1 || isResolving}
        onClick={onNext}
      >
        Next
        <ArrowRightIcon />
      </Button>
      <Button
        onClick={onResolveConflict}
        disabled={isResolving || !canResolveConflict}
      >
        Resolve conflict
        <Spinner loading={isResolving} />
      </Button>
    </Flex>
  </div>
);
