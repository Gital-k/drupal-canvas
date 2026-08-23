import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { CodeComponentMetadataOperationUnsupportedError } from '../services/api';
import { bundledComponentMetadataSchema } from './component-metadata';
import {
  preflightCodeComponentPayloads,
  resolveTargetComponentMetadataSchema,
} from './target-component-metadata';

import type { BuiltComponent } from './build-project';

const siteUrl = 'https://example.com/';

describe('target component metadata', () => {
  let projectRoot: string;

  beforeEach(async () => {
    projectRoot = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-schema-'));
  });

  afterEach(async () => {
    await fs.rm(projectRoot, { recursive: true, force: true });
  });

  it('requests, caches, and revalidates a target schema with its ETag', async () => {
    const getComponentMetadataSchema = vi
      .fn()
      .mockResolvedValueOnce({
        status: 200,
        schema: bundledComponentMetadataSchema,
        etag: '"schema-1"',
      })
      .mockResolvedValueOnce({ status: 304, etag: '"schema-1"' });
    const api = {
      getSiteUrl: () => siteUrl,
      getComponentMetadataSchema,
    };

    const online = await resolveTargetComponentMetadataSchema(projectRoot, api);
    expect(online.remoteValidationAvailable).toBe(true);
    expect(online.warnings).toEqual([]);
    expect(
      await fs.readFile(path.join(projectRoot, '.canvas/.gitignore'), 'utf8'),
    ).toContain('schema-cache/');

    const revalidated = await resolveTargetComponentMetadataSchema(
      projectRoot,
      api,
    );
    expect(revalidated.remoteValidationAvailable).toBe(true);
    expect(getComponentMetadataSchema).toHaveBeenLastCalledWith('"schema-1"');
  });

  it('uses a cached target schema when the site is unavailable', async () => {
    const api = {
      getSiteUrl: () => siteUrl,
      getComponentMetadataSchema: vi.fn().mockResolvedValue({
        status: 200 as const,
        schema: bundledComponentMetadataSchema,
        etag: '"schema-1"',
      }),
    };
    await resolveTargetComponentMetadataSchema(projectRoot, api);
    api.getComponentMetadataSchema.mockRejectedValue(new Error('offline'));

    const context = await resolveTargetComponentMetadataSchema(
      projectRoot,
      api,
    );

    expect(context.remoteValidationAvailable).toBe(false);
    expect(context.warnings[0]).toContain('cached target');
    expect(context.warnings[0]).toContain('not authoritative');
  });

  it('uses the bundled schema without an authenticated target', async () => {
    const context = await resolveTargetComponentMetadataSchema(projectRoot);

    expect(context.schema).toBe(bundledComponentMetadataSchema);
    expect(context.remoteValidationAvailable).toBe(false);
    expect(context.warnings[0]).toContain('bundled');
  });

  it('collects remote payload failures before mutation', async () => {
    const components = [
      {
        componentName: 'First',
        componentPayload: { machineName: 'first' },
      },
      {
        componentName: 'Second',
        componentPayload: { machineName: 'second' },
      },
    ] as BuiltComponent[];
    const validateCodeComponentPayload = vi
      .fn()
      .mockRejectedValueOnce(new Error('Rejected first'))
      .mockResolvedValueOnce(undefined);

    const preflight = await preflightCodeComponentPayloads(components, {
      validateCodeComponentPayload,
    });

    expect(validateCodeComponentPayload).toHaveBeenCalledTimes(2);
    expect(preflight.results).toEqual([
      expect.objectContaining({ itemName: 'First', success: false }),
      expect.objectContaining({ itemName: 'Second', success: true }),
    ]);
  });

  it('falls back to save-time validation for an older target', async () => {
    const preflight = await preflightCodeComponentPayloads(
      [
        {
          componentName: 'Example',
          componentPayload: { machineName: 'example' },
        } as BuiltComponent,
      ],
      {
        validateCodeComponentPayload: vi
          .fn()
          .mockRejectedValue(
            new CodeComponentMetadataOperationUnsupportedError(),
          ),
      },
    );

    expect(preflight.results).toEqual([]);
    expect(preflight.warnings[0]).toContain('partial push');
  });
});
