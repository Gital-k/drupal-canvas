import { promises as fs } from 'node:fs';
import { LineCounter, parseDocument } from 'yaml';
import {
  createCanvasAjv,
  MissingRefError,
} from '@drupal-canvas/json-schema-validation';

import bundledComponentMetadataSchema from '../../../../component-metadata.schema.json';

import type {
  AnySchema,
  ErrorObject,
  ValidateFunction,
} from '@drupal-canvas/json-schema-validation';
import type { Document, Node } from 'yaml';
import type { Metadata } from '../types/Metadata';

export interface ParsedComponentMetadata {
  document: Document.Parsed;
  lineCounter: LineCounter;
  source: string;
  value: unknown;
}

export interface ComponentMetadataDiagnostic {
  column?: number;
  line?: number;
  message: string;
  path: string;
}

const validatorCache = new WeakMap<object, ValidateFunction>();

function getValidator(schema: AnySchema) {
  if (typeof schema !== 'object' || schema === null) {
    return createCanvasAjv({ allErrors: true }).compile(schema);
  }
  const cached = validatorCache.get(schema);
  if (cached) return cached;
  const validate = createCanvasAjv({ allErrors: true }).compile(schema);
  validatorCache.set(schema, validate);
  return validate;
}

function decodeJsonPointer(instancePath: string): string[] {
  if (!instancePath) return [];
  return instancePath
    .slice(1)
    .split('/')
    .map((part) => part.replaceAll('~1', '/').replaceAll('~0', '~'));
}

function diagnosticPath(parts: string[]): string {
  return parts.length === 0
    ? '$'
    : `$${parts
        .map((part) =>
          /^\d+$/.test(part) ? `[${part}]` : `.${part.replaceAll('.', '\\.')}`,
        )
        .join('')}`;
}

function getErrorPath(error: ErrorObject): string[] {
  const parts = decodeJsonPointer(error.instancePath);
  if (error.keyword === 'additionalProperties') {
    const property = error.params.additionalProperty;
    if (typeof property === 'string') parts.push(property);
  }
  return parts;
}

function locateNode(document: Document.Parsed, parts: string[]): Node | null {
  let currentParts = parts;
  while (currentParts.length > 0) {
    const node = document.getIn(currentParts, true);
    if (node && typeof node === 'object' && 'range' in node) {
      return node as Node;
    }
    currentParts = currentParts.slice(0, -1);
  }
  return document.contents;
}

function formatErrorMessage(error: ErrorObject): string {
  if (error.keyword === 'additionalProperties') {
    return `must not contain unknown property '${String(error.params.additionalProperty)}'`;
  }
  if (error.keyword === 'required') {
    return `must contain required property '${String(error.params.missingProperty)}'`;
  }
  return error.message ?? 'is invalid';
}

function diagnosticFromError(
  parsed: ParsedComponentMetadata,
  error: ErrorObject,
  prefix: string[] = [],
): ComponentMetadataDiagnostic {
  const parts = [...prefix, ...getErrorPath(error)];
  const node = locateNode(parsed.document, parts);
  const position = node?.range
    ? parsed.lineCounter.linePos(node.range[0])
    : undefined;
  const property = parts.at(-1);
  const message =
    error.keyword === 'not' &&
    ['x-allowed-entity-type-id', 'x-allowed-bundle'].includes(property ?? '')
      ? `must not contain projected property '${property}'`
      : formatErrorMessage(error);
  return {
    path: diagnosticPath(parts),
    message,
    line: position?.line,
    column: position?.col,
  };
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function validatePropDefinitions(
  parsed: ParsedComponentMetadata,
): ComponentMetadataDiagnostic[] {
  if (!isRecord(parsed.value) || !isRecord(parsed.value.props)) return [];
  const properties = parsed.value.props.properties;
  if (!isRecord(properties)) return [];

  const diagnostics: ComponentMetadataDiagnostic[] = [];
  for (const [propName, propDefinition] of Object.entries(properties)) {
    if (!isRecord(propDefinition)) continue;

    // Target extensions may add JSON Schema vocabulary unknown to the CLI.
    const ajv = createCanvasAjv({ allErrors: true, strict: false });
    let validate: ValidateFunction;
    try {
      validate = ajv.compile(propDefinition);
    } catch (error) {
      // Target and extension JSON Schema references cannot necessarily be
      // resolved offline. Drupal validates them authoritatively.
      if (error instanceof MissingRefError) continue;
      diagnostics.push({
        path: diagnosticPath(['props', 'properties', propName]),
        message: error instanceof Error ? error.message : String(error),
      });
      continue;
    }

    if (!Array.isArray(propDefinition.examples)) continue;
    const example = propDefinition.examples[0];
    if (example === undefined || validate(example)) continue;
    const prefix = ['props', 'properties', propName, 'examples', '0'];
    diagnostics.push(
      ...(validate.errors ?? []).map((error) =>
        diagnosticFromError(parsed, error, prefix),
      ),
    );
  }
  return diagnostics;
}

export function validateParsedComponentMetadata(
  parsed: ParsedComponentMetadata,
): ComponentMetadataDiagnostic[] {
  const validate = getValidator(bundledComponentMetadataSchema);
  if (!validate(parsed.value)) {
    return (validate.errors ?? []).map((error) =>
      diagnosticFromError(parsed, error),
    );
  }
  return validatePropDefinitions(parsed);
}

export async function parseComponentMetadata(
  filePath: string,
): Promise<ParsedComponentMetadata> {
  const source = await fs.readFile(filePath, 'utf8');
  const lineCounter = new LineCounter();
  const document = parseDocument(source, {
    lineCounter,
    prettyErrors: false,
    uniqueKeys: true,
  });
  if (document.errors.length > 0) {
    throw new Error(
      document.errors
        .map((error) => {
          const position =
            error.pos[0] !== undefined
              ? lineCounter.linePos(error.pos[0])
              : undefined;
          const location = position
            ? `Line ${position.line}, Column ${position.col}: `
            : '';
          return `${location}${error.message}`;
        })
        .join('\n'),
    );
  }
  return {
    document,
    lineCounter,
    source,
    value: document.toJS(),
  };
}

export function normalizeComponentMetadata(value: unknown): Metadata {
  const raw = value as Partial<Metadata>;
  return {
    name: raw.name as string,
    machineName: raw.machineName as string,
    status: raw.status ?? true,
    required: raw.required ?? [],
    props: raw.props ?? { properties: {} },
    slots: raw.slots ?? {},
    dataDependencies: raw.dataDependencies ?? {},
  };
}

export async function readValidatedComponentMetadata(
  filePath: string,
): Promise<Metadata> {
  const parsed = await parseComponentMetadata(filePath);
  const diagnostics = validateParsedComponentMetadata(parsed);
  if (diagnostics.length > 0) {
    throw new Error(
      diagnostics
        .map((diagnostic) => {
          const location = diagnostic.line
            ? `Line ${diagnostic.line}, Column ${diagnostic.column}: `
            : `${diagnostic.path}: `;
          return `${location}${diagnostic.path} ${diagnostic.message}`;
        })
        .join('\n'),
    );
  }
  return normalizeComponentMetadata(parsed.value);
}

export { bundledComponentMetadataSchema };
