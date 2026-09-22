import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { rename } from 'node:fs/promises';
import { createServer } from 'node:net';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(fileURLToPath(import.meta.url));
const repo = path.resolve(root, '../../..');
assert(
  process.env.AGENT_BROWSER_SESSION,
  'Set a task-owned AGENT_BROWSER_SESSION',
);
const run = (command, args, cwd = repo) =>
  execFileSync(command, args, { cwd, stdio: 'inherit' });
const servers = [];
const movedSources = [];
try {
  run('npx', [
    'turbo',
    'run',
    'build',
    '--filter=@drupal-canvas/headless-angular',
  ]);
  run('node', [path.join(root, 'prepare.mjs')]);
  for (const major of [21, 22]) {
    const app = path.join(root, `angular${major}`);
    if (process.argv.includes('--update-locks')) {
      // Explicit file arguments tell npm to re-read rebuilt local tarballs.
      run(
        'npm',
        [
          'install',
          '--ignore-scripts',
          '../artifacts/drupal-canvas-headless-0.7.0.tgz',
          '../artifacts/drupal-canvas-headless-angular-0.0.0.tgz',
        ],
        app,
      );
    } else {
      run('npm', ['ci', '--ignore-scripts'], app);
    }
    run(
      'node',
      ['node_modules/@drupal-canvas/headless-angular/tools/build.mjs'],
      app,
    );
    run('npm', ['run', 'build'], app);
    const saved = path.join(root, 'artifacts', `sources-${major}`);
    await rename(path.join(app, 'src'), saved);
    movedSources.push([saved, path.join(app, 'src')]);
    const port = 4200 + major;
    // Fail rather than reuse or stop a process that someone else owns.
    await new Promise((resolve, reject) => {
      const probe = createServer();
      probe.once('error', reject);
      probe.listen(port, '127.0.0.1', () => probe.close(resolve));
    });
    const server = spawn(process.execPath, ['dist/proof/server/server.mjs'], {
      cwd: app,
      env: { ...process.env, PORT: String(port) },
      stdio: ['ignore', 'pipe', 'inherit'],
    });
    servers.push(server);
    await new Promise((resolve, reject) => {
      server.once('error', reject);
      server.once('exit', (code) =>
        reject(new Error(`Proof server exited: ${code}`)),
      );
      server.stdout.on('data', (data) => {
        process.stdout.write(data);
        if (String(data).includes('Proof server ready')) resolve();
      });
    });
  }
  run('node', [path.join(root, 'verify.mjs')]);
} finally {
  for (const server of servers) server.kill();
  for (const [saved, source] of movedSources) await rename(saved, source);
}
