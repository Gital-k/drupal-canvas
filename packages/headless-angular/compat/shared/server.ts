import { resolve } from 'node:path';
import express from 'express';
import {
  AngularNodeAppEngine,
  createNodeRequestHandler,
  isMainModule,
  writeResponseToNodeResponse,
} from '@angular/ssr/node';

import { makeTree } from './tree';

const app = express();
const angular = new AngularNodeAppEngine();
app.get('/api/tree', (req, res) => {
  const url = new URL(req.url, 'http://localhost');
  url.pathname = url.searchParams.get('path') ?? '/draft';
  res.setHeader('Cache-Control', 'no-store');
  res.json(makeTree(url));
});
app.use(
  express.static(resolve(import.meta.dirname, '../browser'), { index: false }),
);
app.use((req, res, next) => {
  angular
    .handle(req)
    .then((response) =>
      response ? writeResponseToNodeResponse(response, res) : next(),
    )
    .catch(next);
});
if (isMainModule(import.meta.url)) {
  app.listen(Number(process.env['PORT'] ?? 4221), '127.0.0.1', () =>
    console.log('Proof server ready'),
  );
}
export const reqHandler = createNodeRequestHandler(app);
