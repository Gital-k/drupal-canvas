import { useCallback, useMemo, useRef, useState } from 'react';
import ReactDiffViewer, { DiffMethod } from 'react-diff-viewer-continued';
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
import type { ReactDiffViewerStylesOverride } from 'react-diff-viewer-continued';

import styles from './ConflictResolutionPage.module.css';

const EMPTY_HTML = '<main></main>';
const PREVIEW_FIT_PADDING = 96;
const PREVIEW_DRAG_THRESHOLD = 3;
const YAML_DIFF_VIEWER_STYLES: ReactDiffViewerStylesOverride = {
  variables: {
    dark: {
      diffViewerBackground: '#0f172a',
      diffViewerColor: '#d8e1ec',
      addedBackground: 'rgba(20, 184, 126, 0.2)',
      addedColor: '#d8f7e8',
      removedBackground: 'rgba(239, 68, 92, 0.22)',
      removedColor: '#fee2e2',
      changedBackground: 'rgba(59, 130, 246, 0.16)',
      wordAddedBackground: 'rgba(20, 184, 126, 0.38)',
      wordRemovedBackground: 'rgba(239, 68, 92, 0.38)',
      addedGutterBackground: 'rgba(20, 184, 126, 0.18)',
      removedGutterBackground: 'rgba(239, 68, 92, 0.2)',
      gutterBackground: '#0b1220',
      gutterBackgroundDark: '#111827',
      gutterColor: '#64748b',
      addedGutterColor: '#9ee6c5',
      removedGutterColor: '#f8b4b4',
      codeFoldBackground: '#111827',
      codeFoldGutterBackground: '#0b1220',
      codeFoldContentColor: '#93a4b8',
      emptyLineBackground: '#0f172a',
    },
  },
  diffContainer: {
    minWidth: 520,
    width: '100%',
    height: '100%',
    borderCollapse: 'separate',
    borderSpacing: 0,
    tableLayout: 'fixed',
    fontFamily: 'var(--font-mono)',
    fontSize: 12,
  },
  line: {
    verticalAlign: 'top',
  },
  gutter: {
    minWidth: 44,
    padding: '0 12px',
    borderRight: '1px solid rgba(148, 163, 184, 0.14)',
    lineHeight: '21px',
  },
  marker: {
    width: 26,
    paddingLeft: 8,
    paddingRight: 8,
    lineHeight: '21px',
  },
  contentText: {
    fontFamily: 'var(--font-mono)',
    fontSize: 12,
    lineHeight: '21px',
    whiteSpace: 'pre',
  },
  lineContent: {
    paddingLeft: 12,
    paddingRight: 16,
  },
  content: {
    width: '50%',
  },
  wordDiff: {
    borderRadius: 2,
    padding: '0 1px',
  },
  codeFold: {
    fontSize: 12,
  },
};

type ConflictVersion = 'published' | 'new';

export type ConflictVersionSelection = ConflictVersion | undefined;

type ConflictScrollElement = HTMLElement;

type ConflictScrollAreas = Record<
  ConflictVersion,
  ConflictScrollElement | null
>;

type ScrollPosition = {
  scrollLeft: number;
  scrollTop: number;
};

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
  textLoading?: boolean;
  textError?: boolean;
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

const syncComparisonScroll = (
  sourceVersion: ConflictVersion,
  sourceScrollArea: ConflictScrollElement,
  scrollAreas: ConflictScrollAreas,
  ignoredScrollPositions: Map<ConflictScrollElement, ScrollPosition>,
) => {
  const ignoredScrollPosition = ignoredScrollPositions.get(sourceScrollArea);
  if (ignoredScrollPosition) {
    ignoredScrollPositions.delete(sourceScrollArea);
    if (
      Math.abs(sourceScrollArea.scrollLeft - ignoredScrollPosition.scrollLeft) <
        0.5 &&
      Math.abs(sourceScrollArea.scrollTop - ignoredScrollPosition.scrollTop) <
        0.5
    ) {
      return;
    }
  }

  const targetVersion: ConflictVersion =
    sourceVersion === 'published' ? 'new' : 'published';
  const targetScrollArea = scrollAreas[targetVersion];
  if (!targetScrollArea) {
    return;
  }

  const sourceMaxLeft =
    sourceScrollArea.scrollWidth - sourceScrollArea.clientWidth;
  const sourceMaxTop =
    sourceScrollArea.scrollHeight - sourceScrollArea.clientHeight;
  const targetMaxLeft =
    targetScrollArea.scrollWidth - targetScrollArea.clientWidth;
  const targetMaxTop =
    targetScrollArea.scrollHeight - targetScrollArea.clientHeight;

  const nextLeft =
    sourceMaxLeft > 0 && targetMaxLeft > 0
      ? (sourceScrollArea.scrollLeft / sourceMaxLeft) * targetMaxLeft
      : sourceScrollArea.scrollLeft;
  const nextTop =
    sourceMaxTop > 0 && targetMaxTop > 0
      ? (sourceScrollArea.scrollTop / sourceMaxTop) * targetMaxTop
      : sourceScrollArea.scrollTop;

  if (
    Math.abs(targetScrollArea.scrollLeft - nextLeft) < 0.5 &&
    Math.abs(targetScrollArea.scrollTop - nextTop) < 0.5
  ) {
    return;
  }

  ignoredScrollPositions.set(targetScrollArea, {
    scrollLeft: nextLeft,
    scrollTop: nextTop,
  });
  targetScrollArea.scrollLeft = nextLeft;
  targetScrollArea.scrollTop = nextTop;
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
  const handlePublishedPreview =
    onPublishedPreviewClick ??
    (() =>
      window.open(
        buildConflictPreviewUrl(entityType, entityId, true),
        '_blank',
      ));
  const handleNewPreview =
    onNewPreviewClick ??
    (() =>
      window.open(
        buildConflictPreviewUrl(entityType, entityId, false),
        '_blank',
      ));
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
  const isTextLoading =
    !!publishedVersion.textLoading || !!newVersion.textLoading;
  const isTextError = !!publishedVersion.textError || !!newVersion.textError;
  const visualScrollAreasRef = useRef<ConflictScrollAreas>({
    published: null,
    new: null,
  });
  const textScrollAreasRef = useRef<ConflictScrollAreas>({
    published: null,
    new: null,
  });
  const propsScrollAreasRef = useRef<ConflictScrollAreas>({
    published: null,
    new: null,
  });
  const ignoredScrollPositionsRef = useRef(
    new Map<ConflictScrollElement, ScrollPosition>(),
  );

  const handleVisualScrollAreaMount = useCallback(
    (version: ConflictVersion, node: ConflictScrollElement | null) => {
      visualScrollAreasRef.current[version] = node;
    },
    [],
  );

  const handleVisualScroll = useCallback(
    (
      sourceVersion: ConflictVersion,
      sourceScrollArea: ConflictScrollElement,
    ) => {
      syncComparisonScroll(
        sourceVersion,
        sourceScrollArea,
        visualScrollAreasRef.current,
        ignoredScrollPositionsRef.current,
      );
    },
    [],
  );

  const handleTextScrollAreaMount = useCallback(
    (version: ConflictVersion, node: ConflictScrollElement | null) => {
      textScrollAreasRef.current[version] = node;
    },
    [],
  );

  const handleTextScroll = useCallback(
    (
      sourceVersion: ConflictVersion,
      sourceScrollArea: ConflictScrollElement,
    ) => {
      syncComparisonScroll(
        sourceVersion,
        sourceScrollArea,
        textScrollAreasRef.current,
        ignoredScrollPositionsRef.current,
      );
    },
    [],
  );

  const handlePropsScrollAreaMount = useCallback(
    (version: ConflictVersion, node: ConflictScrollElement | null) => {
      propsScrollAreasRef.current[version] = node;
    },
    [],
  );

  const handlePropsScroll = useCallback(
    (
      sourceVersion: ConflictVersion,
      sourceScrollArea: ConflictScrollElement,
    ) => {
      syncComparisonScroll(
        sourceVersion,
        sourceScrollArea,
        propsScrollAreasRef.current,
        ignoredScrollPositionsRef.current,
      );
    },
    [],
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
        {tab === 'visual' && <ViewportSelector />}
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
              version="published"
              onScrollAreaMount={handleVisualScrollAreaMount}
              onScrollAreaScroll={handleVisualScroll}
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
            <VisualFrame
              html={newContentHtml}
              loading={!!newVersion.loading}
              version="new"
              onScrollAreaMount={handleVisualScrollAreaMount}
              onScrollAreaScroll={handleVisualScroll}
            />
          </PreviewCard>
        </ComparisonGrid>
      </Tabs.Content>
      <Tabs.Content value="text" className={styles.tabContent}>
        <TextDiffComparison
          publishedText={publishedText}
          newText={newText}
          publishedUpdated={publishedVersion.updated}
          newUpdated={newVersion.updated}
          newChanged={!!newVersion.changed}
          selectedVersion={selectedVersion}
          isLoading={isTextLoading}
          isError={isTextError}
          onSelectVersion={onSelectVersion}
          onPublishedPreviewClick={handlePublishedPreview}
          onNewPreviewClick={handleNewPreview}
          onScrollAreaMount={handleTextScrollAreaMount}
          onScrollAreaScroll={handleTextScroll}
        />
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
            <JsonPanel
              value={publishedProps}
              version="published"
              onScrollAreaMount={handlePropsScrollAreaMount}
              onScrollAreaScroll={handlePropsScroll}
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
            <JsonPanel
              value={newProps}
              version="new"
              onScrollAreaMount={handlePropsScrollAreaMount}
              onScrollAreaScroll={handlePropsScroll}
            />
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
              style={{ cursor: 'pointer' }}
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

const VisualFrame = ({
  html,
  loading,
  version,
  onScrollAreaMount,
  onScrollAreaScroll,
}: {
  html: string;
  loading: boolean;
  version: ConflictVersion;
  onScrollAreaMount?: (
    version: ConflictVersion,
    node: ConflictScrollElement | null,
  ) => void;
  onScrollAreaScroll?: (
    version: ConflictVersion,
    scrollArea: ConflictScrollElement,
  ) => void;
}) => {
  const previewScale = useAppSelector(selectEditorViewPortScale);
  const previewWidth = useAppSelector(selectViewportWidth);
  const previewMinHeight = useAppSelector(selectViewportMinHeight);
  const previewScrollAreaRef = useRef<HTMLDivElement | null>(null);
  const previewFrameWrapperRef = useRef<HTMLDivElement | null>(null);
  const dragStateRef = useRef<{
    pointerId: number;
    startX: number;
    startY: number;
    scrollLeft: number;
    scrollTop: number;
    didDrag: boolean;
  }>();
  const suppressNextClickRef = useRef(false);
  const [isDraggingPreview, setIsDraggingPreview] = useState(false);

  const handleScrollAreaRef = useCallback(
    (node: HTMLDivElement | null) => {
      previewScrollAreaRef.current = node;
      onScrollAreaMount?.(version, node);
    },
    [onScrollAreaMount, version],
  );

  const handleScroll = useCallback(
    (event: React.UIEvent<HTMLDivElement>) => {
      onScrollAreaScroll?.(version, event.currentTarget);
    },
    [onScrollAreaScroll, version],
  );

  const endDrag = useCallback((event: React.PointerEvent<HTMLDivElement>) => {
    const dragState = dragStateRef.current;
    if (!dragState || dragState.pointerId !== event.pointerId) {
      return;
    }

    if (
      event.currentTarget.hasPointerCapture?.(event.pointerId) &&
      event.currentTarget.releasePointerCapture
    ) {
      event.currentTarget.releasePointerCapture(event.pointerId);
    }
    suppressNextClickRef.current = dragState.didDrag;
    dragStateRef.current = undefined;
    setIsDraggingPreview(false);
  }, []);

  const handlePointerDown = useCallback(
    (event: React.PointerEvent<HTMLDivElement>) => {
      if (event.button !== 0 || loading) {
        return;
      }

      dragStateRef.current = {
        pointerId: event.pointerId,
        startX: event.clientX,
        startY: event.clientY,
        scrollLeft: event.currentTarget.scrollLeft,
        scrollTop: event.currentTarget.scrollTop,
        didDrag: false,
      };
      event.currentTarget.setPointerCapture?.(event.pointerId);
      setIsDraggingPreview(true);
    },
    [loading],
  );

  const handlePointerMove = useCallback(
    (event: React.PointerEvent<HTMLDivElement>) => {
      const dragState = dragStateRef.current;
      if (!dragState || dragState.pointerId !== event.pointerId) {
        return;
      }

      const deltaX = event.clientX - dragState.startX;
      const deltaY = event.clientY - dragState.startY;
      if (
        Math.abs(deltaX) > PREVIEW_DRAG_THRESHOLD ||
        Math.abs(deltaY) > PREVIEW_DRAG_THRESHOLD
      ) {
        dragState.didDrag = true;
      }

      event.currentTarget.scrollLeft = dragState.scrollLeft - deltaX;
      event.currentTarget.scrollTop = dragState.scrollTop - deltaY;
      onScrollAreaScroll?.(version, event.currentTarget);
    },
    [onScrollAreaScroll, version],
  );

  const handleClick = useCallback((event: React.MouseEvent<HTMLDivElement>) => {
    if (!suppressNextClickRef.current) {
      return;
    }

    suppressNextClickRef.current = false;
    event.stopPropagation();
    event.preventDefault();
  }, []);

  return (
    <div className={styles.visualSurface}>
      <div
        className={styles.previewScrollArea}
        ref={handleScrollAreaRef}
        data-testid="canvas-conflict-preview-scroll-area"
        data-dragging={isDraggingPreview ? 'true' : undefined}
        onClick={handleClick}
        onPointerCancel={endDrag}
        onPointerDown={handlePointerDown}
        onPointerMove={handlePointerMove}
        onPointerUp={endDrag}
        onScroll={handleScroll}
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

const TextDiffComparison = ({
  publishedText,
  newText,
  publishedUpdated,
  newUpdated,
  newChanged,
  selectedVersion,
  isLoading,
  isError,
  onSelectVersion,
  onPublishedPreviewClick,
  onNewPreviewClick,
  onScrollAreaMount,
  onScrollAreaScroll,
}: {
  publishedText: string;
  newText: string;
  publishedUpdated?: string;
  newUpdated?: string;
  newChanged: boolean;
  selectedVersion?: ConflictVersionSelection;
  isLoading: boolean;
  isError: boolean;
  onSelectVersion?: (version: ConflictVersion) => void;
  onPublishedPreviewClick: () => void;
  onNewPreviewClick: () => void;
  onScrollAreaMount?: (
    version: ConflictVersion,
    node: ConflictScrollElement | null,
  ) => void;
  onScrollAreaScroll?: (
    version: ConflictVersion,
    scrollArea: ConflictScrollElement,
  ) => void;
}) => {
  const hasTextContent = publishedText.trim() || newText.trim();

  return (
    <div className={styles.textDiffComparison} data-testid="conflict-text-diff">
      <ComparisonGrid>
        <PreviewCard
          title="Published version"
          updated={publishedUpdated}
          version="published"
          selected={selectedVersion === 'published'}
          onSelect={onSelectVersion}
          onPreviewClick={onPublishedPreviewClick}
        >
          <TextDiffPanel
            side="published"
            publishedText={publishedText}
            newText={newText}
            isLoading={isLoading}
            isError={isError}
            hasTextContent={!!hasTextContent}
            onScrollAreaMount={onScrollAreaMount}
            onScrollAreaScroll={onScrollAreaScroll}
          />
        </PreviewCard>
        <PreviewCard
          title="New version"
          updated={newUpdated}
          changed={newChanged}
          version="new"
          selected={selectedVersion === 'new'}
          onSelect={onSelectVersion}
          onPreviewClick={onNewPreviewClick}
        >
          <TextDiffPanel
            side="new"
            publishedText={publishedText}
            newText={newText}
            isLoading={isLoading}
            isError={isError}
            hasTextContent={!!hasTextContent}
            onScrollAreaMount={onScrollAreaMount}
            onScrollAreaScroll={onScrollAreaScroll}
          />
        </PreviewCard>
      </ComparisonGrid>
    </div>
  );
};

const TextDiffPanel = ({
  side,
  publishedText,
  newText,
  isLoading,
  isError,
  hasTextContent,
  onScrollAreaMount,
  onScrollAreaScroll,
}: {
  side: ConflictVersion;
  publishedText: string;
  newText: string;
  isLoading: boolean;
  isError: boolean;
  hasTextContent: boolean;
  onScrollAreaMount?: (
    version: ConflictVersion,
    node: HTMLDivElement | null,
  ) => void;
  onScrollAreaScroll?: (
    version: ConflictVersion,
    scrollArea: HTMLDivElement,
  ) => void;
}) => {
  const panelClassName = [
    styles.textDiffViewerSurface,
    side === 'published'
      ? styles.textDiffViewerPublished
      : styles.textDiffViewerNew,
  ]
    .filter(Boolean)
    .join(' ');
  const handleScrollAreaRef = useCallback(
    (node: HTMLDivElement | null) => {
      onScrollAreaMount?.(side, node);
    },
    [onScrollAreaMount, side],
  );
  const handleScroll = useCallback(
    (event: React.UIEvent<HTMLDivElement>) => {
      onScrollAreaScroll?.(side, event.currentTarget);
    },
    [onScrollAreaScroll, side],
  );

  return (
    <div
      ref={handleScrollAreaRef}
      className={panelClassName}
      data-testid={`conflict-text-diff-${side}`}
      onScroll={handleScroll}
    >
      {isLoading ? (
        <TextDiffStatus message="Loading text comparison..." />
      ) : isError ? (
        <TextDiffStatus
          message="Unable to load the text comparison for this page."
          color="red"
        />
      ) : hasTextContent ? (
        <ReactDiffViewer
          oldValue={publishedText}
          newValue={newText}
          splitView
          compareMethod={DiffMethod.YAML}
          showDiffOnly={false}
          useDarkTheme
          styles={YAML_DIFF_VIEWER_STYLES}
          hideSummary
          disableWordDiff
          disableWorker
        />
      ) : (
        <TextDiffStatus message="No text content." />
      )}
    </div>
  );
};

const TextDiffStatus = ({
  message,
  color = 'gray',
}: {
  message: string;
  color?: React.ComponentProps<typeof Text>['color'];
}) => (
  <Flex
    align="center"
    justify="center"
    className={styles.textDiffStatus}
    data-testid="conflict-text-diff-status"
  >
    <Text size="2" color={color}>
      {message}
    </Text>
  </Flex>
);

const JsonPanel = ({
  value,
  version,
  onScrollAreaMount,
  onScrollAreaScroll,
}: {
  value: string;
  version: ConflictVersion;
  onScrollAreaMount?: (
    version: ConflictVersion,
    node: ConflictScrollElement | null,
  ) => void;
  onScrollAreaScroll?: (
    version: ConflictVersion,
    scrollArea: ConflictScrollElement,
  ) => void;
}) => {
  const handlePanelRef = useCallback(
    (node: HTMLPreElement | null) => {
      onScrollAreaMount?.(version, node);
    },
    [onScrollAreaMount, version],
  );
  const handleScroll = useCallback(
    (event: React.UIEvent<HTMLPreElement>) => {
      onScrollAreaScroll?.(version, event.currentTarget);
    },
    [onScrollAreaScroll, version],
  );

  return (
    <pre
      ref={handlePanelRef}
      className={styles.textPanel}
      data-testid={`conflict-props-panel-${version}`}
      onScroll={handleScroll}
    >
      {value}
    </pre>
  );
};

interface ConflictPreviewControlsProps {
  previewScrollAreaRef: React.RefObject<HTMLDivElement | null>;
  previewFrameWrapperRef: React.RefObject<HTMLDivElement | null>;
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
