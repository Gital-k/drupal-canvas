import { useEffect, useState } from 'react';
import { useDroppable } from '@dnd-kit/core';
import { Box, Flex, ScrollArea, Text } from '@radix-ui/themes';

import { useAppSelector } from '@/app/hooks';
import { selectRootElements } from '@/features/formBuilder/selectors';

import { FormElementDropZone } from './canvas/FormElementDropZone';
import { FormElementNode } from './canvas/FormElementNode';

import type { FormElementSchema } from '@/features/formBuilder/types';

const hoverStyles = `
  .form-element-node:hover > .form-element-toolbar,
  .form-element-node:hover .form-element-toolbar {
    opacity: 1 !important;
    pointer-events: auto !important;
  }
  .form-element-node:hover {
    outline: 2px solid var(--gray-6) !important;
  }
`;

const ACCEPTS = ['form-palette', 'form-canvas'];

interface FormCanvasProps {
  schemas: Record<string, FormElementSchema>;
}

function EmptyDropZone() {
  const [activeOrigin, setActiveOrigin] = useState('');

  const { setNodeRef, isOver, active } = useDroppable({
    id: 'drop:root:start',
    disabled: !ACCEPTS.includes(activeOrigin),
    data: { parentUuid: null, afterUuid: null },
  });

  useEffect(() => {
    setActiveOrigin(active?.data?.current?.origin ?? '');
  }, [active]);

  return (
    <Flex
      ref={setNodeRef}
      data-testid="form-canvas-empty"
      align="center"
      justify="center"
      style={{
        height: 200,
        borderRadius: 8,
        border: `2px dashed ${isOver ? 'var(--accent-8)' : 'var(--gray-5)'}`,
        background: isOver ? 'var(--accent-2)' : 'transparent',
        transition: 'border-color 0.1s ease, background 0.1s ease',
      }}
    >
      <Text size="2" color="gray">
        Drag elements from the palette to build your form
      </Text>
    </Flex>
  );
}

export function FormCanvas({ schemas }: FormCanvasProps) {
  const rootElements = useAppSelector(selectRootElements);

  return (
    <Box
      data-testid="form-canvas"
      style={{
        flex: 1,
        display: 'flex',
        flexDirection: 'column',
        overflow: 'hidden',
      }}
    >
      <style>{hoverStyles}</style>
      <ScrollArea style={{ flex: 1 }}>
        <Box p="4" style={{ maxWidth: 680, margin: '0 auto' }}>
          {rootElements.length === 0 ? (
            <EmptyDropZone />
          ) : (
            <>
              <FormElementDropZone
                id="drop:root:start"
                parentUuid={null}
                afterUuid={null}
              />
              <div
                data-testid="form-canvas-elements"
                style={{ display: 'flex', flexDirection: 'column', gap: 4 }}
              >
                {rootElements.map((element) => (
                  <div key={element.uuid}>
                    <FormElementNode element={element} schemas={schemas} />
                    <FormElementDropZone
                      id={`drop:root:after:${element.uuid}`}
                      parentUuid={null}
                      afterUuid={element.uuid}
                    />
                  </div>
                ))}
              </div>
            </>
          )}
        </Box>
      </ScrollArea>
    </Box>
  );
}
