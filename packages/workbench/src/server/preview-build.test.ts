import { promises as fs } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';

import {
  buildPreviewArtifact,
  buildPreviewArtifactIndexHtml,
  extractInlineScript,
  parsePreviewBuildArgs,
} from './preview-build';

import type { PreviewDataPayload } from './preview-data';

const temporaryDirectories: string[] = [];

async function makeTemporaryDirectory(): Promise<string> {
  const directory = await fs.mkdtemp(
    path.join(os.tmpdir(), 'canvas-workbench-preview-build-'),
  );
  temporaryDirectories.push(directory);
  return directory;
}

function makePreviewDataPayload(
  overrides: Partial<PreviewDataPayload> = {},
): PreviewDataPayload {
  return {
    ok: true,
    request: {
      mode: 'component',
      inputPath: 'components/card/component.yml',
      projectRoot: '/tmp/project',
    },
    target: {
      id: 'card',
      name: 'Card',
      projectRelativePath: 'components/card/component.yml',
    },
    spec: {
      root: 'canvas-workbench-preview-root',
      elements: {},
    },
    css: '.card{color:red;}',
    iframeHtml: [
      '<!doctype html><html><head>',
      '<style data-canvas-preview-css>.card{color:red;}</style>',
      '</head><body>',
      '<script data-canvas-preview-bootstrap>window.bootstrap=true;</script>',
      '<script data-canvas-preview-runtime>window.runtime=true;</script>',
      '</body></html>',
    ].join(''),
    renderMode: 'interactive',
    warnings: [],
    errors: [],
    ...overrides,
  };
}

afterEach(async () => {
  await Promise.all(
    temporaryDirectories.map((directory) =>
      fs.rm(directory, { recursive: true, force: true }),
    ),
  );
  temporaryDirectories.length = 0;
});

describe('preview-build', () => {
  it('parses args and requires --out-dir', () => {
    const parsed = parsePreviewBuildArgs([
      '--component-path',
      'components/card/component.yml',
      '--out-dir',
      '.canvas-preview',
      '--pretty',
    ]);

    expect(parsed).toEqual({
      ok: true,
      value: {
        mode: 'component',
        inputPath: 'components/card/component.yml',
        outDir: '.canvas-preview',
        pretty: true,
        splitFiles: false,
      },
    });

    const splitModeArgs = parsePreviewBuildArgs([
      '--component-path',
      'components/card/component.yml',
      '--out-dir',
      '.canvas-preview',
      '--split-files',
    ]);
    expect(splitModeArgs).toEqual({
      ok: true,
      value: {
        mode: 'component',
        inputPath: 'components/card/component.yml',
        outDir: '.canvas-preview',
        pretty: false,
        splitFiles: true,
      },
    });

    const missingOutDir = parsePreviewBuildArgs([
      '--component-path',
      'components/card/component.yml',
    ]);
    expect(missingOutDir.ok).toBe(false);
  });

  it('extracts inline scripts by marker', () => {
    const html =
      '<script data-canvas-preview-bootstrap>window.bootstrap=true;</script>';
    expect(
      extractInlineScript(html, 'data-canvas-preview-bootstrap'),
    ).toContain('window.bootstrap=true;');
    expect(extractInlineScript(html, 'data-canvas-preview-runtime')).toBeNull();
  });

  it('writes a self-contained index.html by default', async () => {
    const projectRoot = await makeTemporaryDirectory();
    const outDir = '.canvas-preview';

    const payload = await buildPreviewArtifact(
      {
        mode: 'component',
        inputPath: 'components/card/component.yml',
        projectRoot,
        outDir,
        splitFiles: false,
      },
      {
        buildPreviewDataPayload: async () => makePreviewDataPayload(),
      },
    );

    expect(payload.ok).toBe(true);
    expect(payload.renderMode).toBe('interactive');
    expect(payload.files).not.toBeNull();

    const outputDirectory = path.resolve(projectRoot, outDir);
    const outputPaths = payload.files!;
    expect(outputPaths.indexHtml).toBe(
      path.join(outputDirectory, 'index.html'),
    );
    expect(outputPaths.javascript).toBeNull();
    expect(outputPaths.css).toBeNull();

    const [indexHtml, jsFileCheck, cssFileCheck] = await Promise.all([
      fs.readFile(outputPaths.indexHtml, 'utf-8'),
      fs.access(path.join(outputDirectory, 'preview.js')).then(
        () => 'exists',
        () => 'missing',
      ),
      fs.access(path.join(outputDirectory, 'preview.css')).then(
        () => 'exists',
        () => 'missing',
      ),
    ]);

    expect(indexHtml).toContain('<style data-canvas-preview-css>');
    expect(indexHtml).toContain('window.bootstrap=true;');
    expect(indexHtml).toContain('window.runtime=true;');
    expect(jsFileCheck).toBe('missing');
    expect(cssFileCheck).toBe('missing');
  });

  it('writes split files when --split-files is enabled', async () => {
    const projectRoot = await makeTemporaryDirectory();
    const outDir = '.canvas-preview';

    const payload = await buildPreviewArtifact(
      {
        mode: 'component',
        inputPath: 'components/card/component.yml',
        projectRoot,
        outDir,
        splitFiles: true,
      },
      {
        buildPreviewDataPayload: async () => makePreviewDataPayload(),
      },
    );

    expect(payload.ok).toBe(true);
    expect(payload.files).not.toBeNull();

    const outputDirectory = path.resolve(projectRoot, outDir);
    const outputPaths = payload.files!;
    expect(outputPaths.indexHtml).toBe(
      path.join(outputDirectory, 'index.html'),
    );
    expect(outputPaths.javascript).toBe(
      path.join(outputDirectory, 'preview.js'),
    );
    expect(outputPaths.css).toBe(path.join(outputDirectory, 'preview.css'));

    const [indexHtml, javascript, css] = await Promise.all([
      fs.readFile(outputPaths.indexHtml, 'utf-8'),
      fs.readFile(outputPaths.javascript!, 'utf-8'),
      fs.readFile(outputPaths.css!, 'utf-8'),
    ]);

    expect(indexHtml).toContain(
      '<link rel="stylesheet" href="./preview.css"/>',
    );
    expect(indexHtml).toContain(
      '<script type="text/javascript" src="./preview.js"></script>',
    );
    expect(javascript).toContain('window.bootstrap=true;');
    expect(javascript).toContain('window.runtime=true;');
    expect(css).toBe('.card{color:red;}');
  });

  it('fails when runtime script is missing from iframe html', async () => {
    const projectRoot = await makeTemporaryDirectory();

    const payload = await buildPreviewArtifact(
      {
        mode: 'component',
        inputPath: 'components/card/component.yml',
        projectRoot,
        outDir: '.canvas-preview',
        splitFiles: false,
      },
      {
        buildPreviewDataPayload: async () =>
          makePreviewDataPayload({
            iframeHtml:
              '<script data-canvas-preview-bootstrap>window.bootstrap=true;</script>',
          }),
      },
    );

    expect(payload.ok).toBe(false);
    expect(payload.renderMode).toBe('interactive');
    expect(payload.files).toBeNull();
    expect(payload.errors.map((error) => error.code)).toContain(
      'artifact_build_failed',
    );
  });

  it('fails when preview-data payload is missing iframe html', async () => {
    const projectRoot = await makeTemporaryDirectory();

    const payload = await buildPreviewArtifact(
      {
        mode: 'component',
        inputPath: 'components/card/component.yml',
        projectRoot,
        outDir: '.canvas-preview',
        splitFiles: false,
      },
      {
        buildPreviewDataPayload: async () =>
          makePreviewDataPayload({
            iframeHtml: null,
          }),
      },
    );

    expect(payload.ok).toBe(false);
    expect(payload.errors.map((error) => error.code)).toContain(
      'artifact_build_failed',
    );
  });

  it('returns original preview-data errors when interactive payload fails', async () => {
    const projectRoot = await makeTemporaryDirectory();

    const payload = await buildPreviewArtifact(
      {
        mode: 'component',
        inputPath: 'components/card/component.yml',
        projectRoot,
        outDir: '.canvas-preview',
        splitFiles: false,
      },
      {
        buildPreviewDataPayload: async () =>
          makePreviewDataPayload({
            ok: false,
            iframeHtml: null,
            errors: [{ code: 'interactive_bundle_failed', message: 'boom' }],
          }),
      },
    );

    expect(payload.ok).toBe(false);
    expect(payload.renderMode).toBe('interactive');
    expect(payload.files).toBeNull();
    expect(payload.errors).toEqual([
      { code: 'interactive_bundle_failed', message: 'boom' },
    ]);
  });

  it('builds standalone index html shell that references artifact files', () => {
    const html = buildPreviewArtifactIndexHtml();
    expect(html).toContain('<!doctype html>');
    expect(html).toContain('<div id="root"></div>');
    expect(html).toContain('<link rel="stylesheet" href="./preview.css"/>');
    expect(html).toContain(
      '<script type="text/javascript" src="./preview.js"></script>',
    );
  });
});
