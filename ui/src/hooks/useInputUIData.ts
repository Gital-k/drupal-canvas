import { useMemo, useRef } from 'react';

import { useAppSelector } from '@/app/hooks';
import { selectCurrentComponent } from '@/features/form/formStateSlice';
import { selectLayout, selectModel } from '@/features/layout/layoutModelSlice';
import { findComponentByUuid } from '@/features/layout/layoutUtils';
import { useGetComponentsQuery } from '@/services/componentAndLayout';

import type { InputUIData } from '@/types/Form';

const useInputUIData = (): InputUIData => {
  const currentComponent = useAppSelector(selectCurrentComponent);
  const selectedComponent = currentComponent || 'noop';
  const model = useAppSelector(selectModel);
  const { data: components } = useGetComponentsQuery();
  const layout = useAppSelector(selectLayout);
  const node = findComponentByUuid(layout, selectedComponent);
  const [selectedComponentType, version] = (
    node ? (node.type as string) : 'noop'
  ).split('@');

  const callCountRef = useRef(0);
  const prevValuesRef = useRef<any>(null);
  const prevResultRef = useRef<any>(null);
  callCountRef.current += 1;

  // Use useMemo to only create new object when dependencies actually change
  const result = useMemo(() => {
    const newResult = {
      selectedComponent,
      components,
      selectedComponentType,
      layout,
      node,
      version,
      model,
    };

    prevValuesRef.current = newResult;
    return newResult;
  }, [
    selectedComponent,
    components,
    selectedComponentType,
    layout,
    node,
    version,
    model,
  ]);

  // Log after useMemo to see if we're returning same or new reference
  if (callCountRef.current > 1) {
    if (prevResultRef.current === result) {
      // console.log(
      //   `[useInputUIData] Call #${callCountRef.current} - ✅ Returning SAME OBJECT from useMemo`,
      // );
    } else {
      // console.log(
      //   `[useInputUIData] Call #${callCountRef.current} - ⚠️ useMemo returned NEW OBJECT`,
      // );

      // Check what changed in dependencies
      const prev = prevValuesRef.current;
      if (prev) {
        const changes: string[] = [];
        if (prev.selectedComponent !== selectedComponent)
          changes.push('selectedComponent');
        if (prev.components !== components) changes.push('components');
        if (prev.selectedComponentType !== selectedComponentType)
          changes.push('selectedComponentType');
        if (prev.layout !== layout) changes.push('layout');
        if (prev.node !== node) changes.push('node');
        if (prev.version !== version) changes.push('version');
        if (prev.model !== model) changes.push('model');

        if (changes.length > 0) {
          console.log(
            `[useInputUIData]   Dependencies that changed: ${changes.join(', ')}`,
          );
        }
      }
    }
  }

  prevResultRef.current = result;

  return result;
};

export default useInputUIData;
