import { useDraggable } from '@dnd-kit/core';
import { CSS } from '@dnd-kit/utilities';
import { Box, Text } from '@radix-ui/themes';

import type { FormElementSchema } from '@/features/formBuilder/types';

interface PaletteItemProps {
  schema: FormElementSchema;
}

export function PaletteItem({ schema }: PaletteItemProps) {
  const { attributes, listeners, setNodeRef, transform, isDragging } =
    useDraggable({
      id: `palette:${schema.type}`,
      data: { origin: 'form-palette', elementType: schema.type, schema },
    });

  return (
    <Box
      ref={setNodeRef}
      {...attributes}
      {...listeners}
      data-testid={`palette-item-${schema.type}`}
      style={{
        transform: CSS.Translate.toString(transform),
        opacity: isDragging ? 0.5 : 1,
        cursor: 'grab',
        padding: '6px 8px',
        borderRadius: 4,
        border: '1px solid var(--gray-5)',
        background: 'var(--color-background)',
        userSelect: 'none',
      }}
    >
      <Text size="1" weight="medium">
        {schema.label}
      </Text>
    </Box>
  );
}
