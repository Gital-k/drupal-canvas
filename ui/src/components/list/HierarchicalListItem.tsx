import { useState } from 'react';
import clsx from 'clsx';
import * as Collapsible from '@radix-ui/react-collapsible';
import { ChevronRightIcon } from '@radix-ui/react-icons';
import { Flex } from '@radix-ui/themes';

import ListItem from '@/components/list/ListItem';
import { ListIndentContext } from '@/components/sidePanel/ListIndentContext';

import type React from 'react';
import type { LayoutItemType } from '@/features/ui/primaryPanelSlice';
import type { CanvasComponent } from '@/types/Component';
import type { ComponentWithChildren } from '@/hooks/useComponentHierarchy';

import detailsStyles from '@/components/form/components/AccordionAndDetails.module.css';
import listStyles from '@/components/list/List.module.css';

interface HierarchicalListItemProps {
  item: ComponentWithChildren;
  type: LayoutItemType.COMPONENT;
}

/**
 * Renders a component list item with optional nested child components.
 * If the component has childComponents (from allowedComponents in slots),
 * they are rendered in a collapsible section underneath.
 */
const HierarchicalListItem: React.FC<HierarchicalListItemProps> = ({
  item,
  type,
}) => {
  const [isOpen, setIsOpen] = useState(false);
  const hasChildren = item.childComponents && item.childComponents.length > 0;

  if (!hasChildren) {
    // No children - render with spacer to align with items that have chevrons
    return (
      <Flex align="center" className={listStyles.hierarchicalItem}>
        <div className={listStyles.hierarchicalSpacer} />
        <Flex flexGrow="1">
          <ListItem item={item as CanvasComponent} type={type} />
        </Flex>
      </Flex>
    );
  }

  return (
    <Collapsible.Root open={isOpen} onOpenChange={setIsOpen}>
      <Flex align="center" className={listStyles.hierarchicalItem}>
        <Collapsible.Trigger asChild>
          <button
            className={listStyles.hierarchicalTrigger}
            aria-label={`${isOpen ? 'Collapse' : 'Expand'} ${item.name} children`}
          >
            <ChevronRightIcon
              className={clsx(listStyles.chevron, {
                [listStyles.isOpen]: isOpen,
              })}
            />
          </button>
        </Collapsible.Trigger>
        <Flex flexGrow="1">
          <ListItem item={item as CanvasComponent} type={type} />
        </Flex>
      </Flex>
      <Collapsible.Content
        className={clsx(detailsStyles.content, detailsStyles.detailsContent)}
      >
        <ListIndentContext.Provider value={2.5}>
          <Flex direction="column" className={listStyles.hierarchicalChildren}>
            {item.childComponents?.map((child) => (
              <ListItem key={child.id} item={child} type={type} />
            ))}
          </Flex>
        </ListIndentContext.Provider>
      </Collapsible.Content>
    </Collapsible.Root>
  );
};

export default HierarchicalListItem;
