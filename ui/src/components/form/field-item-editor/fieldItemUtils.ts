import type { PropsValues } from '@/types/Form';
import type { FieldItem } from './types';

export function extractDisplayValue(
  value: any,
  fieldType: string = 'text',
): string {
  if (value === null || value === undefined) {
    return '';
  }

  if (typeof value === 'string') {
    return value;
  }

  if (typeof value === 'object' && 'value' in value) {
    return String(value.value || '');
  }

  return String(value);
}

export function getFieldCardinality(attributes: Record<string, any>): number {
  if (attributes['data-cardinality']) {
    const cardinality = parseInt(attributes['data-cardinality'], 10);
    return cardinality === -1 ? -1 : cardinality;
  }

  const name = attributes.name || '';
  const deltaMatch = name.match(/\[(\d+)\]\[value\]$/);
  if (deltaMatch) {
    return -1;
  }

  return 1;
}

export function getDeltaFromAttributes(
  attributes: Record<string, any>,
): number {
  const name = attributes.name || '';
  const deltaMatch = name.match(/\[(\d+)\]\[value\]$/);
  if (deltaMatch) {
    return parseInt(deltaMatch[1], 10);
  }
  return 0;
}

export function isTextField(attributes: Record<string, any>): boolean {
  const type = attributes.type;
  const tagName = attributes.tagName;

  return (
    (type === 'text' || type === 'textarea' || tagName === 'TEXTAREA') &&
    !attributes.readonly &&
    !attributes.disabled
  );
}

export function updateItemWeights(items: FieldItem[]): FieldItem[] {
  return items.map((item, index) => ({
    ...item,
    weight: index,
  }));
}

export function shouldUseFieldItemEditor(
  attributes: Record<string, any>,
  context: string,
): boolean {
  if (context !== 'component_instance_form') {
    return false;
  }

  if (!isTextField(attributes)) {
    return false;
  }

  if (attributes['data-canvas-disable-field-editor']) {
    return false;
  }

  const delta = getDeltaFromAttributes(attributes);
  if (delta !== 0) {
    return false;
  }

  return true;
}

export function parseFieldItems(
  formState: PropsValues,
  fieldName: string,
  componentId: string,
): FieldItem[] {
  const items: FieldItem[] = [];
  const fieldKey = `canvas_component_props[${componentId}][${fieldName}]`;

  Object.keys(formState).forEach((key) => {
    if (key.startsWith(fieldKey)) {
      const match = key.match(/\[(\d+)\]\[value\]$/);
      if (match) {
        const delta = parseInt(match[1], 10);
        const value = formState[key];
        const displayValue = extractDisplayValue(value);

        if (displayValue === '' && value === '') {
          return;
        }

        const weightKey = `canvas_component_props[${componentId}][${fieldName}][${delta}][_weight]`;
        const weight = formState[weightKey]
          ? parseInt(formState[weightKey], 10)
          : delta;

        items.push({
          id: `${fieldName}-${delta}`,
          delta,
          displayValue,
          value,
          weight,
        });
      }
    }
  });

  return items.sort((a, b) => a.weight - b.weight);
}

export function getFieldNameFromAttributes(
  attributes: Record<string, any>,
  componentId: string,
): string {
  const name = attributes.name || '';
  const match = name.match(/\[([^\]]+)\]\[\d+\]/);
  if (match) {
    return match[1];
  }
  return '';
}
