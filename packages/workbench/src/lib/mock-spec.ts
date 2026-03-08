import { defineComponentCatalog } from 'drupal-canvas/json-render-utils';

import type { Spec } from '@json-render/core';
import type { PreviewWarning } from './preview-contract';

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null;
}

function isSpec(value: unknown): value is Spec {
  return (
    isRecord(value) &&
    typeof value.root === 'string' &&
    isRecord(value.elements)
  );
}

function toComponentId(componentName: string): string {
  return `js.${componentName}`;
}

function toUnderscoreComponentId(componentName: string): string {
  return `js.${componentName.replace(/-/g, '_')}`;
}

function buildComponentAliasMap(componentNames: string[]): Map<string, string> {
  const aliasMap = new Map<string, string>();

  for (const componentName of componentNames) {
    const canonicalId = toComponentId(componentName);
    aliasMap.set(componentName, canonicalId);
    aliasMap.set(canonicalId, canonicalId);
    aliasMap.set(toUnderscoreComponentId(componentName), canonicalId);
  }

  return aliasMap;
}

function normalizeSpecForCatalogValidation(
  spec: Spec,
  aliasMap: Map<string, string>,
): Spec {
  const normalizedElements = Object.fromEntries(
    Object.entries(spec.elements).map(([key, element]) => {
      if (!isRecord(element)) {
        return [key, element];
      }

      const normalizedType =
        typeof element.type === 'string'
          ? (aliasMap.get(element.type) ?? element.type)
          : element.type;

      return [
        key,
        {
          ...element,
          type: normalizedType,
          props: element.props ?? {},
          children: element.children ?? [],
          slots: element.slots ?? {},
        },
      ];
    }),
  );

  return {
    ...spec,
    elements: normalizedElements as Spec['elements'],
  };
}

function toValidationErrorMessage(validationResult: {
  error?: { issues?: Array<{ path?: unknown[]; message?: string }> };
}): string {
  const firstIssue = validationResult.error?.issues?.[0];
  if (!firstIssue) {
    return 'Invalid json-render spec.';
  }

  const issuePath = Array.isArray(firstIssue.path)
    ? firstIssue.path.join('.')
    : '';
  if (!issuePath) {
    return firstIssue.message ?? 'Invalid json-render spec.';
  }

  return `${firstIssue.message ?? 'Invalid value.'} (${issuePath})`;
}

export interface MockSpecValidationOptions {
  componentNames?: string[];
}

export function validateMockSpecArray(
  value: unknown,
  sourcePath: string,
  options: MockSpecValidationOptions = {},
): {
  specs: Spec[];
  warnings: PreviewWarning[];
} {
  if (!Array.isArray(value)) {
    return {
      specs: [],
      warnings: [
        {
          code: 'invalid_mock_spec_array',
          message: `Mock file must contain an array of json-render specs: ${sourcePath}`,
          path: sourcePath,
        },
      ],
    };
  }

  const specs: Spec[] = [];
  const warnings: PreviewWarning[] = [];
  const componentNames = options.componentNames ?? [];
  const aliasMap = buildComponentAliasMap(componentNames);
  const validationCatalog =
    componentNames.length > 0
      ? defineComponentCatalog(
          componentNames.map((componentName) => ({
            name: componentName,
            machineName: componentName,
            status: true,
            props: {
              properties: {},
            },
            required: [],
            slots: {},
          })),
        )
      : null;

  value.forEach((entry, index) => {
    if (isSpec(entry)) {
      if (validationCatalog) {
        const normalizedSpec = normalizeSpecForCatalogValidation(
          entry,
          aliasMap,
        );
        const validationResult = validationCatalog.validate(normalizedSpec);
        if (!validationResult.success) {
          warnings.push({
            code: 'invalid_mock_spec_entry',
            message: `Mock spec entry at index ${index} is invalid in ${sourcePath}: ${toValidationErrorMessage(validationResult)}`,
            path: sourcePath,
          });
          return;
        }
      }

      specs.push(entry);
      return;
    }

    warnings.push({
      code: 'invalid_mock_spec_entry',
      message: `Mock spec entry at index ${index} is invalid in ${sourcePath}.`,
      path: sourcePath,
    });
  });

  return { specs, warnings };
}
