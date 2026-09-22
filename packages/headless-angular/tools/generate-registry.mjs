import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { buildComponentRegistryModule } from '@drupal-canvas/headless/component-registry';

const projectRoot = process.cwd();
const modulePath = path.resolve(
  projectRoot,
  process.argv[2] ?? 'src/canvas-components.generated.ts',
);
const source = await buildComponentRegistryModule({ projectRoot, modulePath });
await mkdir(path.dirname(modulePath), { recursive: true });
// Angular CLI emits JavaScript; extensionless imports avoid requiring noEmit
// (which would prevent the Angular compiler from producing application code).
await writeFile(modulePath, source.replace(/(from "[^"\n]+)\.ts";/g, '$1";'));
