import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { AlertDialog, Button, ContextMenu, Flex } from '@radix-ui/themes';

import { UnifiedMenu } from '@/components/UnifiedMenu';
import useEditorNavigation from '@/hooks/useEditorNavigation';
import { getLanguages } from '@/utils/drupal-globals';

import type React from 'react';
import type { ReactNode } from 'react';
import type { UnifiedMenuType } from '@/components/UnifiedMenu';
import type { RegionNode } from '@/features/layout/layoutModelSlice';

interface RegionContextMenuProps {
  children: ReactNode;
  region: RegionNode;
}
export const RegionContextMenuContent: React.FC<
  Pick<RegionContextMenuProps, 'region'> & {
    menuType?: UnifiedMenuType;
  }
> = ({ region, menuType = 'context' }) => {
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

  const handleEditGlobalRegion = () => {
    if (
      region.sourceLangcode &&
      activeLanguageId &&
      region.sourceLangcode !== activeLanguageId
    ) {
      setBlockDialogOpen(true);
      return;
    }
    setSelectedRegion(region.id);
  };

  return (
    <>
      <UnifiedMenu.Content
        aria-label="Context menu for region"
        menuType={menuType}
        align="start"
        side="right"
      >
        <UnifiedMenu.Label>{region.name}</UnifiedMenu.Label>
        <UnifiedMenu.Separator />
        <UnifiedMenu.Item onClick={handleEditGlobalRegion}>
          Edit global region
        </UnifiedMenu.Item>
      </UnifiedMenu.Content>
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

const RegionContextMenu: React.FC<RegionContextMenuProps> = ({
  children,
  region,
}) => {
  return (
    <ContextMenu.Root>
      <ContextMenu.Trigger>{children}</ContextMenu.Trigger>
      <RegionContextMenuContent region={region} />
    </ContextMenu.Root>
  );
};

export default RegionContextMenu;
