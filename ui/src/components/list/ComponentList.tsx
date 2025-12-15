import { useEffect, useMemo } from 'react';
import { useErrorBoundary } from 'react-error-boundary';

import HierarchicalListItem from '@/components/list/HierarchicalListItem';
import { LayoutItemType } from '@/features/ui/primaryPanelSlice';
import {
  filterOutChildComponents,
  useComponentHierarchy,
} from '@/hooks/useComponentHierarchy';
import { useSlotFilteredComponents } from '@/hooks/useSlotFilteredComponents';
import {
  useGetComponentsQuery,
  useGetFoldersQuery,
} from '@/services/componentAndLayout';

import LibraryItemList from './LibraryItemList';

import type { CanvasComponent, ComponentsList } from '@/types/Component';
import type { ComponentWithChildren } from '@/hooks/useComponentHierarchy';
import type { FolderData } from './FolderList';

interface ComponentListProps {
  searchTerm: string;
}

const ComponentList = ({ searchTerm }: ComponentListProps) => {
  const { data: components, error, isLoading } = useGetComponentsQuery();
  const {
    data: folders,
    error: foldersError,
    isLoading: foldersLoading,
  } = useGetFoldersQuery({ status: false });
  const { showBoundary } = useErrorBoundary();

  // Build hierarchy data from allowedComponents in slots
  const { componentsWithHierarchy, childComponentIds } =
    useComponentHierarchy(components);

  // Filter components based on target slot's allowedComponents
  const slotFilteredComponents = useSlotFilteredComponents(components);

  // Filter out child components from the main list (they'll appear nested under parents)
  const displayComponents = useMemo(() => {
    // If slot filtering is active, use that; otherwise filter out children
    if (slotFilteredComponents !== components) {
      return slotFilteredComponents;
    }
    return filterOutChildComponents(
      componentsWithHierarchy as ComponentsList,
      childComponentIds,
    );
  }, [slotFilteredComponents, components, componentsWithHierarchy, childComponentIds]);

  useEffect(() => {
    if (error || foldersError) {
      showBoundary(error || foldersError);
    }
  }, [error, foldersError, showBoundary]);

  const renderItem = (item: CanvasComponent) => {
    const itemWithChildren = componentsWithHierarchy[item.id] as ComponentWithChildren;
    return (
      <HierarchicalListItem
        item={itemWithChildren || item}
        type={LayoutItemType.COMPONENT}
      />
    );
  };

  return (
    <LibraryItemList<CanvasComponent>
      items={displayComponents as ComponentsList}
      folders={folders as FolderData}
      isLoading={isLoading || foldersLoading}
      searchTerm={searchTerm}
      layoutType={LayoutItemType.COMPONENT}
      topLevelLabel="Components"
      itemType="component"
      renderItem={renderItem}
    />
  );
};

export default ComponentList;
