import { execFileSync } from 'node:child_process';
import { cp, mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(fileURLToPath(import.meta.url));
const repo = path.resolve(root, '../../..');
const artifacts = path.join(root, 'artifacts');
await mkdir(artifacts, { recursive: true });
for (const pkg of ['headless', 'headless-angular']) {
  const directory = path.join(
    repo,
    'packages',
    pkg,
    ...(pkg === 'headless-angular' ? ['dist'] : []),
  );
  execFileSync('npm', ['pack', directory, '--pack-destination', artifacts], {
    stdio: 'inherit',
  });
}
for (const major of [21, 22]) {
  const app = path.join(root, `angular${major}`);
  await cp(path.join(root, 'shared'), path.join(app, 'src'), {
    recursive: true,
  });
  // Independent package trees: never resolve Angular 22 or TS 6 from the workspace.
  await writeFile(
    path.join(app, 'angular.json'),
    JSON.stringify(
      {
        version: 1,
        projects: {
          proof: {
            projectType: 'application',
            root: '',
            sourceRoot: 'src',
            architect: {
              build: {
                builder: '@angular/build:application',
                options: {
                  outputPath: 'dist/proof',
                  browser: 'src/main.ts',
                  server: 'src/main.server.ts',
                  ssr: { entry: 'src/server.ts' },
                  outputMode: 'server',
                  index: 'src/index.html',
                  tsConfig: 'tsconfig.json',
                  styles: ['src/styles.css'],
                  optimization: false,
                  sourceMap: true,
                  security: { allowedHosts: ['127.0.0.1'] },
                },
              },
            },
          },
        },
      },
      null,
      2,
    ) + '\n',
  );
  await writeFile(
    path.join(app, 'tsconfig.json'),
    JSON.stringify(
      {
        compilerOptions: {
          target: 'ES2022',
          module: 'preserve',
          moduleResolution: 'bundler',
          experimentalDecorators: true,
          strict: true,
          skipLibCheck: true,
          types: ['node'],
          lib: ['ES2022', 'DOM'],
        },
        angularCompilerOptions: { strictTemplates: true },
        include: ['src/**/*.ts'],
      },
      null,
      2,
    ) + '\n',
  );
}
