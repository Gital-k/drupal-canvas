import {
  isNonEmptyString,
  isRecord,
  normalizeElementMapProps,
  parseElementMap,
  validateElementMapSlotReferences,
  validateSpecComponentTypes,
} from './authored-spec-utils';

import type { Spec } from '@json-render/core';
import type { AuthoredSpecElementMap } from './authored-spec-utils';
import type { PageSpecIssue } from './page-spec';

export interface AuthoredRegionSpec {
  uuid?: string;
  theme: string;
  region: string;
  status?: boolean;
  elements: AuthoredSpecElementMap;
}

export interface NormalizedRegionSpec {
  /** Composite ID `{theme}.{region}` derived from the spec. */
  id: string;
  theme: string;
  region: string;
  spec: Spec;
}

function getTopLevelElementIds(elements: AuthoredSpecElementMap): string[] {
  const referenced = new Set<string>();
  Object.values(elements).forEach((element) => {
    Object.values(element.slots ?? {}).forEach((slotItems) => {
      slotItems.forEach((id) => referenced.add(id));
    });
  });
  return Object.keys(elements).filter((id) => !referenced.has(id));
}

export function normalizeRegionSpec(
  region: AuthoredRegionSpec,
): NormalizedRegionSpec {
  const topLevelElementIds = getTopLevelElementIds(region.elements);
  const elements = normalizeElementMapProps(region.elements);

  return {
    id: `${region.theme}.${region.region}`,
    theme: region.theme,
    region: region.region,
    spec: {
      root: 'canvas:component-tree',
      elements: {
        ...elements,
        'canvas:component-tree': {
          type: 'canvas:component-tree',
          props: {},
          children: topLevelElementIds,
        },
      } as Spec['elements'],
    },
  };
}

export function parseRegionSpec(
  value: unknown,
  sourcePath: string,
  options: { componentNames?: string[] } = {},
): {
  region: NormalizedRegionSpec | null;
  issues: PageSpecIssue[];
} {
  if (!isRecord(value)) {
    return {
      region: null,
      issues: [
        {
          code: 'invalid_page_spec',
          message: `Region file must contain an object: ${sourcePath}`,
          path: sourcePath,
        },
      ],
    };
  }

  const issues: PageSpecIssue[] = [];
  const allowedTopLevelKeys = new Set([
    '$schema',
    'uuid',
    'theme',
    'region',
    'status',
    'elements',
  ]);
  const unexpectedTopLevelKeys = Object.keys(value).filter(
    (key) => !allowedTopLevelKeys.has(key),
  );
  if (unexpectedTopLevelKeys.length > 0) {
    issues.push({
      code: 'invalid_page_spec',
      message: `Region file contains unexpected top-level keys in ${sourcePath}: ${unexpectedTopLevelKeys.join(', ')}.`,
      path: sourcePath,
    });
  }

  if (!isNonEmptyString(value.theme)) {
    issues.push({
      code: 'invalid_page_spec',
      message: `Region file must include a non-empty theme: ${sourcePath}`,
      path: `${sourcePath}#theme`,
    });
  }
  if (!isNonEmptyString(value.region)) {
    issues.push({
      code: 'invalid_page_spec',
      message: `Region file must include a non-empty region: ${sourcePath}`,
      path: `${sourcePath}#region`,
    });
  }

  const parsedElements = parseElementMap(
    value.elements,
    `${sourcePath}#elements`,
  );
  parsedElements.issues.forEach((issue) => {
    issues.push({
      code: 'invalid_page_spec',
      message: issue.message,
      path: issue.path,
    });
  });

  if (
    parsedElements.elements &&
    'canvas:component-tree' in parsedElements.elements
  ) {
    issues.push({
      code: 'invalid_page_spec',
      message: `Region files must not define canvas:component-tree directly: ${sourcePath}`,
      path: `${sourcePath}#elements.canvas:component-tree`,
    });
  }

  if (parsedElements.elements) {
    validateElementMapSlotReferences(
      parsedElements.elements,
      `${sourcePath}#elements`,
    ).forEach((issue) => {
      issues.push({
        code: 'invalid_page_spec',
        message: issue.message,
        path: issue.path,
      });
    });
  }

  if (
    issues.length > 0 ||
    !parsedElements.elements ||
    !isNonEmptyString(value.theme) ||
    !isNonEmptyString(value.region)
  ) {
    return { region: null, issues };
  }

  const region = normalizeRegionSpec({
    theme: value.theme,
    region: value.region,
    elements: parsedElements.elements,
  });

  const validationError = validateSpecComponentTypes(region.spec, {
    componentNames: options.componentNames,
    additionalComponentNames: ['canvas:component-tree'],
  });
  if (validationError) {
    issues.push({
      code: 'invalid_page_spec',
      message: `Region spec is invalid in ${sourcePath}: ${validationError}`,
      path: sourcePath,
    });
    return { region: null, issues };
  }

  return { region, issues };
}
