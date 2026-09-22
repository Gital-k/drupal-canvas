import assert from 'node:assert/strict';
import { execFileSync, spawnSync } from 'node:child_process';
import { lstat, readFile, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';

/** Exercise the npm-installed executable, never its real-script-path workaround. */
export async function verifyInstalledBin(app) {
  const registry = path.join(app, 'src/canvas-components.generated.ts');
  const manifest = path.join(app, 'src/canvas-manifest.generated.ts');
  const marker = path.join(app, '.canvas-bin-child');
  const invalid = path.join(app, 'src/bin-type-error.ts');
  await Promise.all(
    [registry, manifest, marker, invalid].map((file) =>
      rm(file, { force: true }),
    ),
  );
  const symlink = (
    await lstat(path.join(app, 'node_modules/.bin/canvas-angular'))
  ).isSymbolicLink();
  if (process.platform !== 'win32')
    assert(symlink, 'Exercise the real npm bin symlink');
  try {
    execFileSync('npm', ['run', 'type-check'], { cwd: app, stdio: 'inherit' });
    assert.match(await readFile(registry, 'utf8'), /"counter":/);
    assert.match(await readFile(manifest, 'utf8'), /"machineName": "counter"/);
    // Prove arbitrary child dispatch, argument preservation, and nonzero status.
    const child = spawnSync(
      'npm',
      [
        'exec',
        '--',
        'canvas-angular',
        '--',
        process.execPath,
        '-e',
        `require('node:fs').writeFileSync('.canvas-bin-child', process.argv[1]); process.exit(23)`,
        'argument with spaces',
      ],
      { cwd: app, encoding: 'utf8' },
    );
    assert.equal(child.status, 23, child.stderr);
    assert.equal(await readFile(marker, 'utf8'), 'argument with spaces');
    // A successful wrapper exit alone cannot prove ngc actually ran.
    await writeFile(
      invalid,
      'export const mustFail: number = "not a number";\n',
    );
    const failed = spawnSync('npm', ['run', 'type-check'], {
      cwd: app,
      encoding: 'utf8',
    });
    assert.notEqual(failed.status, 0);
    assert.match(failed.stdout + failed.stderr, /TS2322/);
    return {
      symlink,
      generatedSources: true,
      ngcExecuted: true,
      childExitCode: child.status,
      typeErrorRejected: true,
    };
  } finally {
    await rm(marker, { force: true });
    await rm(invalid, { force: true });
  }
}
