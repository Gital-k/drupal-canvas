import assert from 'node:assert/strict';

export async function verifyIntegration(origin, browser, evaluate) {
  const control = (endpoint, body = {}) =>
    fetch(origin + '/test/' + endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
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
  await control('hold', { path: '/integration/second?example=query' });
  evaluate(
    `(() => { const w = ${frame}; w.__sentinel = 'retained'; w.__button = w.document.querySelector('proof-counter button'); w.__button.click(); w.document.querySelector('#navigate').click(); })()`,
  );
  browser(
    'wait',
    '--fn',
    `fetch('/test/stats').then(r => r.json()).then(s => s.held === 1)`,
  );
  evaluate('window.__host.refresh()');
  // Hold the resolver through BOTH a host refresh and genuine timer renewal.
  browser(
    'wait',
    '--fn',
    `${frame}.__integration.store.session().tokenExpiresAt > Date.now() + 600000`,
  );
  assert.equal(evaluate(`${frame}.location.pathname`), '/integration/one');
  assert.equal(
    evaluate(`${frame}.__integration.store.path()`),
    '/integration/one',
  );
  assert.equal(
    evaluate(`${frame}.__integration.store.pendingPath()`),
    '/integration/second?example=query',
  );
  assert.equal(
    evaluate(`${frame}.document.querySelector('#page-title').textContent`),
    'Private /integration/one R1',
  );
  const heldStats = await (await fetch(origin + '/test/stats')).json();
  assert.equal(
    heldStats.held,
    1,
    'Refresh must not start a competing navigation request',
  );
  await control('release');
  browser(
    'wait',
    '--fn',
    `${frame}.document.querySelector('#page-title')?.textContent === 'Private /integration/second?example=query R1' && ${frame}.location.pathname === '/integration/second' && ${frame}.location.search === '?example=query'`,
  );
  browser(
    'wait',
    '--fn',
    `fetch('/test/stats').then(r => r.json()).then(s => s.requests.length >= ${heldStats.requests.length + 2})`,
  );
  const automaticRefreshes = (
    await (await fetch(origin + '/test/stats')).json()
  ).requests.slice(heldStats.requests.length);
  assert.deepEqual(
    automaticRefreshes.map((request) => request.uri),
    ['/integration/second?example=query', '/integration/second?example=query'],
  );
  // Drain the shared queue before asserting its post-navigation target/epoch.
  await evaluate(`${frame}.__integration.store.refresh()`);
  assert.equal(
    evaluate(`${frame}.__integration.store.path()`),
    '/integration/second?example=query',
  );
  assert.equal(evaluate(`${frame}.__integration.store.pendingPath()`), null);
  assert.equal(evaluate(`${frame}.__integration.store.error()`), null);
  assert(
    evaluate(
      `${frame}.__integration.store.session().tokenExpiresAt > Date.now() + 600000`,
    ),
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

  // A redirect discovered by HOST refresh must use Angular navigation, not
  // commit a null page or replace the document and its stateful components.
  await control('redirect', {
    path: '/integration/second?example=query',
    target: '/integration/refresh-target',
    external: false,
  });
  evaluate('window.__host.refresh()');
  browser(
    'wait',
    '--fn',
    `${frame}.location.pathname === '/integration/refresh-target' && ${frame}.__integration.store.path() === '/integration/refresh-target'`,
  );
  assert.equal(
    evaluate(`${frame}.document.querySelector('#page-title').textContent`),
    'Private /integration/refresh-target R2',
  );
  assert.equal(evaluate(`${frame}.__sentinel`), 'retained');
  assert.equal(evaluate(`${frame}.__button.isConnected`), true);
  assert.equal(evaluate(`${frame}.document.querySelector('#not-found')`), null);

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

  // A fresh short-lived session lets a real RENEWAL refresh discover an
  // external redirect. Same-origin destination, but external=true requires a
  // document navigation rather than an Angular router transition.
  await evaluate(
    `window.__host.activate({ path: '/integration/renew-redirect', ttl: '12' })`,
  );
  browser(
    'wait',
    '--fn',
    `${frame}.__integration?.store.path() === '/integration/renew-redirect'`,
  );
  evaluate(`${frame}.__redirectDocument = 'old document'`);
  await control('redirect', {
    path: '/integration/renew-redirect',
    target: origin + '/integration/external-target',
    external: true,
  });
  browser(
    'wait',
    '--fn',
    `${frame}.location.pathname === '/integration/external-target' && ${frame}.__integration?.store.path() === '/integration/external-target'`,
  );
  assert.equal(evaluate(`${frame}.__redirectDocument ?? null`), null);
  assert.equal(
    evaluate(`${frame}.document.querySelector('#page-title').textContent`),
    'Private /integration/external-target R2',
  );
  assert(
    evaluate(
      `${frame}.__integration.store.session().tokenExpiresAt > Date.now() + 600000`,
    ),
  );
  assert.deepEqual(browser('errors').errors, []);
  assert(
    !browser('console').messages.some((message) =>
      /AbortError/.test(message.text),
    ),
    'Refresh must never abort a resolver',
  );
  evaluate(`${frame}.__destroyApp()`);
  browser('wait', '--fn', `${frame}.__messageListeners.size === 0`);

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
    navigationRefreshOverlap: true,
    hostRefreshRouterRedirect: true,
    renewalRefreshDocumentRedirect: true,
    renewedInPlace: true,
    serverExchanges: stats.exchanges,
    metadataWithoutSources: true,
    cleanup: true,
    expiryAndExit: true,
  };
}
