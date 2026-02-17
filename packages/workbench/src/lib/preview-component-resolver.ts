import type { ComponentType } from 'react';

interface ResolverResult {
  component: ComponentType | null;
  reason: string | null;
}

function isComponentCandidate(value: unknown): value is ComponentType {
  if (typeof value === 'function') {
    return true;
  }

  if (typeof value === 'object' && value !== null) {
    // ForwardRef, memo, and lazy components are React objects with $$typeof.
    return '$$typeof' in value;
  }

  return false;
}

function pickFromObject(
  objectValue: Record<string, unknown>,
): ComponentType | null {
  const directKeys = ['Component', 'component', 'default'];

  for (const key of directKeys) {
    const candidate = objectValue[key];
    if (isComponentCandidate(candidate)) {
      return candidate;
    }
  }

  return null;
}

export function resolvePreviewComponent(
  moduleRecord: Record<string, unknown>,
): ResolverResult {
  const defaultExport = moduleRecord.default;
  if (isComponentCandidate(defaultExport)) {
    return { component: defaultExport, reason: null };
  }

  if (typeof defaultExport === 'object' && defaultExport !== null) {
    const nestedDefaultComponent = pickFromObject(
      defaultExport as Record<string, unknown>,
    );
    if (nestedDefaultComponent) {
      return { component: nestedDefaultComponent, reason: null };
    }
  }

  const namedExport = moduleRecord.Component;
  if (isComponentCandidate(namedExport)) {
    return { component: namedExport, reason: null };
  }

  const probableNamedExport = Object.entries(moduleRecord).find(
    ([name, value]) => {
      const startsWithUpper = /^[A-Z]/.test(name);
      return startsWithUpper && isComponentCandidate(value);
    },
  );

  if (probableNamedExport && isComponentCandidate(probableNamedExport[1])) {
    return { component: probableNamedExport[1], reason: null };
  }

  const exportNames = Object.keys(moduleRecord);
  return {
    component: null,
    reason: `No renderable React export found. Checked default, default.Component, named Component, and PascalCase named exports. Available exports: ${exportNames.join(', ') || '(none)'}.`,
  };
}
