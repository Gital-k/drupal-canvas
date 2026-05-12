import fs from 'fs/promises';
import os from 'os';
import path from 'path';
import { describe, expect, it } from 'vitest';

import { validateRegions } from './validate-region';

import type {
  DiscoveredRegion,
  DiscoveryResult,
} from '@drupal-canvas/discovery';

function makeDiscoveryResult(
  tmpDir: string,
  regions: DiscoveredRegion[],
): DiscoveryResult {
  return {
    componentRoot: tmpDir,
    projectRoot: tmpDir,
    components: [],
    pages: [],
    regions,
    warnings: [],
    stats: { scannedFiles: regions.length, ignoredFiles: 0 },
  };
}

async function writeRegion(tmpDir: string, fileName: string, spec: unknown) {
  const regionPath = path.join(tmpDir, fileName);
  await fs.writeFile(regionPath, JSON.stringify(spec), 'utf-8');
  return regionPath;
}

describe('validateRegions', () => {
  it('accepts region specs with no elements', async () => {
    const tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-region-'));
    try {
      const regionPath = await writeRegion(tmpDir, 'header.json', {
        theme: 'olivero',
        region: 'header',
        elements: {},
      });
      const discoveryResult = makeDiscoveryResult(tmpDir, [
        {
          theme: 'olivero',
          region: 'header',
          path: regionPath,
          relativePath: 'regions/header.json',
        },
      ]);

      await expect(validateRegions(discoveryResult)).resolves.toEqual({
        results: [{ itemName: 'olivero.header', success: true }],
      });
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('reports missing required fields', async () => {
    const tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-region-'));
    try {
      const regionPath = await writeRegion(tmpDir, 'header.json', {
        theme: 'olivero',
        elements: {},
      });
      const discoveryResult = makeDiscoveryResult(tmpDir, [
        {
          theme: 'olivero',
          region: 'header',
          path: regionPath,
          relativePath: 'regions/header.json',
        },
      ]);

      const { results } = await validateRegions(discoveryResult);
      expect(results).toHaveLength(1);
      expect(results[0].success).toBe(false);
      expect(
        results[0].details?.some((d) => d.content.includes('region')),
      ).toBe(true);
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('rejects unexpected top-level keys', async () => {
    const tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-region-'));
    try {
      const regionPath = await writeRegion(tmpDir, 'header.json', {
        theme: 'olivero',
        region: 'header',
        elements: {},
        title: 'Not allowed on regions',
      });
      const discoveryResult = makeDiscoveryResult(tmpDir, [
        {
          theme: 'olivero',
          region: 'header',
          path: regionPath,
          relativePath: 'regions/header.json',
        },
      ]);

      const { results } = await validateRegions(discoveryResult);
      expect(results[0].success).toBe(false);
      expect(
        results[0].details?.some((d) => d.content.includes("'title'")),
      ).toBe(true);
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('rejects canvas:component-tree as an element key', async () => {
    const tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-region-'));
    try {
      const regionPath = await writeRegion(tmpDir, 'header.json', {
        theme: 'olivero',
        region: 'header',
        elements: {
          'canvas:component-tree': { type: 'canvas:component-tree' },
        },
      });
      const discoveryResult = makeDiscoveryResult(tmpDir, [
        {
          theme: 'olivero',
          region: 'header',
          path: regionPath,
          relativePath: 'regions/header.json',
        },
      ]);

      const { results } = await validateRegions(discoveryResult);
      expect(results[0].success).toBe(false);
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });

  it('reports invalid JSON with the file name as heading', async () => {
    const tmpDir = await fs.mkdtemp(path.join(os.tmpdir(), 'canvas-region-'));
    try {
      const regionPath = path.join(tmpDir, 'header.json');
      await fs.writeFile(regionPath, '{ not valid json', 'utf-8');
      const discoveryResult = makeDiscoveryResult(tmpDir, [
        {
          theme: 'olivero',
          region: 'header',
          path: regionPath,
          relativePath: 'regions/header.json',
        },
      ]);

      const { results } = await validateRegions(discoveryResult);
      expect(results[0].success).toBe(false);
      expect(results[0].details?.[0].heading).toBe('header.json');
    } finally {
      await fs.rm(tmpDir, { recursive: true, force: true });
    }
  });
});
