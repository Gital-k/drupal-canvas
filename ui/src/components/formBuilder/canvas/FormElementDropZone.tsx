import { useEffect, useState } from 'react';
import { useDroppable } from '@dnd-kit/core';

const ACCEPTS = ['form-palette', 'form-canvas'];

interface FormElementDropZoneProps {
  id: string;
  parentUuid: string | null;
  afterUuid: string | null;
}

export function FormElementDropZone({
  id,
  parentUuid,
  afterUuid,
}: FormElementDropZoneProps) {
  const [activeOrigin, setActiveOrigin] = useState('');

  const { setNodeRef, isOver, active } = useDroppable({
    id,
    disabled: !ACCEPTS.includes(activeOrigin),
    data: { parentUuid, afterUuid },
  });

  useEffect(() => {
    setActiveOrigin(active?.data?.current?.origin ?? '');
  }, [active]);

  return (
    <div
      ref={setNodeRef}
      data-testid="form-drop-zone"
      data-drop-id={id}
      style={{
        height: 10,
        margin: '-5px 0',
        position: 'relative',
        zIndex: 1,
        borderRadius: 4,
        background: isOver ? 'var(--accent-4)' : 'transparent',
        border: isOver
          ? '2px dashed var(--accent-8)'
          : '2px dashed transparent',
        transition: 'background 0.1s ease, border-color 0.1s ease',
      }}
    />
  );
}
