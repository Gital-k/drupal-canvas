import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { createWriteStream } from 'node:fs';
import { mkdir, readFile, rename, rm, writeFile } from 'node:fs/promises';
import { createServer } from 'node:net';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

assert(process.env.AGENT_BROWSER_SESSION, 'Use an owned browser session');
const root = path.dirname(fileURLToPath(import.meta.url));
const browser = (...args) =>
  execFileSync('agent-browser', args, { stdio: 'inherit' });
async function until(check) {
  const deadline = Date.now() + 90000;
  while (Date.now() < deadline) {
    try {
      if (await check()) return;
    } catch {
      /* Dev compilation is transient. */
    }
    await new Promise((resolve) => setTimeout(resolve, 200));
  }
  throw new Error('Angular CLI watch proof timed out');
}
const results = [];
for (const major of [21, 22]) {
  const app = path.join(root, `angular${major}`);
  const port = 4300 + major;
  const origin = `http://127.0.0.1:${port}`;
  await new Promise((resolve, reject) => {
    const socket = createServer();
    socket.once('error', reject);
    socket.listen(port, '127.0.0.1', () => socket.close(resolve));
  });
  const log = createWriteStream(
    path.join(root, 'artifacts', `angular${major}-dev.log`),
  );
  const child = spawn(
    process.execPath,
    [
      'node_modules/@drupal-canvas/headless-angular/tools/build.mjs',
      '--watch',
      '--',
      process.execPath,
      'node_modules/@angular/cli/bin/ng.js',
      'serve',
      '--host',
      '127.0.0.1',
      '--port',
      String(port),
    ],
    {
      cwd: app,
      env: { ...process.env, PORT: String(port) },
      detached: true,
      stdio: ['ignore', 'pipe', 'pipe'],
    },
  );
  child.stdout.pipe(log);
  child.stderr.pipe(log);
  const component = path.join(app, 'src/components/watch');
  const renamed = path.join(app, 'src/components/renamed-watch');
  const text = async (route) =>
    (await fetch(origin + route, { headers: { Connection: 'close' } })).text();
  const metadata = async () => {
    const assertion = await (await fetch(origin + '/test/assertion')).json();
    return (
      await fetch(origin + '/api/canvas/components', {
        headers: {
          Authorization: `Bearer ${assertion.assertion}`,
          Origin: origin,
        },
      })
    ).json();
  };
  try {
    await until(async () =>
      (await text('/integration/one')).includes('Public /integration/one'),
    );
    await mkdir(component);
    await writeFile(
      path.join(component, 'component.yml'),
      'name: Watch\nmachineName: watch\nstatus: true\nprops:\n  properties:\n    label:\n      type: string\n      title: Label\n',
    );
    const source = (label) =>
      `import { Component, input } from '@angular/core';\n@Component({selector:'watch-proof', template:'<p id="watch-added">${label}{{label()}}</p>'})\nexport default class Watch { readonly label = input(''); }\n`;
    await writeFile(path.join(component, 'index.ts'), source(''));
    await until(async () =>
      (await text('/integration/watch')).includes('id="watch-added">Watched'),
    );
    browser('open', origin + '/integration/watch');
    browser('wait', '--text', 'Watched');
    await until(async () => (await metadata()).components.length === 3);
    await rename(component, renamed);
    await writeFile(path.join(renamed, 'index.ts'), source('Renamed '));
    await until(async () =>
      (await text('/integration/watch')).includes(
        'id="watch-added">Renamed Watched',
      ),
    );
    browser('wait', '--text', 'Renamed Watched');
    await until(async () =>
      (await metadata()).components.some(
        (entry) => entry.relativeDirectory === 'renamed-watch',
      ),
    );
    browser('open', origin + '/integration/one');
    browser('wait', '--text', 'Public /integration/one');
    await rm(renamed, { recursive: true });
    await until(async () => (await metadata()).components.length === 2);
    assert(
      !(
        await readFile(
          path.join(app, 'src/canvas-components.generated.ts'),
          'utf8',
        )
      ).includes('renamed-watch'),
    );
    browser('open', origin + '/integration/one');
    browser(
      'wait',
      '--fn',
      'window.__integration?.store.page()?.head.title === "Public /integration/one R1"',
    );
    const errors = JSON.parse(
      execFileSync('agent-browser', ['errors', '--json'], { encoding: 'utf8' }),
    ).data.errors;
    assert.deepEqual(errors, []);
    results.push({
      major,
      add: true,
      rename: true,
      delete: true,
      angularCliLiveReload: true,
    });
  } finally {
    browser('close');
    process.kill(-child.pid, 'SIGTERM');
    await new Promise((resolve) => child.once('exit', resolve));
    await rm(component, { recursive: true, force: true });
    await rm(renamed, { recursive: true, force: true });
    log.end();
  }
}
await writeFile(
  path.join(root, 'artifacts', 'dev-proof.json'),
  JSON.stringify(results, null, 2),
);
console.log(
  'PASS: public Angular CLI watch/live reload, discovery add/rename/delete, both majors',
);
