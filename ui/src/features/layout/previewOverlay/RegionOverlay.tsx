import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { useParams } from 'react-router';
import { useSearchParams } from 'react-router-dom';
import { AlertDialog, Button, Flex } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { selectLayoutForRegion } from '@/features/layout/layoutModelSlice';
import { useDataToHtmlMapValue } from '@/features/layout/preview/DataToHtmlMapContext';
import { RegionNameTag } from '@/features/layout/preview/NameTag';
import RegionContextMenu from '@/features/layout/preview/RegionContextMenu';
import ComponentOverlay from '@/features/layout/previewOverlay/ComponentOverlay';
import EmptyRegionDropZone from '@/features/layout/previewOverlay/EmptyRegionDropZone';
import RegionDropZone from '@/features/layout/previewOverlay/RegionDropZone';
import {
  DEFAULT_REGION,
  selectDragging,
  selectEditorViewPortScale,
  selectIsComponentHovered,
  selectTargetSlot,
  setHoveredComponent,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import useSyncPreviewElementSize from '@/hooks/useSyncPreviewElementSize';
import { getLanguages } from '@/utils/drupal-globals';

import type React from 'react';
import type { RegionNode } from '@/features/layout/layoutModelSlice';

import styles from './PreviewOverlay.module.css';

interface RegionOverlayProps {
  iframeRef: React.RefObject<HTMLIFrameElement>;
  regionId: string;
  regionName: string;
  region: RegionNode;
}

const RegionOverlay: React.FC<RegionOverlayProps> = ({ iframeRef, region }) => {
  const layout = useAppSelector((state) =>
    selectLayoutForRegion(state, region.id),
  );
  const { regionsMap } = useDataToHtmlMapValue();
  const { regionId: focusedRegion = DEFAULT_REGION } = useParams();
  const { elementRect } = useSyncPreviewElementSize(
    regionsMap[region.id]?.elements,
  );
  const editorViewPortScale = useAppSelector(selectEditorViewPortScale);
  const [overlayStyles, setOverlayStyles] = useState({});
  const targetSlot = useAppSelector(selectTargetSlot);
  const disableRegion = focusedRegion !== region.id;
  const dispatch = useAppDispatch();
  const { isDragging } = useAppSelector(selectDragging);
  const isHovered = useAppSelector((state) => {
    return selectIsComponentHovered(state, region.id);
  });
  const { setSelectedRegion } = useEditorNavigation();
  const [blockDialogOpen, setBlockDialogOpen] = useState(false);
  const [searchParams] = useSearchParams();
  const languages = getLanguages();
  const activeLanguageId =
    searchParams.get('language') ??
    languages.find((l) => l.isDefault)?.id ??
    '';
  const sourceLangName =
    languages.find((l) => l.id === region.sourceLangcode)?.name ??
    region.sourceLangcode ??
    '';

  const showHovered = isHovered && focusedRegion === DEFAULT_REGION;

  useEffect(() => {
    setOverlayStyles({
      top: `${elementRect.top * editorViewPortScale}px`,
      left: `${elementRect.left * editorViewPortScale}px`,
      width: `${elementRect.width * editorViewPortScale}px`,
      height: `${elementRect.height * editorViewPortScale}px`,
    });
  }, [elementRect, editorViewPortScale, region.id, disableRegion, regionsMap]);

  function handleItemMouseOver(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    if (!isDragging) {
      dispatch(setHoveredComponent(region.id));
    }
  }

  function handleItemMouseOut(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    dispatch(unsetHoveredComponent());
  }

  function handleRegionDblClick(event: React.MouseEvent<HTMLDivElement>) {
    event.stopPropagation();
    if (focusedRegion !== region.id) {
      if (
        region.sourceLangcode &&
        activeLanguageId &&
        region.sourceLangcode !== activeLanguageId
      ) {
        setBlockDialogOpen(true);
        return;
      }
      // Navigate into the clicked region if it's different
      setSelectedRegion(region.id);
    } else {
      // Else we are already focused in this region, so clicking again should take us back out to the content region.
      setSelectedRegion();
    }
  }

  // If the DEFAULT_REGION is focused, then all regions should render otherwise only render if this is the focused region
  if (focusedRegion !== DEFAULT_REGION && focusedRegion !== region.id) {
    return null;
  }

  const isPage = region.id === DEFAULT_REGION;

  return (
    <>
      <div
        className={clsx(
          [isPage && styles.pageOverlay, !isPage && styles.regionOverlay],
          {
            [styles.dropTarget]: region.id === targetSlot,
            [styles.hovered]: showHovered,
          },
          `canvas--region-overlay__${region.id}`,
        )}
        style={overlayStyles}
        onMouseOver={handleItemMouseOver}
        onMouseOut={handleItemMouseOut}
        onDoubleClick={handleRegionDblClick}
      >
        {!isPage && (
          <RegionContextMenu region={region}>
            <div
              aria-label={`Global region ${region.name}`}
              className={styles.regionItem}
              data-canvas-overlay="true"
            />
          </RegionContextMenu>
        )}

        <div className={clsx(styles.canvasNameTag)}>
          <RegionNameTag
            name={region.name}
            id={region.id}
            nodeType={isPage ? 'page' : 'region'}
          />
        </div>

        {!disableRegion && (
          <>
            {layout.components.map((component, index) => (
              <ComponentOverlay
                key={component.uuid}
                iframeRef={iframeRef}
                component={component}
                parentRegion={layout}
                index={index}
              />
            ))}

            {!region.components.length && <EmptyRegionDropZone region={region} />}
            {!!region.components.length && (
              <>
                <RegionDropZone region={region} position="before" />
                <RegionDropZone region={region} position="after" />
              </>
            )}
          </>
        )}
      </div>
      <AlertDialog.Root
        open={blockDialogOpen}
        onOpenChange={setBlockDialogOpen}
      >
        <AlertDialog.Content maxWidth="400px">
          <AlertDialog.Title>Cannot edit this region</AlertDialog.Title>
          <AlertDialog.Description size="2">
            This region&apos;s source language is {sourceLangName}. Switch to{' '}
            {sourceLangName} to edit this region&apos;s content.
          </AlertDialog.Description>
          <Flex gap="3" mt="4" justify="end">
            <AlertDialog.Cancel>
              <Button variant="soft" color="gray">
                OK
              </Button>
            </AlertDialog.Cancel>
          </Flex>
        </AlertDialog.Content>
      </AlertDialog.Root>
    </>
  );
};

export default RegionOverlay;
