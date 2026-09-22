import {
  mkdir,
  mkdtemp,
  readFile,
  rename,
  rm,
  writeFile,
} from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

// Build tooling intentionally ships as executable native ESM, not Angular code.
// @ts-expect-error Native Node tool has no TS declarations.
import { generateCanvasSources, watchCanvasSources } from '../tools/build.mjs';

describe('Angular CLI generated-source integration', () => {
  it('generates production metadata and observes additions, rename, deletion and config changes', async () => {
    const root = await mkdtemp(
      path.join(tmpdir(), 'canvas-angular-discovery-'),
    );
    const config = (componentDir: string) =>
      JSON.stringify({ componentDir, globalCssPath: 'src/styles.css' });
    const add = async (dir: string, name: string) => {
      await mkdir(path.join(root, dir), { recursive: true });
      await writeFile(
        path.join(root, dir, 'component.yml'),
        `name: ${name}\nmachineName: ${name}\nstatus: true\nprops:\n  properties: {}\n`,
      );
      await writeFile(
        path.join(root, dir, 'index.ts'),
        'export default class Component {}',
      );
    };
    await writeFile(
      path.join(root, 'canvas.config.json'),
      config('src/components'),
    );
    await add('src/components/one', 'one');
    const errors: unknown[] = [];
    const stop = await watchCanvasSources(root, (error: unknown) =>
      errors.push(error),
    );
    const registry = () =>
      readFile(path.join(root, 'src/canvas-components.generated.ts'), 'utf8');
    const manifest = () =>
      readFile(path.join(root, 'src/canvas-manifest.generated.ts'), 'utf8');
    try {
      expect(await registry()).toContain('"one": Component0');
      expect(await registry()).not.toContain('index.ts');
      expect(await manifest()).toContain('"machineName": "one"');
      await add('src/components/two', 'two');
      await expect.poll(registry).toContain('"two"');
      await rename(
        path.join(root, 'src/components/two'),
        path.join(root, 'src/components/renamed'),
      );
      await expect.poll(registry).toContain('/renamed/index');
      await rm(path.join(root, 'src/components/one'), { recursive: true });
      await expect.poll(registry).not.toContain('"one"');
      await add('different/three', 'three');
      await writeFile(
        path.join(root, 'canvas.config.json'),
        config('different'),
      );
      await expect.poll(registry).toContain('"three"');
      expect(await registry()).not.toContain('"two"');
      await writeFile(
        path.join(root, 'different/three/component.yml'),
        'invalid: [',
      );
      await expect.poll(() => errors.length).toBeGreaterThan(0);
      await expect(generateCanvasSources(root)).rejects.toThrow();
      await add('different/three', 'recovered');
      await expect.poll(registry).toContain('"recovered"');
      await stop();
      await add('different/three', 'stopped');
      await new Promise((resolve) => setTimeout(resolve, 150));
      expect(await registry()).toContain('"recovered"');
    } finally {
      await stop();
      await rm(root, { recursive: true, force: true });
    }
  });
});
