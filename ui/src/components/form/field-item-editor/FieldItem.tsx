import clsx from 'clsx';
import { DragHandleDots2Icon } from '@radix-ui/react-icons';
import { Flex, Text } from '@radix-ui/themes';

import styles from './FieldItemEditor.module.css';

interface FieldItemProps {
  displayValue: string;
  isSelected: boolean;
  isDraggable: boolean;
  onClick: () => void;
  dragHandleProps?: any;
}

export default function FieldItem({
  displayValue,
  isSelected,
  isDraggable,
  onClick,
  dragHandleProps,
}: FieldItemProps) {
  return (
    <Flex
      align="center"
      gap="2"
      className={clsx(styles.listItem, {
        [styles.listItemSelected]: isSelected,
      })}
      onClick={onClick}
    >
      {isDraggable && (
        <div className={styles.dragHandle} {...dragHandleProps}>
          <DragHandleDots2Icon />
        </div>
      )}
      <Text size="2" className={styles.itemText}>
        {displayValue || '(empty)'}
      </Text>
    </Flex>
  );
}
