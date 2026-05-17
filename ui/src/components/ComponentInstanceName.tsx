/**
 * ComponentInstanceName
 *
 * A small text input that lets a Content Creator give a per-instance label
 * ("Layer name") to a single component on a Canvas page. The label shows up
 * in the Layers panel in place of the generic component-type name, so
 * sitebuilders can tell e.g. five Section Wrappers apart.
 *
 * Renders only when exactly one component is selected. Reads from and writes
 * directly to Redux — `node.name` on the ComponentNode in the layout slice.
 * Empty / whitespace-only input is stored as null (canonical "no name").
 *
 * Refs: Drupal.org issue #3527952.
 */

import { Flex, Text, TextField } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectLayout,
  setComponentName,
} from '@/features/layout/layoutModelSlice';
import {
  findComponentByUuid,
  getComponentTypeIndex,
} from '@/features/layout/layoutUtils';
import { selectSelectedComponentUuid } from '@/features/ui/uiSlice';
import { useGetComponentsQuery } from '@/services/componentAndLayout';

import type React from 'react';

const ComponentInstanceName: React.FC = () => {
  const dispatch = useAppDispatch();
  const selectedUuid = useAppSelector(selectSelectedComponentUuid);
  const layout = useAppSelector(selectLayout);
  const { data: components } = useGetComponentsQuery();

  if (!selectedUuid) {
    return null;
  }
  const node = findComponentByUuid(layout, selectedUuid);
  if (!node) {
    return null;
  }
  const [nodeType] = node.type.split('@');
  const typeName = components?.[nodeType]?.name || 'Component';
  // The placeholder matches what the Layers panel would display if the user
  // left the field blank — i.e. the per-page positional index appended to
  // the component-type name. See #3527952.
  const typeIndex = getComponentTypeIndex(layout, selectedUuid, node.type);
  const placeholderName =
    typeIndex > 0 ? `${typeName} ${typeIndex}` : typeName;

  const value = node.name ?? '';

  return (
    <Flex
      direction="column"
      gap="1"
      mb="3"
      data-testid="canvas-component-instance-name"
    >
      <Text as="label" htmlFor="canvas-component-instance-name-input" size="1">
        Layer name
      </Text>
      <TextField.Root
        id="canvas-component-instance-name-input"
        autoComplete="off"
        size="1"
        value={value}
        placeholder={placeholderName}
        onChange={(e) => {
          dispatch(
            setComponentName({
              uuid: selectedUuid,
              name: e.target.value,
            }),
          );
        }}
      />
      <Text size="1" color="gray">
        Shown only in the editor — not visible on the published page.
      </Text>
    </Flex>
  );
};

export default ComponentInstanceName;
