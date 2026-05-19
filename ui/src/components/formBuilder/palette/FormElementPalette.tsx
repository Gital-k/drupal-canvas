import { Box, Flex, ScrollArea, Text } from '@radix-ui/themes';

import { PaletteItem } from './PaletteItem';

import type { FormElementSchema } from '@/features/formBuilder/types';

const CATEGORY_LABELS: Record<string, string> = {
  text: 'Text inputs',
  selection: 'Selection',
  structural: 'Layout',
  action: 'Actions',
};

interface FormElementPaletteProps {
  schemas: Record<string, FormElementSchema>;
}

export function FormElementPalette({ schemas }: FormElementPaletteProps) {
  const byCategory = Object.values(schemas).reduce<
    Record<string, FormElementSchema[]>
  >((acc, schema) => {
    const cat = schema.category;
    if (!acc[cat]) acc[cat] = [];
    acc[cat].push(schema);
    return acc;
  }, {});

  return (
    <Box
      data-testid="form-builder-palette"
      style={{
        width: 180,
        borderRight: '1px solid var(--gray-5)',
        flexShrink: 0,
      }}
    >
      <ScrollArea style={{ height: '100%' }}>
        <Box p="3">
          {Object.entries(CATEGORY_LABELS).map(([category, catLabel]) => {
            const items = byCategory[category];
            if (!items?.length) return null;
            return (
              <Box
                key={category}
                data-testid={`palette-category-${category}`}
                mb="4"
              >
                <Text size="1" weight="medium" color="gray" mb="2" as="div">
                  {catLabel}
                </Text>
                <Flex direction="column" gap="1">
                  {items.map((schema) => (
                    <PaletteItem key={schema.type} schema={schema} />
                  ))}
                </Flex>
              </Box>
            );
          })}
        </Box>
      </ScrollArea>
    </Box>
  );
}
