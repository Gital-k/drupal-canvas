import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  discoverCanvasProject,
  resolveCanvasConfig,
} from '@drupal-canvas/discovery';

import { buildCanvasProject } from './build-project';

vi.mock('tailwindcss-in-browser', () => ({
  compilePartialCss: vi.fn(async (source: string) => source),
}));

const currentDir = path.dirname(fileURLToPath(import.meta.url));
const packageRoot = path.resolve(currentDir, '../..');
const repositoryRoot = path.resolve(packageRoot, '../..');
const fixtureRoot = path.join(packageRoot, 'test-fixtures/build-projects');
const temporaryDirectories: string[] = [];

async function copyFixtureProject(fixtureName: string): Promise<string> {
  const temporaryRoot = await fs.mkdtemp(
    path.join(os.tmpdir(), `canvas-build-${fixtureName}-`),
  );
  const projectRoot = await fs.realpath(temporaryRoot);
  temporaryDirectories.push(projectRoot);

  await fs.cp(path.join(fixtureRoot, fixtureName), projectRoot, {
    recursive: true,
  });
  await fs.symlink(
    path.join(repositoryRoot, 'node_modules'),
    path.join(projectRoot, 'node_modules'),
    'dir',
  );

  return projectRoot;
}

async function withWorkingDirectory<T>(
  directory: string,
  callback: () => Promise<T>,
): Promise<T> {
  const previousDirectory = process.cwd();
  process.chdir(directory);

  try {
    return await callback();
  } finally {
    process.chdir(previousDirectory);
  }
}

async function buildFixtureProject(fixtureName: string) {
  const projectRoot = await copyFixtureProject(fixtureName);
  const config = resolveCanvasConfig({ hostRoot: projectRoot });
  const componentRoot = path.resolve(projectRoot, config.componentDir);

  const discoveryResult = await discoverCanvasProject({
    componentRoot,
    projectRoot,
  });

  const buildResult = await withWorkingDirectory(projectRoot, () =>
    buildCanvasProject({
      projectRoot,
      componentDir: config.componentDir,
      aliasBaseDir: config.aliasBaseDir,
      outputDir: 'dist',
      discoveryResult,
      cleanOutputDir: true,
      buildTailwind: false,
      requireJsEntries: true,
      useLocalGlobalCss: true,
    }),
  );

  return { projectRoot, config, discoveryResult, buildResult };
}

async function readDistFile(
  projectRoot: string,
  relativePath: string,
): Promise<string> {
  return fs.readFile(path.join(projectRoot, 'dist', relativePath), 'utf-8');
}

async function expectManifestFilesExist(
  projectRoot: string,
  entries: Record<string, string>,
): Promise<void> {
  for (const outputPath of Object.values(entries)) {
    await expect(
      fs.access(path.join(projectRoot, 'dist', outputPath.replace('./', ''))),
    ).resolves.toBeUndefined();
  }
}

afterEach(async () => {
  await Promise.all(
    temporaryDirectories.map((directory) =>
      fs.rm(directory, { recursive: true, force: true }),
    ),
  );
  temporaryDirectories.length = 0;
});

describe('buildCanvasProject fixture projects', () => {
  it('builds a default project with component dependencies, assets, local imports, and vendor artifacts', async () => {
    const { projectRoot, discoveryResult, buildResult } =
      await buildFixtureProject('default-graph');

    expect(
      discoveryResult.components.map((component) => component.name),
    ).toEqual(['button', 'card']);
    expect(buildResult.componentResults).toEqual([
      expect.objectContaining({ itemName: 'button', success: true }),
      expect.objectContaining({ itemName: 'card', success: true }),
    ]);

    const card = buildResult.builtComponents.find(
      (component) => component.machineName === 'card',
    );
    expect(card).toBeDefined();
    expect(card?.importedJsComponents).toEqual(['button']);
    expect(card?.componentPayload.dataDependencies).toEqual({
      drupalSettings: ['v0.baseUrl', 'v0.branding'],
    });

    const cardJs = await readDistFile(projectRoot, 'components/card/index.js');
    expect(cardJs).toContain(
      'import.meta.resolve("@/components/card/hero.webp")',
    );
    expect(cardJs).toContain('import.meta.resolve("@/lib/banner.jpg")');
    expect(cardJs).toContain('from "@/components/button"');
    expect(cardJs).toContain('from "@/lib/format"');

    await expect(
      fs.access(path.join(projectRoot, 'dist/components/card/index.css')),
    ).resolves.toBeUndefined();
    await expect(
      fs.access(path.join(projectRoot, 'dist/components/card/component.yml')),
    ).resolves.toBeUndefined();

    expect(buildResult.manifest.vendor).toEqual({
      'lodash-es': expect.stringMatching(/^\.\/vendor\/lodash-es-[\w-]+\.js$/),
    });
    expect(buildResult.manifest.local).toEqual({
      '@/components/card/hero.webp': expect.stringMatching(
        /^\.\/local\/hero-[\w-]+\.webp$/,
      ),
      '@/lib/banner.jpg': expect.stringMatching(
        /^\.\/local\/banner-[\w-]+\.jpg$/,
      ),
      '@/lib/format': expect.stringMatching(/^\.\/local\/format-[\w-]+\.js$/),
    });
    expect(buildResult.manifest.shared).not.toContain(
      buildResult.manifest.local['@/components/card/hero.webp'],
    );
    expect(buildResult.manifest.shared).not.toContain(
      buildResult.manifest.local['@/lib/banner.jpg'],
    );
    await expectManifestFilesExist(projectRoot, buildResult.manifest.vendor);
    await expectManifestFilesExist(projectRoot, buildResult.manifest.local);
  });

  it('builds custom component and alias roots with named component files', async () => {
    const { projectRoot, config, discoveryResult, buildResult } =
      await buildFixtureProject('custom-roots');

    expect(config.componentDir).toBe('packages/widgets');
    expect(config.aliasBaseDir).toBe('app');
    expect(
      discoveryResult.components.map((component) => component.name),
    ).toEqual(['banner']);
    expect(buildResult.componentResults).toEqual([
      expect.objectContaining({ itemName: 'banner', success: true }),
    ]);

    const bannerJs = await readDistFile(
      projectRoot,
      'components/banner/banner.js',
    );
    expect(bannerJs).toContain('from "@/content/label"');
    await expect(
      fs.access(
        path.join(projectRoot, 'dist/components/banner/banner.component.yml'),
      ),
    ).resolves.toBeUndefined();
    await expect(
      fs.access(path.join(projectRoot, 'dist/components/banner/banner.css')),
    ).resolves.toBeUndefined();

    expect(buildResult.manifest.vendor).toEqual({});
    expect(buildResult.manifest.local).toEqual({
      '@/content/label': expect.stringMatching(/^\.\/local\/label-[\w-]+\.js$/),
    });
    expect(buildResult.manifest.shared).toEqual([]);
    await expectManifestFilesExist(projectRoot, buildResult.manifest.local);
  });
});
