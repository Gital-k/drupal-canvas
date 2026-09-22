import assert from 'node:assert/strict';

export async function verifyIntegration(origin, browser, evaluate) {
  const response = await fetch(origin + '/integration/one', {
    headers: { Connection: 'close' },
  });
  const html = await response.text();
  assert.equal(response.status, 200);
  assert(html.includes('Public /integration/one R1'));
  assert(html.includes('rel="canonical"'));
  assert(html.includes('application/ld+json'));
  assert(!html.includes('fixture-private-token'));
  assert(!html.includes('private-verifier'));
  const browserBundle = await (await fetch(origin + '/main.js')).text();
  assert(!browserBundle.includes('fixture-private-token'));
  assert(!browserBundle.includes('/mock-drupal/oauth/token'));
  assert.equal((await fetch(origin + '/integration/missing')).status, 404);
  const redirect = await fetch(origin + '/integration/redirect', {
    redirect: 'manual',
  });
  assert.equal(redirect.status, 301);
  assert.equal(redirect.headers.get('location'), '/integration/second');
  assert.equal((await fetch(origin + '/api/canvas/components')).status, 401);

  browser('console', '--clear');
  browser('errors', '--clear');
  browser('open', origin + '/host');
  const frame = `document.querySelector('iframe').contentWindow`;
  browser(
    'wait',
    '--fn',
    `${frame}.__integration?.store.session()?.enabled === true && window.__hostEvents.some(e => e.type === 'active')`,
  );
  assert(
    evaluate(`${frame}.__ssrNodes.length`) > 0,
    'Integrated route must SSR real content',
  );
  assert.equal(
    evaluate(
      `${frame}.__ssrNodes.filter(n => !['chrome', 'trusted'].includes(n.id)).every(n => n.isConnected)`,
    ),
    true,
    'Integrated Angular hosts must be hydrated, not replaced',
  );
  assert.equal(
    evaluate(`${frame}.document.querySelector('#page-title').textContent`),
    'Private /integration/one R1',
  );
  assert.equal(
    evaluate(`${frame}.document.cookie.includes('canvas_headless')`),
    false,
  );
  assert(evaluate(`${frame}.__messageListeners.size`) >= 3);
  evaluate(
    `(() => { const w = ${frame}; w.__sentinel = 'retained'; w.__button = w.document.querySelector('proof-counter button'); w.__button.click(); w.document.querySelector('#navigate').click(); })()`,
  );
  browser(
    'wait',
    '--fn',
    `${frame}.document.querySelector('#page-title')?.textContent === 'Private /integration/second?example=query R1'`,
  );
  browser(
    'wait',
    '--fn',
    `window.__hostMessages.some(m => m.type === 'canvas-headless:status' && m.path === '/integration/second?example=query')`,
  );
  assert.equal(evaluate(`${frame}.__sentinel`), 'retained');
  assert.equal(evaluate(`${frame}.__button.isConnected`), true);
  assert.equal(
    evaluate(
      `${frame}.document.head.querySelectorAll('link[rel="canonical"][data-canvas-head]').length`,
    ),
    1,
  );
  assert(
    evaluate(
      `${frame}.document.querySelector('link[rel="canonical"]').href`,
    ).endsWith('/integration/second?example=query'),
  );
  assert.equal(
    evaluate(
      `JSON.parse(${frame}.document.querySelector('script[type="application/ld+json"]').textContent).name`,
    ),
    '/integration/second?example=query',
  );
  assert.equal(
    evaluate(
      `${frame}.document.querySelectorAll('script[type="application/ld+json"]').length`,
    ),
    1,
  );
  assert.equal(
    evaluate(
      `${frame}.document.documentElement.outerHTML.includes('fixture-private-token')`,
    ),
    false,
  );
  evaluate(`${frame}.document.querySelector('#entity').click()`);
  browser(
    'wait',
    '--fn',
    `${frame}.document.querySelector('#entity-result').textContent.includes('Private entity 42 teaser')`,
  );

  assert.equal(
    evaluate(
      `JSON.parse(${frame}.document.querySelector('#entity-result').textContent).entity.uuid`,
    ),
    'entity-uuid',
  );
  assert.equal(
    evaluate(
      `JSON.parse(${frame}.document.querySelector('#entity-result').textContent).content.canvasDraftMode`,
    ),
    true,
  );
  const beforeQueue = (await (await fetch(origin + '/test/stats')).json())
    .requests.length;
  await evaluate(
    `Promise.all([${frame}.__integration.store.refresh(), ${frame}.__integration.store.refresh(), ${frame}.__integration.store.refresh()])`,
  );
  const afterQueue = (await (await fetch(origin + '/test/stats')).json())
    .requests.length;
  assert.equal(
    afterQueue - beforeQueue,
    2,
    'Refreshes must serialize and coalesce into one trailing pass',
  );

  await fetch(origin + '/test/revision', { method: 'POST' });
  evaluate(
    'window.__host.refresh(); window.__host.refresh(); window.__host.refresh()',
  );
  browser(
    'wait',
    '--fn',
    `${frame}.document.querySelector('#page-title').textContent.endsWith('R2')`,
  );
  assert.equal(
    evaluate(`${frame}.__button.textContent`),
    'Request primary-uuid v2: 1',
  );
  assert.equal(evaluate(`${frame}.__button.isConnected`), true);
  browser(
    'wait',
    '--fn',
    `window.__hostEvents.some(e => e.type === 'geometry' && e.geometry.some(g => g.id === 'added-uuid'))`,
  );
  // First mock token lives for 20s. The shared machine renews halfway through;
  // all later tokens last 900s. No test-side invocation of private lifecycle APIs.
  browser(
    'wait',
    '--fn',
    `window.__hostEvents.some(e => e.type === 'renewing') && window.__hostEvents.filter(e => e.type === 'active').some(e => e.tokenExpiresAt > Date.now() + 600000)`,
  );
  assert.equal(evaluate(`${frame}.__sentinel`), 'retained');
  assert.equal(evaluate(`${frame}.__button.isConnected`), true);
  const stats = await (await fetch(origin + '/test/stats')).json();
  assert(stats.exchanges >= 2);
  const metadataAssertion = await (
    await fetch(origin + '/test/assertion')
  ).json();
  const metadata = await fetch(origin + '/api/canvas/components', {
    headers: {
      Authorization: `Bearer ${metadataAssertion.assertion}`,
      Origin: origin,
    },
  });
  assert.equal(metadata.status, 200);
  assert.equal((await metadata.json()).components.length, 2);

  evaluate(`${frame}.document.querySelector('#redirect').click()`);
  browser(
    'wait',
    '--fn',
    `${frame}.location.pathname === '/integration/second' && ${frame}.location.search === ''`,
  );
  browser(
    'wait',
    '--fn',
    `${frame}.document.querySelector('#page-title')?.textContent === 'Private /integration/second R2'`,
  );
  evaluate(`${frame}.document.querySelector('#missing').click()`);
  browser(
    'wait',
    '--fn',
    `${frame}.document.querySelector('#not-found')?.textContent === 'Not found'`,
  );

  // Queue work, then destroy the actual Angular app. Public DestroyRef teardown
  // must remove every shared protocol listener and abort pending fetch work.
  evaluate(
    `${frame}.__integration.store.refresh(); ${frame}.__integration.store.refresh(); ${frame}.__destroyApp()`,
  );
  browser('wait', '--fn', `${frame}.__messageListeners.size === 0`);
  assert.equal(
    evaluate(`${frame}.document.querySelectorAll('[data-canvas-head]').length`),
    0,
  );
  assert.deepEqual(browser('errors').errors, []);

  const expired = await (
    await fetch(origin + '/test/assertion?path=/integration/one&ttl=1')
  ).json();
  browser(
    'open',
    origin + '/api/draft?assertion=' + encodeURIComponent(expired.assertion),
  );
  browser('wait', '--text', 'Expired');
  const expiredHtml = evaluate(`fetch('/integration/one').then(r => r.text())`);
  assert.match(
    expiredHtml,
    /id="session"[^>]*>Expired</,
    'SSR must show the server-computed expired state before hydration',
  );
  assert.equal(
    evaluate(`document.querySelector('#page-title').textContent`),
    'Public /integration/one R2',
  );
  browser('click', '#exit');
  browser('wait', '--url', origin + '/');
  browser(
    'wait',
    '--fn',
    'window.__integration?.store.session()?.enabled === false',
  );
  assert.equal(
    evaluate(`document.querySelectorAll('[data-canvas-marker]').length`),
    0,
  );
  assert.equal(
    evaluate(`document.querySelector('#page-title').textContent`),
    'Public / R2',
  );
  const session = evaluate(
    `fetch('/api/canvas/page?path=/integration/one').then(r => r.json()).then(r => r.session)`,
  );
  assert.equal(session.enabled, false, JSON.stringify({ origin, session }));
  return {
    renewedInPlace: true,
    serverExchanges: stats.exchanges,
    metadataWithoutSources: true,
    cleanup: true,
    expiryAndExit: true,
  };
}
