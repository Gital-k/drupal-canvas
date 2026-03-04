import {
  closestCenter,
  DndContext,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import {
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { PlusIcon } from '@radix-ui/react-icons';
import { Button, Flex } from '@radix-ui/themes';

import FieldItem from './FieldItem';

import type { DragEndEvent } from '@dnd-kit/core';
import type { FieldItem as FieldItemType } from './types';

import styles from './FieldItemEditor.module.css';

interface SortableItemProps {
  item: FieldItemType;
  isSelected: boolean;
  onClick: () => void;
}

function SortableItem({ item, isSelected, onClick }: SortableItemProps) {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id: item.id });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  };

  return (
    <div ref={setNodeRef} style={style}>
      <FieldItem
        displayValue={item.displayValue}
        isSelected={isSelected}
        isDraggable={true}
        onClick={onClick}
        dragHandleProps={{ ...attributes, ...listeners }}
      />
    </div>
  );
}

interface FieldItemListProps {
  items: FieldItemType[];
  cardinality: number;
  onItemClick: (index: number) => void;
  onReorder: (oldIndex: number, newIndex: number) => void;
  onAdd: () => void;
  selectedIndex: number | null;
}

export default function FieldItemList({
  items,
  cardinality,
  onItemClick,
  onReorder,
  onAdd,
  selectedIndex,
}: FieldItemListProps) {
  const sensors = useSensors(
    useSensor(PointerSensor),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    }),
  );

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;

    if (over && active.id !== over.id) {
      const oldIndex = items.findIndex((item) => item.id === active.id);
      const newIndex = items.findIndex((item) => item.id === over.id);

      onReorder(oldIndex, newIndex);
    }
  };

  const isMultiValue = cardinality !== 1;

  if (isMultiValue && items.length > 0) {
    return (
      <Flex direction="column" gap="2" className={styles.listView}>
        <DndContext
          sensors={sensors}
          collisionDetection={closestCenter}
          onDragEnd={handleDragEnd}
        >
          <SortableContext
            items={items.map((item) => item.id)}
            strategy={verticalListSortingStrategy}
          >
            {items.map((item, index) => (
              <SortableItem
                key={item.id}
                item={item}
                isSelected={selectedIndex === index}
                onClick={() => onItemClick(index)}
              />
            ))}
          </SortableContext>
        </DndContext>
        <Button
          size="1"
          variant="soft"
          onClick={onAdd}
          className={styles.addButton}
        >
          <PlusIcon /> Add new
        </Button>
      </Flex>
    );
  }

  return (
    <Flex direction="column" gap="2" className={styles.listView}>
      {items.length === 0 && isMultiValue ? (
        <Button
          size="1"
          variant="soft"
          onClick={onAdd}
          className={styles.addButton}
        >
          <PlusIcon /> Add new
        </Button>
      ) : (
        items.map((item, index) => (
          <FieldItem
            key={item.id}
            displayValue={item.displayValue}
            isSelected={selectedIndex === index}
            isDraggable={false}
            onClick={() => onItemClick(index)}
          />
        ))
      )}
    </Flex>
  );
}
