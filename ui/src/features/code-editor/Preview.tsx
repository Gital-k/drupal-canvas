import { useCallback, useEffect, useRef, useState } from 'react';
import { parse } from '@babel/parser';
import { Flex, ScrollArea, Spinner } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import ErrorCard from '@/components/error/ErrorCard';
import { buildColorStyles } from '@/features/brandKit/colorCss';
import {
  buildFontFaceStyles,
  getFontPreloadDefinitions,
} from '@/features/brandKit/fontCss';
import {
  clearDataFetches,
  selectBrandKit,
  selectCodeComponentProperty,
  selectGlobalAssetLibraryProperty,
  selectPreviewCompiledJsForSlots,
  selectStatus,
  setCodeComponentProperty,
} from '@/features/code-editor/codeEditorSlice';
import {
  getDataDependenciesFromAst,
  getImportsFromAst,
} from '@/features/code-editor/utils/ast-utils';
import {
  isAllowedPreviewFetchUrl,
  safeSerializeDrupalSettings,
} from '@/features/code-editor/utils/previewPayload';
import {
  getPropValuesForPreview,
  getSlotNamesForPreview,
} from '@/features/code-editor/utils/utils';
import { useGetCodeComponentsQuery } from '@/services/componentAndLayout';
import { getDrupal, getDrupalSettings } from '@/utils/drupal-globals';

import MissingDefaultExportMessage, {
  CodeBlock,
  TextBlock,
} from './errors/MissingDefaultExportMessage';

import type { File } from '@babel/types';
import type { BrandKit } from '@/types/CodeComponent';

import styles from './Preview.module.css';

const Drupal = getDrupal();
// A route distinct from the parent app's own document, so the iframe below
// can run with an opaque origin (sandbox="allow-scripts", no
// allow-same-origin) instead of inheriting the parent's origin.
// @see \Drupal\canvas\Controller\CodeEditorPreviewController::shell()
const PREVIEW_SHELL_URL = Drupal.url('canvas/code-editor-preview');

// Neither this nor the import map below (@see `getPreviewImportMap()`) can
// load via the shell's own network fetch: a sandboxed, opaque-origin
// document has no classifiable network address space, and Chrome blocks any
// subresource fetch from such a document to a private network target (e.g.
// the same site's own `localhost` or internal IP) outright, before the
// request is even sent. Fetching it here instead, from the parent's real
// (non-opaque) origin, and relaying it into the iframe over `postMessage()`
// as a `Blob` (@see the `_canvas_preview_bootstrap_ready` listener below —
// `Blob`s survive `postMessage()`'s structured clone, including across an
// opaque-origin boundary) avoids that fetch entirely. Cached at module
// scope: the content never changes within a session, and every code
// component's preview iframe needs it.
// @see ui/lib/code-editor-preview.js
let previewScriptSourcePromise: Promise<Blob> | null = null;
function getPreviewScriptSource(): Promise<Blob> {
  previewScriptSourcePromise ??= fetch(
    Drupal.url('canvas/code-editor-preview/script.js'),
  ).then((response) => response.blob());
  return previewScriptSourcePromise;
}

// Matches a bundler-emitted relative import specifier: `from"./foo.js"` /
// `from '../foo.js'` inside an `import`/`export ... from` statement, a
// side-effect `import"./foo.js"`, or a dynamic `import("./foo.js")` — used
// by `discoverFiles()` below to find a file's own relative imports. The
// specifier is capture group 4. Keep in sync with the shell's copy, which
// rewrites the same specifiers.
// @see \Drupal\canvas\Controller\CodeEditorPreviewController::HTML
const RELATIVE_IMPORT_PATTERN =
  /\b(from|import)(\s*\(?\s*)(["'])(\.\.?\/[^"']*)\3/g;

// Recursively fetches a JS file's own relative-imported siblings
// (Vite/Rollup-built chunks sometimes import a shared sibling this way,
// e.g. `@drupal-api-client/json-api-client` importing
// `./_commonjsHelpers.js`), filling `files` with each one's raw source
// keyed by its real (absolute) URL.
//
// This only *discovers* files and their text — it deliberately doesn't
// build blob URLs itself, unlike `getPreviewScriptSource()` above. A `Blob`
// created here would need embedding into another file's rewritten source
// as a URL string, but a `blob:` URL is scoped to the realm that called
// `URL.createObjectURL()`: one created in the parent's real origin can't be
// dereferenced from the opaque-origin iframe's own realm, even embedded
// verbatim as a string in a file the iframe otherwise trusts. So instead
// the shell bootstrap does that part itself, from this same raw-source
// data, entirely inside its own realm — @see the `_canvas_preview_bootstrap_ready`
// listener below and `CodeEditorPreviewController`'s shell HTML.
async function discoverFiles(
  url: string,
  files: Map<string, string>,
  inProgress: Map<string, Promise<void>>,
): Promise<void> {
  // `GlobalImports::getImportMap()` returns root-relative URLs
  // (`/modules/...`). `fetch()` resolves those against the page origin
  // implicitly, but the `URL` constructor needs an explicit absolute base
  // to resolve a relative specifier against — so every URL used as a base
  // below has to be made absolute first, or a nested relative import (e.g.
  // `drupal-canvas.js`'s own `./jsonapi-client.js`) throws trying to
  // resolve against it.
  const absoluteUrl = new URL(url, window.location.href).href;
  let pending = inProgress.get(absoluteUrl);
  if (!pending) {
    pending = (async () => {
      const text = await (await fetch(absoluteUrl)).text();
      files.set(absoluteUrl, text);
      const relativeSpecifiers = new Set(
        [...text.matchAll(RELATIVE_IMPORT_PATTERN)].map((match) => match[4]),
      );
      await Promise.all(
        [...relativeSpecifiers].map((specifier) =>
          discoverFiles(
            new URL(specifier, absoluteUrl).href,
            files,
            inProgress,
          ),
        ),
      );
    })();
    inProgress.set(absoluteUrl, pending);
  }
  return pending;
}

// Same reasoning as `getPreviewScriptSource()` above, for the bare-specifier
// dependencies (`preact`, `react`, etc.) the preview script needs to resolve
// its own imports: the shell can't fetch any of these over the network
// either. Fetches the import map itself
// (@see \Drupal\canvas\Controller\CodeEditorPreviewController::importMap()),
// then every entry's own source and (transitively) every file it relatively
// imports — @see `discoverFiles()` for why this stops short of building
// blob URLs itself.
let previewImportMapPromise: Promise<{
  imports: Record<string, string>;
  files: Record<string, string>;
}> | null = null;
function getPreviewImportMap() {
  previewImportMapPromise ??= fetch(
    Drupal.url('canvas/code-editor-preview/import-map'),
  )
    .then((response) => response.json() as Promise<Record<string, string>>)
    .then(async (imports) => {
      const files = new Map<string, string>();
      const inProgress = new Map<string, Promise<void>>();
      await Promise.all(
        Object.values(imports).map((url) =>
          discoverFiles(url, files, inProgress),
        ),
      );
      const resolvedImports = Object.fromEntries(
        Object.entries(imports).map(([specifier, url]) => [
          specifier,
          new URL(url, window.location.href).href,
        ]),
      );
      return { imports: resolvedImports, files: Object.fromEntries(files) };
    });
  return previewImportMapPromise;
}

const Preview = ({ isLoading = false }: { isLoading?: boolean }) => {
  const dispatch = useAppDispatch();
  const componentId = useAppSelector(
    selectCodeComponentProperty('machineName'),
  );
  const sourceCodeJs = useAppSelector(
    selectCodeComponentProperty('sourceCodeJs'),
  );
  const compiledJs = useAppSelector(selectCodeComponentProperty('compiledJs'));
  const compiledCss = useAppSelector(
    selectCodeComponentProperty('compiledCss'),
  );
  const compiledGlobalCss = useAppSelector(
    selectGlobalAssetLibraryProperty(['css', 'compiled']),
  );
  const brandKitFonts = useAppSelector((state) =>
    selectBrandKit<BrandKit['fonts']>(state, 'fonts'),
  );
  const brandKitColors = useAppSelector((state) =>
    selectBrandKit<BrandKit['colors']>(state, 'colors'),
  );
  const previewCompiledJsForSlots = useAppSelector(
    selectPreviewCompiledJsForSlots,
  );
  const { compilationError } = useAppSelector(selectStatus);
  const props = useAppSelector(selectCodeComponentProperty('props'));
  const slots = useAppSelector(selectCodeComponentProperty('slots'));
  const [isDefaultExportMissingError, setIsDefaultExportMissingError] =
    useState(false);
  const iframeRef = useRef<HTMLIFrameElement>(null);
  const parentRef = useRef<HTMLDivElement>(null);
  const [isJsImportError, setIsJsImportError] = useState(false);
  const { data: codeComponents } = useGetCodeComponentsQuery();
  const [jsImportNameWithError, setJsImportNameWithError] = useState('');

  // The `contentWindow` that most recently announced itself ready to
  // receive the payload below (@see the `_canvas_preview_ready` listener).
  // A ref, not state: a sandboxed, opaque-origin iframe's navigation to
  // PREVIEW_SHELL_URL can make the browser swap it through more than one
  // internal frame before settling, each running its own copy of
  // `code-editor-preview.js`. The iframe element's `onload` DOM event fires
  // once per swap and can't tell which firing was the last one, so relying
  // on it to gate sending the payload risks targeting a `contentWindow`
  // that's already been replaced by the next swap — silently dropping the
  // message. Letting each shell instance announce its own readiness instead
  // means whichever instance survives is also the last to announce itself.
  const readyContentWindowRef = useRef<Window | null>(null);

  // Builds the dynamic per-render payload sent to the preview iframe via
  // `postMessage()`. Everything here is data the compiled component needs to
  // render; none of it is trusted to run with the parent's origin/cookies —
  // that's the whole point of sending it to an opaque-origin iframe instead
  // of embedding it in a same-origin document.
  // @see ui/lib/code-editor-preview.js
  const buildPreviewPayload = useCallback(() => {
    const propValues = getPropValuesForPreview(props, brandKitColors);
    const slotNames = getSlotNamesForPreview(slots);
    const previewGlobalColorCss = buildColorStyles(brandKitColors ?? []);
    const previewGlobalFontCss = buildFontFaceStyles(brandKitFonts ?? []);
    const previewGlobalFontPreloads = getFontPreloadDefinitions(
      brandKitFonts ?? [],
    );
    // Remove the `canvas` and `canvasExtension` properties from `drupalSettings`.
    // They are only added for the Canvas UI, and are not available normally.
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    const { canvas, canvasExtension, ...rawDrupalSettings } =
      getDrupalSettings();
    // `getDrupalSettings()` returns the live `window.drupalSettings` object by
    // reference, which may carry non-serializable values unsafe to
    // `postMessage()` directly.
    const drupalSettings = safeSerializeDrupalSettings(rawDrupalSettings);
    // Compiled JS and CSS for all code components except the current one, so
    // the preview can resolve `@/components/{name}` imports and render their
    // styles without an opaque-origin, cookie-authenticated network request.
    // @todo Make this more efficient by introducing better backend support.
    // @see https://drupal.org/i/3520867
    const otherComponents = Object.fromEntries(
      Object.entries(codeComponents ?? {})
        .filter(([, component]) => component.machineName !== componentId)
        .map(([name, component]) => [
          name,
          {
            compiledJs: component.compiledJs ?? '',
            compiledCss: component.compiledCss ?? '',
          },
        ]),
    );
    return {
      compiledJs,
      compiledJsForSlots: previewCompiledJsForSlots,
      previewCss: compiledCss,
      previewGlobalCss: compiledGlobalCss,
      previewGlobalColorCss,
      previewGlobalFontCss,
      previewGlobalFontPreloads,
      otherComponents,
      propValues,
      slotNames,
      drupalSettings,
    };
  }, [
    brandKitColors,
    brandKitFonts,
    codeComponents,
    compiledCss,
    compiledGlobalCss,
    compiledJs,
    componentId,
    previewCompiledJsForSlots,
    props,
    slots,
  ]);

  // Verifies that the component's JS code has a default export.
  const hasDefaultExport = (ast: File) => {
    for (const node of ast.program.body) {
      if (node.type === 'ExportDefaultDeclaration') {
        // Case when JS is a function default export.
        if (node.declaration.type === 'FunctionDeclaration') {
          return true;
        } else if ('name' in node.declaration) {
          // Case when JS is an arrow function default export.
          return true;
        }
      }
    }
    return false;
  };

  // Collects all the JS components imported in the component's JS code.
  const collectImportedJsComponents = useCallback(
    (ast: File) => {
      // Returns an array of all the imports that start with '@/components/'.
      // ex. [ 'my_button', 'my_heading']
      const scope = '@/components/';
      const imports = getImportsFromAst(ast, scope);
      const dataDependencies = getDataDependenciesFromAst(ast);
      dispatch(clearDataFetches());
      dispatch(setCodeComponentProperty(['importedJsComponents', imports]));
      dispatch(
        setCodeComponentProperty(['dataDependencies', dataDependencies]),
      );
      setIsJsImportError(false);
      if (imports.length > 0) {
        imports.map((importName) => {
          if (!codeComponents?.[importName]) {
            setIsJsImportError(true);
            setJsImportNameWithError(importName);
          }
        });
      }
    },
    [codeComponents, dispatch],
  );

  useEffect(() => {
    if (!sourceCodeJs) {
      return;
    }
    try {
      const ast = parse(sourceCodeJs, {
        sourceType: 'module',
        plugins: ['jsx', 'typescript'],
      });
      collectImportedJsComponents(ast);
      if (hasDefaultExport(ast)) {
        setIsDefaultExportMissingError(false);
      } else {
        setIsDefaultExportMissingError(true);
      }
    } catch (error) {
      // This error will also be caught by the JavaScript compiler, and
      // `previewCompilationError` will be set to true in the code editor
      // Redux slice.
      console.error('Error parsing source code:', error);
    }
  }, [sourceCodeJs, collectImportedJsComponents]);

  // Sends the current payload to the preview iframe. Runs on every relevant
  // state change, not just the first: the shell document (loaded once via
  // PREVIEW_SHELL_URL) stays put, and updates reach it live via
  // `postMessage()` instead of a fresh navigation per edit.
  useEffect(() => {
    if (!compiledJs || !readyContentWindowRef.current) {
      return;
    }
    readyContentWindowRef.current.postMessage(buildPreviewPayload(), '*');
  }, [compiledJs, buildPreviewPayload]);

  // Listens for the shell's inline bootstrap announcing that its own message
  // listener is registered, and replies with the actual preview script and
  // its import map so the bootstrap can build local blob URLs for both and
  // dynamically `import()` it — @see `getPreviewScriptSource()` and
  // `getPreviewImportMap()` above for why neither can just be a network
  // fetch from inside the opaque-origin shell itself.
  useEffect(() => {
    const handleBootstrapReady = (event: MessageEvent) => {
      if (
        event.source !== iframeRef.current?.contentWindow ||
        event.data?.type !== '_canvas_preview_bootstrap_ready'
      ) {
        return;
      }
      const target = event.source as Window;
      void Promise.all([getPreviewScriptSource(), getPreviewImportMap()]).then(
        ([source, importMap]) => {
          target.postMessage(
            {
              type: '_canvas_preview_script_source',
              source,
              imports: importMap.imports,
              files: importMap.files,
            },
            '*',
          );
        },
      );
    };
    window.addEventListener('message', handleBootstrapReady);
    return () => window.removeEventListener('message', handleBootstrapReady);
  }, []);

  // Listens for the preview shell announcing itself ready (@see
  // `readyContentWindowRef` above for why this, rather than the iframe's
  // `onload` event, is what gates sending the payload). Sends immediately
  // if a payload is already available, covering the case where the shell
  // becomes ready after `compiledJs` already arrived — the effect above
  // only covers the reverse order.
  useEffect(() => {
    const handleReadyMessage = (event: MessageEvent) => {
      if (
        event.source !== iframeRef.current?.contentWindow ||
        event.data?.type !== '_canvas_preview_ready'
      ) {
        return;
      }
      readyContentWindowRef.current = event.source as Window;
      if (compiledJs) {
        readyContentWindowRef.current.postMessage(buildPreviewPayload(), '*');
      }
    };
    window.addEventListener('message', handleReadyMessage);
    return () => window.removeEventListener('message', handleReadyMessage);
  }, [compiledJs, buildPreviewPayload]);

  // Proxies the preview iframe's own `fetch()` calls (e.g. a component's
  // JSON:API lookups). Its opaque origin can never carry this site's session,
  // so those requests would otherwise always fail with a CORS error; the
  // parent performs the real, authenticated fetch instead, restricted to an
  // allowlist so this can't become a proxy for arbitrary admin-authenticated
  // requests on a compiled component's behalf.
  // @see ui/lib/code-editor-preview.js
  useEffect(() => {
    const handleFetchProxyRequest = async (event: MessageEvent) => {
      if (
        event.source !== iframeRef.current?.contentWindow ||
        event.data?.type !== '_canvas_preview_fetch_request'
      ) {
        return;
      }
      const { id, url, method } = event.data;
      const respond = (payload: object) =>
        iframeRef.current?.contentWindow?.postMessage(
          { type: '_canvas_preview_fetch_response', id, ...payload },
          '*',
        );
      if (method !== 'GET' || !isAllowedPreviewFetchUrl(url)) {
        respond({ status: 403 });
        return;
      }
      try {
        const response = await fetch(url, { credentials: 'same-origin' });
        respond({
          status: response.status,
          headers: {
            'Content-Type':
              response.headers.get('Content-Type') ?? 'application/json',
          },
          body: await response.text(),
        });
      } catch {
        respond({ status: 502 });
      }
    };
    window.addEventListener('message', handleFetchProxyRequest);
    return () => window.removeEventListener('message', handleFetchProxyRequest);
  }, []);

  const renderCompileError = () => (
    <ErrorCard
      title="Error: There was an error compiling your code."
      error="Check your browser's developer console for more details."
    />
  );

  const renderExportMissingError = () => (
    <ErrorCard
      title="Error: Component is missing a default export."
      asChild={true}
    >
      <MissingDefaultExportMessage />
    </ErrorCard>
  );

  const renderImportError = () => {
    const title = `Error: Could not import JS component of id: ${jsImportNameWithError}`;
    return (
      <ErrorCard title={title} asChild={true}>
        <Flex direction="column" gap="3">
          <TextBlock>
            An auto-saved version of this component doesn't exist yet or your
            import statement is using the wrong component id.
          </TextBlock>
          <CodeBlock>import Heading from '@/components/component_id'</CodeBlock>
          <TextBlock>
            To find the correct id for the component you are trying to import,
            open the code editor for that component, and it will be in your
            browser's URL.
          </TextBlock>
        </Flex>
      </ErrorCard>
    );
  };

  const errorComponents = {
    isCompileError: renderCompileError(),
    isDefaultExportMissingError: renderExportMissingError(),
    isJsImportError: renderImportError(),
  };

  const activeErrors = Object.entries({
    isCompileError: compilationError,
    isDefaultExportMissingError,
    isJsImportError,
  })
    .filter(([_, hasError]) => hasError)
    .map(([key]) => key);

  return (
    <div className={styles.iframeContainer} ref={parentRef}>
      {activeErrors.length > 0 && (
        <ScrollArea>
          <div className={styles.errorContainer}>
            {activeErrors.map(
              (key) => errorComponents[key as keyof typeof errorComponents],
            )}
          </div>
        </ScrollArea>
      )}
      {activeErrors.length === 0 && (
        <iframe
          className={styles.iframe}
          title="Canvas Code Editor Preview"
          ref={iframeRef}
          height="100%"
          width="100%"
          src={PREVIEW_SHELL_URL}
          data-canvas-iframe="canvas-code-editor-preview"
          sandbox="allow-scripts"
        />
      )}
      {/* A sibling overlay, not a wrapper: Radix's <Spinner loading> swaps its
          children into a differently-shaped element tree while loading,
          which would otherwise force React to unmount and remount the
          iframe above on every `isLoading` change, silently dropping an
          in-flight preview render. Kept last in the child list so its own
          appearance/disappearance never shifts the iframe's position. */}
      {isLoading && (
        <div className={styles.loadingOverlay}>
          <Spinner loading />
        </div>
      )}
    </div>
  );
};

export default Preview;
