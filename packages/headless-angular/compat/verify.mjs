import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Uses the session supplied by the harness. Never hijack the default browser.
assert(
  process.env.AGENT_BROWSER_SESSION,
  'Set a task-owned AGENT_BROWSER_SESSION',
);
const root = path.dirname(fileURLToPath(import.meta.url));
const artifacts = path.join(root, 'artifacts');
const browser = (...args) => {
  const result = JSON.parse(
    execFileSync('agent-browser', [...args, '--json'], { encoding: 'utf8' }),
  );
  assert(result.success, JSON.stringify(result));
  return result.data;
};
const evaluate = (script) => browser('eval', script).result;
const report = [];
const tarball = await readFile(
  path.join(artifacts, 'drupal-canvas-headless-angular-0.0.0.tgz'),
);
const tarballIntegrity =
  'sha512-' + createHash('sha512').update(tarball).digest('base64');

try {
  for (const major of [21, 22]) {
    const origin = `http://127.0.0.1:42${major}`;
    const manifest = JSON.parse(
      await readFile(
        path.join(
          root,
          `angular${major}/node_modules/@drupal-canvas/headless-angular/package.json`,
        ),
        'utf8',
      ),
    );
    assert.equal(manifest.version, '0.0.0');
    const lock = JSON.parse(
      await readFile(
        path.join(root, `angular${major}/package-lock.json`),
        'utf8',
      ),
    );
    assert.equal(
      lock.packages['node_modules/@drupal-canvas/headless-angular'].integrity,
      tarballIntegrity,
      'Consumer installed a stale tarball',
    );
    // Verify both consumers actually installed byte-identical partial declarations.
    const fesm = await readFile(
      path.join(
        root,
        `angular${major}/node_modules/@drupal-canvas/headless-angular/fesm2022/drupal-canvas-headless-angular.mjs`,
      ),
    );
    assert.match(fesm.toString(), /ɵɵngDeclareComponent/);
    const artifactHash = createHash('sha256').update(fesm).digest('hex');
    if (report.length) assert.equal(artifactHash, report[0].artifactHash);

    // Parallel request isolation with distinct private cookies: only tree data is transferred.
    await Promise.all(
      ['Alice', 'Bob'].map(async (label) => {
        const response = await fetch(`${origin}/draft?label=${label}`, {
          headers: {
            Connection: 'close',
            Cookie: `private-token=secret-${label}`,
          },
        });
        assert.equal(response.status, 200);
        const html = await response.text();
        assert(html.includes(`${label} primary-uuid v1: 0`));
        assert(!html.includes(label === 'Alice' ? 'Bob' : 'Alice'));
        assert(!html.includes('secret-'));
        assert(html.includes('ng-server-context="ssr"'));
      }),
    );

    const pages = [];
    for (const route of ['/draft', '/published', '/empty', '/empty-top']) {
      const response = await fetch(origin + route, {
        headers: { Connection: 'close' },
      });
      assert.equal(response.status, 200);
      const html = await response.text();
      assert(
        html.includes('ng-server-context="ssr"'),
        'CSR fallback is not acceptable',
      );
      assert(!html.includes('ngskiphydration'));
      if (route === '/published') {
        assert(!/data-canvas-marker\s*=/.test(html));
        assert(!html.includes('canvas--slot-empty-placeholder'));
        assert(!html.includes('canvas--region-empty-placeholder'));
      }
      await writeFile(
        path.join(artifacts, `angular${major}-${route.slice(1)}-ssr.html`),
        html,
      );
      browser('console', '--clear');
      browser('errors', '--clear');
      browser('open', origin + route);
      browser('wait', '--fn', 'window.__proof?.hydrated === true');
      const proof = evaluate('window.__proof');
      assert.equal(
        proof.reused,
        true,
        'SSR Angular nodes must survive hydration',
      );
      assert(proof.captured > 0);
      const check = evaluate(`(() => {
        const markers = [...document.querySelectorAll('template[data-canvas-marker]')];
        const geometry = window.__geometry;
        const check = (condition, message) => { if (!condition) throw new Error(message); };
        check(!document.querySelector('[ngskiphydration]'), 'No hydration opt-outs');
        if (${JSON.stringify(route)} === '/published') {
          check(markers.length === 0 && geometry.length === 0, 'Published output has editor boundaries');
          check(document.querySelector('#default-markup').style.color === 'rgb(1, 2, 3)', 'Default trusted markup lost');
        } else {
          check(markers.filter(m => m.dataset.canvasType === 'region').length === 2, 'Exactly one region pair');
          check(geometry.every(g => g.rect.width > 0 && g.rect.height > 0), 'Unmeasurable boundary');
          if (${JSON.stringify(route)}.startsWith('/empty')) {
            check(document.querySelectorAll('.canvas--region-empty-placeholder').length === 1, 'Empty region drop target');
            check(geometry.length === 1 && geometry[0].type === 'region', 'Empty region geometry');
          } else {
            check(!document.querySelector('#default-markup'), 'Draft defaults should become placeholders');
            check(document.querySelectorAll('.canvas--slot-empty-placeholder').length === 3, 'Empty slot targets');
            check(geometry.length === 11, 'Nested/default/named boundary count');
            const button = document.querySelector('proof-counter button').getBoundingClientRect();
            const rect = geometry.find(g => g.id === 'primary-uuid').rect;
            check(Math.abs(button.height - rect.height) < 1 && Math.abs(button.width - rect.width) < 1, 'Component geometry must measure rendered button');
          }
          for (const marker of markers.filter(m => m.dataset.canvasMarker === 'start')) {
            const id = marker.dataset.canvasUuid ?? marker.dataset.canvasRegionId;
            check(markers.filter(m => m.dataset.canvasMarker === 'end' && (m.dataset.canvasUuid ?? m.dataset.canvasRegionId) === id).length === 1, 'Unbalanced markers');
          }
        }
        if (${JSON.stringify(route)} !== '/empty-top') {
          check(document.querySelector('#trusted em')?.textContent === 'HTML', 'Trusted HTML not rendered');
          check(document.querySelector('#trusted').style.color === 'rgb(4, 5, 6)', 'Trusted HTML style stripped');
        }
        return { markers: markers.length, geometry };
      })()`);
      if (route === '/draft' || route === '/published') {
        browser(
          'find',
          'role',
          'button',
          'click',
          '--name',
          'Request primary-uuid v1: 0',
        );
        browser('wait', '--text', 'Request primary-uuid v1: 1');
        browser(
          'find',
          'role',
          'button',
          'click',
          '--name',
          'Request secondary-uuid v1: 0',
        );
        browser('wait', '--text', 'Request secondary-uuid v1: 1');
        browser('click', '#refresh');
        browser('wait', '--text', 'Request primary-uuid v2: 1');
        browser('wait', '--text', 'Request added-uuid v2: 0');
        assert.equal(
          evaluate(
            `window.__ssrNodes.filter(n => !['chrome', 'trusted'].includes(n.id)).every(n => n.isConnected)`,
          ),
          true,
          'UUID identity lost after refresh/reorder',
        );
        assert.equal(
          evaluate(`document.querySelectorAll('proof-counter').length`),
          3,
        );
        assert.equal(
          evaluate(
            `document.querySelector('proof-counter button').textContent`,
          ),
          'Request added-uuid v2: 0',
        );
        assert.equal(
          evaluate(
            `document.querySelector('aside proof-counter button').textContent`,
          ),
          'Request secondary-uuid v2: 1',
        );
        if (route === '/published')
          assert.equal(
            evaluate(
              `document.querySelectorAll('[data-canvas-marker]').length`,
            ),
            0,
          );
        else
          browser(
            'wait',
            '--fn',
            'window.__geometry.some(g => g.id === "added-uuid")',
          );
      }
      const errors = browser('errors').errors;
      assert.deepEqual(errors, []);
      const logs = browser('console').messages;
      assert(
        !logs.some(
          (entry) => entry.type === 'error' || /NG05\d+/.test(entry.text),
        ),
        JSON.stringify(logs),
      );
      assert(
        logs.some((entry) =>
          /Angular hydrated .*0 component\(s\) were skipped/.test(entry.text),
        ),
        'Missing real hydration diagnostic',
      );
      pages.push({
        route,
        proof,
        ...check,
        hydrationLogs: logs.map((entry) => entry.text),
      });
    }
    report.push({ major, artifactHash, tarballIntegrity, pages });
  }
  await writeFile(
    path.join(artifacts, 'browser-proof.json'),
    JSON.stringify(report, null, 2) + '\n',
  );
  console.log(
    'PASS: same Angular 21 partial artifact, Angular 21 + 22 SSR/hydration/refresh/geometry/isolation',
  );
} finally {
  browser('close');
}
