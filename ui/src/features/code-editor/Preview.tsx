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

  // Whether the shell document (loaded once, from PREVIEW_SHELL_URL) has
  // fired `onload`, i.e. whether `code-editor-preview.js`'s `message`
  // listener is registered and ready to receive the payload below.
  const [isPreviewReady, setIsPreviewReady] = useState(false);

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
    // `getDrupalSettings()` returns the live `window.drupalSettings` object
    // by reference. Other code on the page (e.g. Drupal core's jQuery UI
    // dialog behaviors) can attach non-serializable values to it, such as a
    // live close-handler function, which would otherwise make the
    // postMessage() below throw a DataCloneError. A JSON round-trip strips
    // anything that can't be structured-cloned.
    let drupalSettings: typeof rawDrupalSettings;
    try {
      drupalSettings = JSON.parse(JSON.stringify(rawDrupalSettings));
    } catch {
      drupalSettings = {} as typeof rawDrupalSettings;
    }
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
    if (!compiledJs || !isPreviewReady) {
      return;
    }
    iframeRef.current?.contentWindow?.postMessage(buildPreviewPayload(), '*');
  }, [compiledJs, isPreviewReady, buildPreviewPayload]);

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
    <Spinner loading={isLoading}>
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
            onLoad={() => setIsPreviewReady(true)}
            data-canvas-iframe="canvas-code-editor-preview"
            sandbox="allow-scripts"
          />
        )}
      </div>
    </Spinner>
  );
};

export default Preview;
