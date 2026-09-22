import {
  mkdir,
  mkdtemp,
  readdir,
  readFile,
  rm,
  writeFile,
} from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import spawn from 'cross-spawn';
import { expect, it, vi } from 'vitest';
import * as prompts from '@clack/prompts';

import templates from '../templates.json';
import createProject from './create.js';
import { resolveTemplate } from './lib/template-resolution.js';

const git = vi.hoisted(() => ({
  clone: vi.fn(),
  fetch: vi.fn(),
  checkout: vi.fn(),
  init: vi.fn(),
  add: vi.fn(),
  commit: vi.fn(),
}));
vi.mock('./lib/use-git.js', () => ({ default: () => git }));
vi.mock('./lib/detect-package-manager.js', () => ({ default: () => 'npm' }));
vi.mock('cross-spawn', async () => {
  const { EventEmitter } = await import('node:events');
  return {
    default: vi.fn(() => {
      const child = new EventEmitter();
      queueMicrotask(() => child.emit('close', 0));
      return child;
    }),
  };
});
vi.mock('@clack/prompts', () => ({
  spinner: () => ({ start: vi.fn(), stop: vi.fn() }),
  log: { info: vi.fn(), warn: vi.fn(), error: vi.fn() },
  note: vi.fn(),
  outro: vi.fn(),
}));

it('scaffolds the registered Angular subdirectory through the existing create flow', async () => {
  // Synthetic clone and package install: never access the template checkout,
  // network, shared dependencies, or a real Git repository.
  const root = await mkdtemp(join(tmpdir(), 'create-angular-'));
  const packageJson = {
    name: 'template-angular',
    scripts: {
      dev: 'canvas-angular --watch -- ng serve',
      build: 'canvas-angular -- ng build',
    },
    dependencies: { '@angular/core': '^21.0.0' },
  };
  const angularJson = JSON.stringify({
    version: 1,
    projects: { frontend: { root: '' } },
  });
  const template = resolveTemplate(templates, 'angular')!;
  vi.spyOn(process, 'cwd').mockReturnValue(root);
  vi.spyOn(process, 'exit').mockImplementation(() => {
    throw new Error('Unexpected process.exit');
  });
  git.clone.mockImplementation(async (_url: string, destination: string) => {
    await mkdir(join(destination, 'angular', 'src'), { recursive: true });
    await mkdir(join(destination, 'nextjs'));
    await mkdir(join(destination, '.git'));
    await writeFile(
      join(destination, 'README.md'),
      'Repository-level documentation',
    );
    await writeFile(
      join(destination, 'angular', 'package.json'),
      JSON.stringify(packageJson),
    );
    await writeFile(join(destination, 'angular', 'angular.json'), angularJson);
    await writeFile(
      join(destination, 'angular', '.env.example'),
      'CANVAS_SITE_URL=\n',
    );
    await writeFile(
      join(destination, 'angular', 'src', 'app.ts'),
      'export class App {}',
    );
  });
  try {
    await createProject({
      template,
      projectName: 'my-angular-site',
      interactive: false,
      selectedAgents: [],
      siteUrl: 'https://cms.example',
    });
    const project = join(root, 'my-angular-site');
    expect(
      JSON.parse(await readFile(join(project, 'package.json'), 'utf8')),
    ).toEqual({ ...packageJson, name: 'my-angular-site' });
    expect(await readFile(join(project, 'angular.json'), 'utf8')).toBe(
      angularJson,
    );
    expect(await readFile(join(project, '.env'), 'utf8')).toContain(
      'CANVAS_SITE_URL="https://cms.example"',
    );
    expect(await readFile(join(project, 'src', 'app.ts'), 'utf8')).toBe(
      'export class App {}',
    );
    expect(await readdir(root)).toEqual(['my-angular-site']);
    const files = await readdir(project);
    for (const excluded of ['nextjs', 'README.md', '.git'])
      expect(files).not.toContain(excluded);
    expect(git.clone).toHaveBeenCalledWith(
      template.repository.url,
      expect.stringContaining('.canvas-template-'),
      { '--depth': 1, '--branch': 'main' },
    );
    expect(spawn).toHaveBeenCalledWith(
      'npm',
      ['install'],
      expect.objectContaining({
        cwd: './my-angular-site',
        env: expect.objectContaining({ NODE_ENV: 'development' }),
      }),
    );
    expect(git.init).toHaveBeenCalledWith([
      '--initial-branch=main',
      'my-angular-site',
    ]);
    expect(git.commit).toHaveBeenCalledWith(
      expect.stringContaining('Path: angular'),
    );
    expect(prompts.note).toHaveBeenCalledWith(
      expect.stringContaining('npm run dev'),
      'Get started',
    );
    expect(prompts.log.error).not.toHaveBeenCalled();
  } finally {
    vi.restoreAllMocks();
    await rm(root, { recursive: true, force: true });
  }
});
