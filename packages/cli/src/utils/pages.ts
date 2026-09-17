import {
  CANVAS_COLOR_REF_PREFIX,
  COLOR_PROP_SCHEMA_REF,
  serializeColorValue,
} from '@drupal-canvas/discovery';

import { resolvedComponentTreeToAuthoredElementMap } from './authored-elements';
import { isRecord } from './utils';

import type {
  ColorTokenValue,
  ComponentMetadata,
} from '@drupal-canvas/discovery';
import type { AuthoredSpecElementMap } from 'drupal-canvas/json-render-utils';
import type { Page } from '../types/Page';

interface PageToAuthoredSpecOptions {
  componentMetadata?: ComponentMetadata[];
}

function isColorTokenValue(value: unknown): value is ColorTokenValue {
  if (!isRecord(value)) {
    return false;
  }
  const components = value.components;
  return (
    (value.colorSpace === 'srgb' || value.colorSpace === 'hsl') &&
    Array.isArray(components) &&
    components.length === 3 &&
    components.every(
      (component) =>
        typeof component === 'number' && Number.isFinite(component),
    )
  );
}

function collapseResolvedColorPropValue(value: unknown): unknown {
  const resolvedColor = isRecord(value) ? value : null;
  const token = resolvedColor?.value;
  if (!isColorTokenValue(token)) {
    return value;
  }

  const cssVariableValue = resolvedColor?.cssVariable;
  const cssVariable =
    typeof cssVariableValue === 'string' ? cssVariableValue : null;
  if (cssVariable && cssVariable.startsWith('--') && cssVariable.length > 2) {
    return `${CANVAS_COLOR_REF_PREFIX}${cssVariable.slice(2)}`;
  }

  if (token.colorSpace === 'srgb' && typeof token.hex === 'string') {
    const normalizedHex = /^#[0-9a-fA-F]{6}$/.test(token.hex)
      ? token.hex
      : null;
    if (normalizedHex) {
      if (token.alpha == null || token.alpha === 1) {
        return normalizedHex;
      }
      const alphaByte = Math.min(
        255,
        Math.max(0, Math.round(token.alpha * 255)),
      );
      const alphaHex = alphaByte.toString(16).padStart(2, '0');
      return `${normalizedHex}${alphaHex}`;
    }
  }

  return serializeColorValue(token);
}

function collapseColorPropsInElements(
  elements: AuthoredSpecElementMap,
  componentMetadata: ComponentMetadata[],
): AuthoredSpecElementMap {
  const colorPropNamesByType = new Map<string, Set<string>>();

  for (const entry of componentMetadata) {
    const colorProps = new Set<string>();
    for (const [propName, schema] of Object.entries(
      entry.props.properties ?? {},
    )) {
      if (schema.$ref === COLOR_PROP_SCHEMA_REF) {
        colorProps.add(propName);
      }
    }
    if (colorProps.size > 0) {
      colorPropNamesByType.set(`js.${entry.machineName}`, colorProps);
    }
  }

  if (colorPropNamesByType.size === 0) {
    return elements;
  }

  let hasUpdates = false;
  const nextElements: AuthoredSpecElementMap = {};

  for (const [elementId, element] of Object.entries(elements)) {
    const colorProps = colorPropNamesByType.get(element.type);
    if (!colorProps || !isRecord(element.props)) {
      nextElements[elementId] = element;
      continue;
    }

    let propsChanged = false;
    const nextProps: Record<string, unknown> = {
      ...(element.props as Record<string, unknown>),
    };

    for (const propName of colorProps) {
      if (!(propName in nextProps)) {
        continue;
      }
      const currentValue = nextProps[propName];
      const nextValue = collapseResolvedColorPropValue(currentValue);
      if (nextValue !== currentValue) {
        nextProps[propName] = nextValue;
        propsChanged = true;
      }
    }

    if (propsChanged) {
      hasUpdates = true;
      nextElements[elementId] = {
        ...element,
        props: nextProps,
      };
      continue;
    }

    nextElements[elementId] = element;
  }

  return hasUpdates ? nextElements : elements;
}

export function pageToAuthoredSpec(
  page: Page,
  options: PageToAuthoredSpecOptions = {},
): Record<string, unknown> {
  const meta: Record<string, unknown> = {
    uuid: page.uuid,
    title: page.title,
    path: page.path,
    description: page.description,
    ...(page.pageVariant ? { pageVariant: page.pageVariant } : {}),
  };

  if (page.components.length === 0) {
    return { ...meta, elements: {} };
  }

  const baseElements = resolvedComponentTreeToAuthoredElementMap(
    page.components,
  );
  const elements =
    options.componentMetadata && options.componentMetadata.length > 0
      ? collapseColorPropsInElements(baseElements, options.componentMetadata)
      : baseElements;

  return { ...meta, elements };
}
