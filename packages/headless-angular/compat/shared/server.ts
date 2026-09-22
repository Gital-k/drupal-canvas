import { resolve } from 'node:path';
import express from 'express';
import {
  AngularNodeAppEngine,
  createNodeRequestHandler,
  createWebRequestFromNodeRequest,
  isMainModule,
  writeResponseToNodeResponse,
} from '@angular/ssr/node';
import { createCanvasHandler } from '@drupal-canvas/headless-angular/server';

import manifest from './canvas-manifest.generated';
import { makeTree } from './tree';

import type { EntityResult } from '@drupal-canvas/headless';

const app = express();
const angular = new AngularNodeAppEngine();
const port = Number(process.env['PORT'] ?? 4221);
const origin = `http://127.0.0.1:${port}`;

// Deliberately fake Drupal transport, ONLY in this loopback integration fixture.
// It exercises the real SDK flows, not Drupal's JWT validation/permissions.
let issued = 0;
let exchanges = 0;
let revision = 1;
const requests: { uri: string; authenticated: boolean }[] = [];
app.get('/test/assertion', (req, res) => {
  const claims = {
    sub: '42',
    path: req.query['path'] ?? '/integration/one',
    resourceVersion: 'rel:working-copy',
    renewUrl: `${origin}/mock-drupal/renew`,
    nonce: ++issued,
    ttl: req.query['ttl'] ?? (issued === 1 ? 20 : 900),
  };
  res.json({
    assertion: `e30.${Buffer.from(JSON.stringify(claims)).toString('base64url')}.fixture`,
  });
});
app.post(
  '/mock-drupal/oauth/token',
  express.urlencoded({ extended: false }),
  (req, res) => {
    const claims = JSON.parse(
      Buffer.from(
        String(req.body.assertion).split('.')[1],
        'base64url',
      ).toString(),
    );
    exchanges++;
    res.json({
      access_token: `fixture-private-token-${exchanges}`,
      token_type: 'Bearer',
      expires_in: Number(claims.ttl ?? 900),
    });
  },
);
app.get('/mock-drupal/canvas/content-api', (req, res) => {
  const uri = String(req.query['requestUri']);
  const authenticated = Boolean(req.headers.authorization);
  requests.push({ uri, authenticated });
  if (uri === '/integration/missing') {
    res.sendStatus(404);
    return;
  }
  if (uri === '/integration/redirect') {
    res.json({
      redirect: {
        url: '/integration/second',
        statusCode: 301,
        external: false,
      },
    });
    return;
  }
  const content =
    uri === '/integration/watch'
      ? { element: 'js-watch', props: { label: 'Watched' } }
      : makeTree(new URL(`/published?revision=${revision}`, origin));
  res.json({
    content,
    head: {
      title: `${authenticated ? 'Private' : 'Public'} ${uri} R${revision}`,
      meta: [{ name: 'description', content: `Description ${uri}` }],
      link: [
        { rel: 'canonical', href: origin + uri },
        { rel: 'alternate', hreflang: 'fr', href: origin + '/fr' + uri },
      ],
      script: [
        {
          type: 'application/ld+json',
          textContent: {
            '@type': 'Article',
            name: uri,
            text: '</script><script>unsafe()</script>',
          },
        },
      ],
    },
    route: {
      name: 'entity.node.canonical',
      requestUri: uri,
      params: { node: '42' },
      managedByCanvas: true,
      entity: {
        entityType: 'node',
        bundle: 'article',
        id: '42',
        uuid: 'entity-uuid',
        langcode: 'en',
      },
    },
  });
});
app.get('/mock-drupal/canvas/content-api/entity', (req, res) => {
  const result: EntityResult = {
    managedByCanvas: true,
    content: {
      element: 'js-counter',
      props: {
        canvasUuid: 'entity-render-uuid',
        label: `${req.headers.authorization ? 'Private' : 'Public'} entity ${req.query['id']} ${req.query['viewMode']}`,
      },
    },
    entity: {
      entityType: String(req.query['type']),
      bundle: 'article',
      id: String(req.query['id']),
      uuid: 'entity-uuid',
      langcode: 'en',
    },
  };
  res.json(result);
});
app.post('/test/revision', (_req, res) => {
  revision++;
  res.json({ revision });
});
app.get('/test/stats', (_req, res) =>
  res.json({ issued, exchanges, requests, revision }),
);

app.get('/api/tree', (req, res) => {
  const url = new URL(req.url, origin);
  url.pathname = url.searchParams.get('path') ?? '/draft';
  res.setHeader('Cache-Control', 'no-store');
  res.json(makeTree(url));
});
app.use(
  express.static(resolve(import.meta.dirname, '../browser'), { index: false }),
);
const canvas = createCanvasHandler({
  config: { baseUrl: origin + '/mock-drupal' },
  manifest,
});
app.use((req, res, next) => {
  const integrated =
    req.path === '/' ||
    req.path.startsWith('/integration') ||
    req.path.startsWith('/api/');
  const work = integrated
    ? canvas(createWebRequestFromNodeRequest(req), (request, context) =>
        angular.handle(request, context),
      )
    : angular.handle(req);
  work
    .then((response) =>
      response ? writeResponseToNodeResponse(response, res) : next(),
    )
    .catch(next);
});
if (isMainModule(import.meta.url))
  app.listen(port, '127.0.0.1', () => console.log('Proof server ready'));
export const reqHandler = createNodeRequestHandler(app);
