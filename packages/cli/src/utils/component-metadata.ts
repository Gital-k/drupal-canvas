import { promises as fs } from 'node:fs';
import { LineCounter, parseDocument } from 'yaml';
import { createCanvasAjv } from '@drupal-canvas/json-schema-validation';

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

export function validateParsedComponentMetadata(
  parsed: ParsedComponentMetadata,
  schema: AnySchema = bundledComponentMetadataSchema,
): ComponentMetadataDiagnostic[] {
  const validate = getValidator(schema);
  if (validate(parsed.value)) return [];

  return (validate.errors ?? []).map((error) => {
    const parts = getErrorPath(error);
    const node = locateNode(parsed.document, parts);
    const position = node?.range
      ? parsed.lineCounter.linePos(node.range[0])
      : undefined;
    return {
      path: diagnosticPath(parts),
      message: formatErrorMessage(error),
      line: position?.line,
      column: position?.col,
    };
  });
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
          const position = error.pos[0]
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
  schema: AnySchema = bundledComponentMetadataSchema,
): Promise<Metadata> {
  const parsed = await parseComponentMetadata(filePath);
  const diagnostics = validateParsedComponentMetadata(parsed, schema);
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
