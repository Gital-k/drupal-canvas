import clsx from 'clsx';
import { CheckIcon } from '@radix-ui/react-icons';
import { Flex, Popover, Text } from '@radix-ui/themes';

import styles from './AiToolSelector.module.css';

export interface AiTool {
  id: string;
  label: string;
  description: string;
}

// Hardcoded tool list. The `id` is what gets sent to the backend as
// `target_tool` on every hop request.
export const AI_TOOLS: AiTool[] = [
  {
    id: 'page_builder',
    label: 'Build a page',
    description: 'Create or edit a canvas page with AI',
  },
  {
    id: 'component_agent',
    label: 'Build a component',
    description: 'Create React components with AI',
  },
];

interface AiToolSelectorProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  selectedTool: string | null;
  onSelect: (id: string | null) => void;
}

// The tools menu for the AI chat. The button that opens it lives inside
// deep-chat's shadow DOM (added via deep-chat's `customButtons`), so the menu
// cannot anchor to it directly. Instead a zero-size span is positioned over the
// button (in the light DOM) and used as the popover anchor; deep-chat's button
// `onClick` toggles `open`. Single-select: clicking the active tool clears it.
const AiToolSelector = ({
  open,
  onOpenChange,
  selectedTool,
  onSelect,
}: AiToolSelectorProps) => (
  <Popover.Root open={open} onOpenChange={onOpenChange}>
    <Popover.Anchor className={styles.menuAnchor} />
    <Popover.Content side="top" align="end" sideOffset={8} width="240px">
      <Text className={styles.heading}>Available tools</Text>
      {AI_TOOLS.map((tool) => {
        const isSelected = tool.id === selectedTool;
        return (
          <button
            key={tool.id}
            type="button"
            className={clsx(styles.toolRow, isSelected && styles.selected)}
            aria-pressed={isSelected}
            onClick={() => {
              onSelect(isSelected ? null : tool.id);
              onOpenChange(false);
            }}
          >
            <Flex direction="column" align="start" gap="1">
              <Text className={styles.toolLabel}>{tool.label}</Text>
              <Text className={styles.toolDescription}>{tool.description}</Text>
            </Flex>
            {isSelected && <CheckIcon className={styles.checkIcon} />}
          </button>
        );
      })}
    </Popover.Content>
  </Popover.Root>
);

export default AiToolSelector;
