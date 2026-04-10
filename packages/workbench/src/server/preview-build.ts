import { promises as fs } from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

import { buildPreviewDataPayload, parsePreviewDataArgs } from './preview-data';

import type { Spec } from '@json-render/core';
import type {
  PreviewDataIssue,
  PreviewDataRequest,
  PreviewDataTarget,
} from './preview-data';

export interface PreviewBuildCliArgs {
  mode: 'component' | 'page';
  inputPath: string;
  pretty: boolean;
  outDir: string;
}

export interface PreviewBuildPayload {
  ok: boolean;
  request: PreviewDataRequest;
  target: PreviewDataTarget | null;
  spec: Spec | null;
  renderMode: 'interactive';
  outDir: string | null;
  files: {
    indexHtml: string;
    javascript: string;
    css: string;
  } | null;
  warnings: PreviewDataIssue[];
  errors: PreviewDataIssue[];
}

interface BuildPreviewArtifactOptions {
  mode: 'component' | 'page';
  inputPath: string;
  projectRoot: string;
  outDir: string;
}

interface BuildPreviewArtifactDependencies {
  buildPreviewDataPayload?: typeof buildPreviewDataPayload;
}

function toIssue(code: string, message: string): PreviewDataIssue {
  return {
    code,
    message,
  };
}

function toPayload(
  request: PreviewDataRequest,
  options: {
    ok: boolean;
    target?: PreviewDataTarget | null;
    spec?: Spec | null;
    outDir?: string | null;
    files?: PreviewBuildPayload['files'];
    warnings?: PreviewDataIssue[];
    errors?: PreviewDataIssue[];
  },
): PreviewBuildPayload {
  return {
    ok: options.ok,
    request,
    target: options.target ?? null,
    spec: options.spec ?? null,
    renderMode: 'interactive',
    outDir: options.outDir ?? null,
    files: options.files ?? null,
    warnings: options.warnings ?? [],
    errors: options.errors ?? [],
  };
}

function parsePreviewBuildArgsError(message: string): {
  ok: false;
  error: string;
} {
  return {
    ok: false,
    error: message,
  };
}

export function parsePreviewBuildArgs(
  argv: string[],
): { ok: true; value: PreviewBuildCliArgs } | { ok: false; error: string } {
  const forwardedArgs: string[] = [];
  let outDir: string | null = null;

  for (let index = 0; index < argv.length; index += 1) {
    const argument = argv[index];

    if (argument === '--out-dir') {
      const next = argv[index + 1];
      if (!next || next.startsWith('--')) {
        return parsePreviewBuildArgsError('Missing value for --out-dir.');
      }

      outDir = next;
      index += 1;
      continue;
    }

    forwardedArgs.push(argument);
  }

  if (!outDir) {
    return parsePreviewBuildArgsError(
      'Missing required argument: --out-dir <path>.',
    );
  }

  const parsed = parsePreviewDataArgs(forwardedArgs);
  if (!parsed.ok) {
    return parsePreviewBuildArgsError(parsed.error);
  }

  return {
    ok: true,
    value: {
      mode: parsed.value.mode,
      inputPath: parsed.value.inputPath,
      pretty: parsed.value.pretty,
      outDir,
    },
  };
}

export function extractInlineScript(
  html: string,
  attributeMarker: string,
): string | null {
  const pattern = new RegExp(
    `<script[^>]*${attributeMarker}[^>]*>([\\s\\S]*?)<\\/script>`,
  );
  const match = html.match(pattern);
  if (!match) {
    return null;
  }

  return match[1] ?? null;
}

export function buildPreviewArtifactIndexHtml(): string {
  return [
    '<!doctype html>',
    '<html lang="en">',
    '<head>',
    '<meta charset="utf-8"/>',
    '<meta name="viewport" content="width=device-width, initial-scale=1"/>',
    '<link rel="stylesheet" href="./preview.css"/>',
    '</head>',
    '<body>',
    '<div id="root"></div>',
    '<script type="text/javascript" src="./preview.js"></script>',
    '</body>',
    '</html>',
  ].join('\n');
}

export async function buildPreviewArtifact(
  options: BuildPreviewArtifactOptions,
  dependencies: BuildPreviewArtifactDependencies = {},
): Promise<PreviewBuildPayload> {
  const request: PreviewDataRequest = {
    mode: options.mode,
    inputPath: options.inputPath,
    projectRoot: options.projectRoot,
  };
  const buildPayload =
    dependencies.buildPreviewDataPayload ?? buildPreviewDataPayload;
  const outputDirectory = path.resolve(options.projectRoot, options.outDir);

  const previewDataPayload = await buildPayload({
    mode: options.mode,
    inputPath: options.inputPath,
    projectRoot: options.projectRoot,
  });

  if (!previewDataPayload.ok) {
    return toPayload(request, {
      ok: false,
      target: previewDataPayload.target,
      spec: previewDataPayload.spec,
      outDir: outputDirectory,
      warnings: previewDataPayload.warnings,
      errors: previewDataPayload.errors,
    });
  }

  if (!previewDataPayload.iframeHtml) {
    return toPayload(request, {
      ok: false,
      target: previewDataPayload.target,
      spec: previewDataPayload.spec,
      outDir: outputDirectory,
      warnings: previewDataPayload.warnings,
      errors: [
        ...previewDataPayload.errors,
        toIssue(
          'artifact_build_failed',
          'Preview data payload did not include iframeHtml.',
        ),
      ],
    });
  }

  const bootstrapScript = extractInlineScript(
    previewDataPayload.iframeHtml,
    'data-canvas-preview-bootstrap',
  );
  const runtimeScript = extractInlineScript(
    previewDataPayload.iframeHtml,
    'data-canvas-preview-runtime',
  );

  if (!runtimeScript) {
    return toPayload(request, {
      ok: false,
      target: previewDataPayload.target,
      spec: previewDataPayload.spec,
      outDir: outputDirectory,
      warnings: previewDataPayload.warnings,
      errors: [
        ...previewDataPayload.errors,
        toIssue(
          'artifact_build_failed',
          'Missing runtime script in preview iframe HTML.',
        ),
      ],
    });
  }

  const javascript = [bootstrapScript, runtimeScript]
    .filter((script): script is string => Boolean(script))
    .join('\n');
  const css = previewDataPayload.css;
  const indexHtml = buildPreviewArtifactIndexHtml();

  await fs.rm(outputDirectory, { recursive: true, force: true });
  await fs.mkdir(outputDirectory, { recursive: true });

  const indexHtmlPath = path.join(outputDirectory, 'index.html');
  const jsPath = path.join(outputDirectory, 'preview.js');
  const cssPath = path.join(outputDirectory, 'preview.css');

  await Promise.all([
    fs.writeFile(indexHtmlPath, indexHtml, 'utf-8'),
    fs.writeFile(jsPath, javascript, 'utf-8'),
    fs.writeFile(cssPath, css, 'utf-8'),
  ]);

  return toPayload(request, {
    ok: true,
    target: previewDataPayload.target,
    spec: previewDataPayload.spec,
    outDir: outputDirectory,
    files: {
      indexHtml: indexHtmlPath,
      javascript: jsPath,
      css: cssPath,
    },
    warnings: previewDataPayload.warnings,
    errors: [],
  });
}

export async function runPreviewBuildCli(argv: string[]): Promise<number> {
  const parsed = parsePreviewBuildArgs(argv);

  if (!parsed.ok) {
    const payload = toPayload(
      {
        mode: 'component',
        inputPath: '',
        projectRoot: process.cwd(),
      },
      {
        ok: false,
        errors: [toIssue('invalid_arguments', parsed.error)],
      },
    );
    process.stdout.write(`${JSON.stringify(payload)}\n`);
    return 1;
  }

  const payload = await buildPreviewArtifact({
    mode: parsed.value.mode,
    inputPath: parsed.value.inputPath,
    projectRoot: process.cwd(),
    outDir: parsed.value.outDir,
  });

  const serialized = parsed.value.pretty
    ? JSON.stringify(payload, null, 2)
    : JSON.stringify(payload);
  process.stdout.write(`${serialized}\n`);

  return payload.ok ? 0 : 1;
}

function isDirectExecution(): boolean {
  const executedPath = process.argv[1];
  if (!executedPath) {
    return false;
  }

  return import.meta.url === pathToFileURL(executedPath).href;
}

if (isDirectExecution()) {
  runPreviewBuildCli(process.argv.slice(2))
    .then((exitCode) => {
      process.exit(exitCode);
    })
    .catch((error: unknown) => {
      const payload = toPayload(
        {
          mode: 'component',
          inputPath: '',
          projectRoot: process.cwd(),
        },
        {
          ok: false,
          errors: [
            toIssue(
              'runtime_failure',
              error instanceof Error ? error.message : String(error),
            ),
          ],
        },
      );
      process.stdout.write(`${JSON.stringify(payload)}\n`);
      process.exit(1);
    });
}
