import { useEffect, useState } from 'react';
import { useDraggable, useDroppable } from '@dnd-kit/core';
import { CSS } from '@dnd-kit/utilities';
import { Cross2Icon, DragHandleDots2Icon } from '@radix-ui/react-icons';
import { Box, Flex, IconButton } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  removeElement,
  selectElement,
} from '@/features/formBuilder/formBuilderSlice';
import {
  selectChildrenOf,
  selectSelectedUuid,
} from '@/features/formBuilder/selectors';

import { FormElementDropZone } from './FormElementDropZone';
import { FormElementPreview } from './FormElementPreview';

import type { DraggableAttributes } from '@dnd-kit/core';
import type { SyntheticListenerMap } from '@dnd-kit/core/dist/hooks/utilities';
import type {
  FormElement,
  FormElementSchema,
} from '@/features/formBuilder/types';

const ACCEPTS = ['form-palette', 'form-canvas'];

interface FormElementNodeProps {
  element: FormElement;
  schemas: Record<string, FormElementSchema>;
}

export function FormElementNode({ element, schemas }: FormElementNodeProps) {
  const dispatch = useAppDispatch();
  const selectedUuid = useAppSelector(selectSelectedUuid);
  const children = useAppSelector(selectChildrenOf(element.uuid));
  const schema = schemas[element.type];
  const isSelected = selectedUuid === element.uuid;
  const canHaveChildren = schema?.canContainChildren ?? false;
  const [activeOrigin, setActiveOrigin] = useState('');

  const {
    attributes,
    listeners,
    setNodeRef: setDragRef,
    transform,
    isDragging,
  } = useDraggable({
    id: `element:${element.uuid}`,
    data: { origin: 'form-canvas', element },
  });

  const {
    setNodeRef: setDropRef,
    isOver,
    active,
  } = useDroppable({
    id: `container:${element.uuid}`,
    disabled: !canHaveChildren || !ACCEPTS.includes(activeOrigin),
    data: { parentUuid: element.uuid, afterUuid: null },
  });

  useEffect(() => {
    setActiveOrigin(active?.data?.current?.origin ?? '');
  }, [active]);

  return (
    <Box
      data-testid="form-element-node"
      data-element-type={element.type}
      data-element-uuid={element.uuid}
      data-machine-name={element.machineName}
      style={{
        opacity: isDragging ? 0.4 : 1,
        transform: CSS.Translate.toString(transform),
        position: 'relative',
      }}
    >
      <Box
        ref={setDragRef}
        onClick={(e) => {
          e.stopPropagation();
          dispatch(selectElement(element.uuid));
        }}
        style={{
          borderRadius: 6,
          padding: '10px 12px',
          outline: isSelected
            ? '2px solid var(--accent-8)'
            : '2px solid transparent',
          outlineOffset: 2,
          cursor: 'pointer',
          position: 'relative',
        }}
        className="form-element-node"
      >
        <Toolbar
          attributes={attributes}
          listeners={listeners}
          onDelete={(e) => {
            e.stopPropagation();
            dispatch(removeElement(element.uuid));
          }}
          visible={isSelected}
        />
        <FormElementPreview element={element} schemas={schemas} />

        {canHaveChildren && (
          <Box
            ref={setDropRef}
            data-testid="form-element-children"
            style={{
              marginTop: 8,
              paddingLeft: 16,
              paddingTop: 4,
              paddingBottom: 4,
              borderLeft: '3px solid var(--gray-4)',
              minHeight: 40,
              background: isOver ? 'var(--accent-2)' : undefined,
              borderRadius: '0 4px 4px 0',
              transition: 'background 0.1s ease',
            }}
          >
            <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
              {children.map((child, i) => (
                <div key={child.uuid}>
                  <FormElementDropZone
                    id={`drop:${element.uuid}:before:${child.uuid}`}
                    parentUuid={element.uuid}
                    afterUuid={i === 0 ? null : children[i - 1].uuid}
                  />
                  <FormElementNode element={child} schemas={schemas} />
                </div>
              ))}
              <FormElementDropZone
                id={`drop:${element.uuid}:end`}
                parentUuid={element.uuid}
                afterUuid={children[children.length - 1]?.uuid ?? null}
              />
            </div>
          </Box>
        )}
      </Box>
    </Box>
  );
}

function Toolbar({
  attributes,
  listeners,
  onDelete,
  visible,
}: {
  attributes: DraggableAttributes;
  listeners: SyntheticListenerMap | undefined;
  onDelete: (e: React.MouseEvent) => void;
  visible: boolean;
}) {
  return (
    <Flex
      align="center"
      gap="1"
      style={{
        position: 'absolute',
        top: -14,
        right: 0,
        zIndex: 10,
        opacity: visible ? 1 : 0,
        background: 'var(--accent-9)',
        borderRadius: '4px 4px 0 0',
        padding: '0 4px',
        height: 20,
        pointerEvents: visible ? 'auto' : 'none',
        transition: 'opacity 0.1s ease',
      }}
      className="form-element-toolbar"
    >
      <Box
        {...attributes}
        {...listeners}
        style={{
          cursor: 'grab',
          color: 'white',
          display: 'flex',
          alignItems: 'center',
        }}
      >
        <DragHandleDots2Icon />
      </Box>
      <IconButton
        size="1"
        variant="ghost"
        color="gray"
        onClick={onDelete}
        style={{ color: 'white' }}
      >
        <Cross2Icon />
      </IconButton>
    </Flex>
  );
}
