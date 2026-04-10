import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';

import {
  buildPreviewDataPayload,
  buildPreviewRuntimeEntrySource,
  parsePreviewDataArgs,
} from './preview-data';

const temporaryDirectories: string[] = [];

async function makeTemporaryDirectory(): Promise<string> {
  const directory = await fs.mkdtemp(
    path.join(os.tmpdir(), 'canvas-workbench-preview-data-'),
  );
  temporaryDirectories.push(directory);
  return directory;
}

async function writeFile(filePath: string, content: string): Promise<void> {
  await fs.mkdir(path.dirname(filePath), { recursive: true });
  await fs.writeFile(filePath, content, 'utf-8');
}

afterEach(async () => {
  await Promise.all(
    temporaryDirectories.map((directory) =>
      fs.rm(directory, { recursive: true, force: true }),
    ),
  );
  temporaryDirectories.length = 0;
});

describe('preview-data', () => {
  it('builds interactive component preview payload with inlined css html', async () => {
    const root = await makeTemporaryDirectory();

    await writeFile(
      path.join(root, 'canvas.config.json'),
      JSON.stringify(
        {
          componentDir: './components',
          pagesDir: './pages',
          aliasBaseDir: 'src',
          globalCssPath: './src/components/global.css',
        },
        null,
        2,
      ),
    );

    await writeFile(
      path.join(root, 'components/card/component.yml'),
      [
        'name: Card',
        'props:',
        '  properties:',
        '    title:',
        '      type: string',
        '      examples:',
        '        - Hello world',
      ].join('\n'),
    );
    await writeFile(
      path.join(root, 'components/card/index.tsx'),
      'export default function Card() { return null; }',
    );
    await writeFile(
      path.join(root, 'components/card/index.css'),
      '.card { color: red; }',
    );
    await writeFile(
      path.join(root, 'src/components/global.css'),
      'body { margin: 0; }',
    );

    let capturedRoot: string | null = null;
    let capturedCssEntryPaths: string[] = [];

    const payload = await buildPreviewDataPayload(
      {
        mode: 'component',
        inputPath: 'components/card/component.yml',
        projectRoot: root,
      },
      {
        bundleInteractivePreview: async (options) => {
          capturedRoot = options.spec.root;
          capturedCssEntryPaths = options.cssEntryPaths;
          return {
            js: 'console.log("interactive");',
            css: 'body{background:black;color:white;}',
          };
        },
      },
    );

    expect(payload.ok).toBe(true);
    expect(payload.renderMode).toBe('interactive');
    expect(payload.target?.projectRelativePath).toBe(
      'components/card/component.yml',
    );
    expect(payload.spec?.root).toBe('canvas-workbench-preview-root');
    expect(payload.css).toBe('body{background:black;color:white;}');
    expect(payload.iframeHtml).toContain(
      '<style data-canvas-preview-css>body{background:black;color:white;}</style>',
    );
    expect(payload.iframeHtml).toContain(
      '<script type="text/javascript" data-canvas-preview-runtime>',
    );
    expect(payload.iframeHtml).toContain(
      '<script type="text/javascript" data-canvas-preview-bootstrap>',
    );
    expect(payload.iframeHtml).toContain(
      "window.drupalSettings.canvasData.v0.baseUrl = canvasPreviewBaseUrl;",
    );
    expect(capturedRoot).toBe('canvas-workbench-preview-root');
    expect(capturedCssEntryPaths).toEqual(
      expect.arrayContaining([
        path.resolve(root, 'src/components/global.css'),
        path.resolve(root, 'components/card/index.css'),
      ]),
    );
  });

  it('builds interactive page preview payload', async () => {
    const root = await makeTemporaryDirectory();

    await writeFile(
      path.join(root, 'canvas.config.json'),
      JSON.stringify(
        {
          componentDir: './components',
          pagesDir: './pages',
          aliasBaseDir: 'src',
          globalCssPath: './src/components/global.css',
        },
        null,
        2,
      ),
    );

    await writeFile(
      path.join(root, 'components/hero/component.yml'),
      'name: Hero\n',
    );
    await writeFile(
      path.join(root, 'components/hero/index.tsx'),
      'export default function Hero() { return null; }',
    );

    await writeFile(
      path.join(root, 'components/card/component.yml'),
      'name: Card\n',
    );
    await writeFile(
      path.join(root, 'components/card/index.tsx'),
      'export default function Card() { return null; }',
    );
    await writeFile(
      path.join(root, 'components/card/index.css'),
      '.card { padding: 1rem; }',
    );

    await writeFile(
      path.join(root, 'pages/home.json'),
      JSON.stringify(
        {
          title: 'Home',
          elements: {
            hero: {
              type: 'js.hero',
              props: {
                title: 'Hello',
              },
            },
            card: {
              type: 'js.card',
              props: {
                featured: true,
              },
            },
          },
        },
        null,
        2,
      ),
    );

    await writeFile(
      path.join(root, 'src/components/global.css'),
      'body { margin: 0; }',
    );

    let capturedRegistryNames: string[] = [];

    const payload = await buildPreviewDataPayload(
      {
        mode: 'page',
        inputPath: 'pages/home.json',
        projectRoot: root,
      },
      {
        bundleInteractivePreview: async (options) => {
          capturedRegistryNames = options.componentSources
            .map((source) => source.name)
            .sort();
          return {
            js: 'console.log("interactive-page");',
            css: '.page{display:block;}',
          };
        },
      },
    );

    expect(payload.ok).toBe(true);
    expect(payload.renderMode).toBe('interactive');
    expect(payload.target?.id).toBe('home');
    expect(payload.spec?.root).toBe('canvas:component-tree');
    expect(payload.css).toBe('.page{display:block;}');
    expect(payload.iframeHtml).toContain('.page{display:block;}');
    expect(capturedRegistryNames).toEqual(['card', 'hero']);
  });

  it('keeps interactive render mode and fails when interactive bundle throws', async () => {
    const root = await makeTemporaryDirectory();

    await writeFile(
      path.join(root, 'components/card/component.yml'),
      'name: Card\n',
    );
    await writeFile(
      path.join(root, 'components/card/index.tsx'),
      'export default function Card() { return null; }',
    );

    const payload = await buildPreviewDataPayload(
      {
        mode: 'component',
        inputPath: 'components/card/component.yml',
        projectRoot: root,
      },
      {
        bundleInteractivePreview: async () => {
          throw new Error('boom');
        },
      },
    );

    expect(payload.ok).toBe(false);
    expect(payload.renderMode).toBe('interactive');
    expect(payload.iframeHtml).toBeNull();
    expect(payload.errors[0]?.code).toBe('interactive_bundle_failed');
  });

  it('builds runtime source that bootstraps React and drupalSettings defaults', () => {
    const source = buildPreviewRuntimeEntrySource({
      spec: {
        root: 'canvas-workbench-preview-root',
        elements: {},
      },
      componentSources: [
        {
          name: 'card',
          jsEntryPath: '/tmp/card.tsx',
        },
      ],
      cssEntryPaths: ['/tmp/global.css'],
    });

    expect(source).toContain('globalThis.React = React;');
    expect(source).toContain(
      'window.drupalSettings = window.drupalSettings ?? {};',
    );
    expect(source).toContain(
      'window.drupalSettings.canvasData.v0.baseUrl = window.location.origin;',
    );
  });

  it('parses preview-data cli args with strict selector rules', () => {
    const componentArgs = parsePreviewDataArgs([
      '--component-path',
      'components/card/component.yml',
      '--pretty',
    ]);
    expect(componentArgs).toEqual({
      ok: true,
      value: {
        mode: 'component',
        inputPath: 'components/card/component.yml',
        pretty: true,
      },
    });

    const invalidArgs = parsePreviewDataArgs([
      '--component-path',
      'components/card/component.yml',
      '--page-path',
      'pages/home.json',
    ]);
    expect(invalidArgs.ok).toBe(false);
  });
});
