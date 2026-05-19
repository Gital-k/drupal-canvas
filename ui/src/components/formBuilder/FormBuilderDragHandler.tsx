import { useDndMonitor } from '@dnd-kit/core';

import { useAppDispatch } from '@/app/hooks';
import {
  addElement,
  moveElement,
} from '@/features/formBuilder/formBuilderSlice';

import type { DragEndEvent } from '@dnd-kit/core';
import type {
  FormElement,
  FormElementSchema,
} from '@/features/formBuilder/types';

interface FormBuilderDragHandlerProps {
  schemas: Record<string, FormElementSchema>;
}

export function FormBuilderDragHandler({
  schemas,
}: FormBuilderDragHandlerProps) {
  const dispatch = useAppDispatch();

  function handleDragEnd(event: DragEndEvent) {
    const { origin, elementType, schema, element } =
      event.active.data.current ?? {};
    const dropData = event.over?.data?.current;

    if (!dropData || !('parentUuid' in dropData)) return;

    const { parentUuid, afterUuid } = dropData as {
      parentUuid: string | null;
      afterUuid: string | null;
    };

    if (origin === 'form-palette') {
      dispatch(
        addElement({
          type: elementType as string,
          parentUuid: parentUuid ?? null,
          afterUuid: afterUuid ?? null,
          defaultProperties: schema?.properties
            ? Object.fromEntries(
                Object.entries(
                  schema.properties as Record<string, { default?: unknown }>,
                )
                  .filter(([, p]) => p.default !== undefined)
                  .map(([k, p]) => [k, p.default]),
              )
            : {},
        }),
      );
    } else if (origin === 'form-canvas' && element) {
      dispatch(
        moveElement({
          uuid: (element as FormElement).uuid,
          newParent: parentUuid ?? null,
          afterUuid: afterUuid ?? null,
        }),
      );
    }
  }

  useDndMonitor({ onDragEnd: handleDragEnd });

  return null;
}
