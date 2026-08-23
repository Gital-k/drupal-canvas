import { createHash } from 'node:crypto';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import axios from 'axios';
import { createCanvasAjv } from '@drupal-canvas/json-schema-validation';

import {
  CodeComponentMetadataOperationUnsupportedError,
  CodeComponentMetadataValidationUnavailableError,
} from '../services/api';
import { bundledComponentMetadataSchema } from './component-metadata';

import type {
  AnySchema,
  ValidateFunction,
} from '@drupal-canvas/json-schema-validation';
import type {
  ApiService,
  ComponentMetadataSchemaResponse,
} from '../services/api';
import type { Result } from '../types/Result';
import type { BuiltComponent } from './build-project';

interface SchemaCacheMetadata {
  etag?: string;
  fetchedAt: string;
  siteUrl: string;
}

interface CachedSchema {
  metadata: SchemaCacheMetadata;
  schema: AnySchema;
}

export interface TargetComponentMetadataContext {
  remoteValidationAvailable: boolean;
  schema: AnySchema;
  validate: ValidateFunction;
  warnings: string[];
}

type MetadataSchemaApi = Pick<
  ApiService,
  'getComponentMetadataSchema' | 'getSiteUrl'
>;

function normalizeSiteUrl(siteUrl: string): string {
  const url = new URL(siteUrl);
  url.hash = '';
  url.search = '';
  url.pathname = url.pathname.replace(/\/+$/, '');
  return url.toString().replace(/\/$/, '');
}

function cacheDirectory(projectRoot: string, siteUrl: string): string {
  const siteHash = createHash('sha256')
    .update(siteUrl)
    .digest('hex')
    .slice(0, 24);
  return path.join(projectRoot, '.canvas', 'schema-cache', siteHash);
}

async function ensureCacheIgnored(projectRoot: string): Promise<void> {
  const canvasDirectory = path.join(projectRoot, '.canvas');
  const ignorePath = path.join(canvasDirectory, '.gitignore');
  await fs.mkdir(canvasDirectory, { recursive: true });
  const existing = await fs.readFile(ignorePath, 'utf8').catch(() => '');
  const lines = existing.split(/\r?\n/);
  if (lines.includes('schema-cache/')) return;
  const prefix = existing.length > 0 && !existing.endsWith('\n') ? '\n' : '';
  await fs.writeFile(ignorePath, `${existing}${prefix}schema-cache/\n`);
}

async function readCache(
  projectRoot: string,
  siteUrl: string,
): Promise<CachedSchema | undefined> {
  const directory = cacheDirectory(projectRoot, siteUrl);
  try {
    const [schemaSource, metadataSource] = await Promise.all([
      fs.readFile(path.join(directory, 'schema.json'), 'utf8'),
      fs.readFile(path.join(directory, 'metadata.json'), 'utf8'),
    ]);
    const metadata = JSON.parse(metadataSource) as SchemaCacheMetadata;
    if (metadata.siteUrl !== siteUrl) return undefined;
    return {
      schema: JSON.parse(schemaSource) as AnySchema,
      metadata,
    };
  } catch {
    return undefined;
  }
}

async function writeCache(
  projectRoot: string,
  siteUrl: string,
  response: ComponentMetadataSchemaResponse,
): Promise<void> {
  const directory = cacheDirectory(projectRoot, siteUrl);
  await ensureCacheIgnored(projectRoot);
  await fs.mkdir(directory, { recursive: true });
  const suffix = `${process.pid}-${Date.now()}.tmp`;
  const schemaTemporaryPath = path.join(directory, `schema.${suffix}`);
  const metadataTemporaryPath = path.join(directory, `metadata.${suffix}`);
  await Promise.all([
    fs.writeFile(
      schemaTemporaryPath,
      `${JSON.stringify(response.schema, null, 2)}\n`,
    ),
    fs.writeFile(
      metadataTemporaryPath,
      `${JSON.stringify(
        {
          siteUrl,
          etag: response.etag,
          fetchedAt: new Date().toISOString(),
        } satisfies SchemaCacheMetadata,
        null,
        2,
      )}\n`,
    ),
  ]);
  await Promise.all([
    fs.rename(schemaTemporaryPath, path.join(directory, 'schema.json')),
    fs.rename(metadataTemporaryPath, path.join(directory, 'metadata.json')),
  ]);
}

function compileSchema(schema: AnySchema): ValidateFunction {
  return createCanvasAjv({ allErrors: true }).compile(schema);
}

function offlineWarning(reason: string, source: 'cached target' | 'bundled') {
  return `${reason} Using the ${source} metadata schema. Validation is offline and not authoritative.`;
}

export async function preflightCodeComponentPayloads(
  builtComponents: BuiltComponent[],
  apiService: Pick<ApiService, 'validateCodeComponentPayload'>,
): Promise<{ results: Result[]; warnings: string[] }> {
  const results: Result[] = [];
  for (const component of builtComponents) {
    try {
      await apiService.validateCodeComponentPayload(component.componentPayload);
      results.push({ itemName: component.componentName, success: true });
    } catch (error) {
      if (
        error instanceof CodeComponentMetadataOperationUnsupportedError ||
        error instanceof CodeComponentMetadataValidationUnavailableError
      ) {
        return {
          results: [],
          warnings: [
            'Target metadata validation is unavailable. Push will use per-component save-time validation, so a partial push remains possible.',
          ],
        };
      }
      results.push({
        itemName: component.componentName,
        success: false,
        details: [
          {
            heading: 'Target site validation',
            content: error instanceof Error ? error.message : String(error),
          },
        ],
      });
    }
  }
  return { results, warnings: [] };
}

export async function resolveTargetComponentMetadataSchema(
  projectRoot: string,
  apiService?: MetadataSchemaApi,
): Promise<TargetComponentMetadataContext> {
  if (!apiService) {
    return {
      schema: bundledComponentMetadataSchema,
      validate: compileSchema(bundledComponentMetadataSchema),
      remoteValidationAvailable: false,
      warnings: [
        offlineWarning('No authenticated target site is available.', 'bundled'),
      ],
    };
  }

  const siteUrl = normalizeSiteUrl(apiService.getSiteUrl());
  let cached = await readCache(projectRoot, siteUrl);
  let cachedValidator: ValidateFunction | undefined;
  if (cached) {
    try {
      cachedValidator = compileSchema(cached.schema);
    } catch {
      cached = undefined;
    }
  }
  try {
    let response = await apiService.getComponentMetadataSchema(
      cached?.metadata.etag,
    );
    if (response.status === 304 && !cached) {
      response = await apiService.getComponentMetadataSchema();
    }
    if (response.status === 304 && cached) {
      return {
        schema: cached.schema,
        validate: cachedValidator as ValidateFunction,
        remoteValidationAvailable: true,
        warnings: [],
      };
    }
    if (response.status === 200 && response.schema) {
      const schema = response.schema as AnySchema;
      const validate = compileSchema(schema);
      const warnings: string[] = [];
      try {
        await writeCache(projectRoot, siteUrl, response);
      } catch {
        warnings.push(
          'The target metadata schema was retrieved but could not be cached.',
        );
      }
      return {
        schema,
        validate,
        remoteValidationAvailable: true,
        warnings,
      };
    }
    throw new Error(
      'The target returned an unusable metadata schema response.',
    );
  } catch (error) {
    const source = cached ? 'cached target' : 'bundled';
    const schema = cached?.schema ?? bundledComponentMetadataSchema;
    let reason = 'The target metadata schema could not be retrieved.';
    if (axios.isAxiosError(error)) {
      if (error.response?.status === 404) {
        reason =
          'The target site does not provide metadata preflight operations.';
      } else if ([401, 403].includes(error.response?.status ?? 0)) {
        reason =
          'Authentication or authorization for the target metadata schema failed.';
      }
    }
    return {
      schema,
      validate: compileSchema(schema),
      remoteValidationAvailable: false,
      warnings: [offlineWarning(reason, source)],
    };
  }
}
