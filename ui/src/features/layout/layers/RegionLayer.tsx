import { useCallback, useState } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
import { AlertDialog, Box, Button, Flex } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import SidebarNode from '@/components/sidePanel/SidebarNode';
import ComponentLayer from '@/features/layout/layers/ComponentLayer';
import RegionContextMenu, {
  RegionContextMenuContent,
} from '@/features/layout/preview/RegionContextMenu';
import {
  DEFAULT_REGION,
  selectIsComponentHovered,
  setHoveredComponent,
  unsetHoveredComponent,
} from '@/features/ui/uiSlice';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { getLanguages } from '@/utils/drupal-globals';

import type React from 'react';
import type { RegionNode } from '@/features/layout/layoutModelSlice';

const RegionLayer: React.FC<{ region: RegionNode; isPage?: boolean }> = ({
  region,
  isPage = false,
}) => {
  const { regionId: focusedRegion = DEFAULT_REGION } = useParams();
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
  const dispatch = useAppDispatch();
  const isHovered = useAppSelector((state) => {
    return selectIsComponentHovered(state, region.id);
  });

  const handleRegionClick = useCallback(() => {
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
  }, [
    activeLanguageId,
    focusedRegion,
    region.id,
    region.sourceLangcode,
    setSelectedRegion,
  ]);

  // Prevent selecting text when double-clicking regions in the layers panel (double-click normally selects text).
  const handleMouseDown = useCallback((event: React.MouseEvent) => {
    if (event.detail > 1) {
      event.preventDefault();
    }
  }, []);

  const handleMouseOver = useCallback(
    (event: React.MouseEvent<HTMLDivElement>) => {
      event.stopPropagation();
      dispatch(setHoveredComponent(region.id));
    },
    [dispatch, region.id],
  );

  const handleMouseOut = useCallback(
    (event: React.MouseEvent<HTMLDivElement>) => {
      event.stopPropagation();
      dispatch(unsetHoveredComponent());
    },
    [dispatch],
  );

  const variant: 'page' | 'region' = isPage ? 'page' : 'region';
  const sidebarNodeProps = {
    onDoubleClick: handleRegionClick,
    onMouseDown: handleMouseDown,
    onMouseOver: handleMouseOver,
    onMouseOut: handleMouseOut,
    draggable: false,
    title: region.name,
    variant,
    open: region.id === focusedRegion,
    hovered: isHovered,
    'data-hovered': isHovered,
    ...(region.id !== focusedRegion && {
      dropdownMenuContent: (
        <RegionContextMenuContent region={region} menuType="dropdown" />
      ),
    }),
  };

  return (
    <>
      <Box>
        {region.id === focusedRegion ? (
          <>
            <SidebarNode {...sidebarNodeProps} />
            <Box role="tree">
              {region.components.map((component, index) => (
                <ComponentLayer
                  index={index}
                  key={component.uuid}
                  component={component}
                  parentNode={region}
                  indent={1}
                />
              ))}
            </Box>
          </>
        ) : (
          <RegionContextMenu region={region}>
            <SidebarNode {...sidebarNodeProps} />
          </RegionContextMenu>
        )}
      </Box>
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

export default RegionLayer;
