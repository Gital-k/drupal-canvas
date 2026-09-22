import { readFile } from 'node:fs/promises';
import { describe, expect, it } from 'vitest';

describe('Angular package publication', () => {
  it('directs Changesets to the built Angular package, not the source workspace', async () => {
    const manifest = JSON.parse(
      await readFile(new URL('../package.json', import.meta.url), 'utf8'),
    );
    const build = JSON.parse(
      await readFile(new URL('../ng-package.json', import.meta.url), 'utf8'),
    );

    expect(manifest.private).not.toBe(true);
    expect(manifest.publishConfig).toEqual({
      access: 'public',
      directory: build.dest,
    });
    expect(build.dest).toBe('dist');
    expect(build.assets).toContain('tools');
    expect(manifest.bin['canvas-angular']).toBe('./tools/build.mjs');
  });
});
