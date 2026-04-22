/**
 * @file
 *
 * Auto-saves the code component and global asset library.
 */

import { useEffect, useRef } from 'react';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectCodeComponentProperty,
  selectGlobalAssetLibraryProperty,
  selectStatus,
  setStatus,
} from '@/features/code-editor/codeEditorSlice';
import {
  serializeProps,
  serializeSlots,
} from '@/features/code-editor/utils/utils';
import { selectWasmStatus } from '@/features/ui/uiSlice';
import { useUpdateAutoSaveMutation as updateGlobalAssetLibraryMutation } from '@/services/assetLibrary';
import { useUpdateAutoSaveMutation as updateCodeComponentMutation } from '@/services/componentAndLayout';

const useAutoSave = (requestedComponentId: string): void => {
  const dispatch = useAppDispatch();

  const [updateCodeComponent, { isLoading: isUpdatingCodeComponent }] =
    updateCodeComponentMutation();
  const [
    updateGlobalAssetLibrary,
    { isLoading: isUpdatingGlobalAssetLibrary },
  ] = updateGlobalAssetLibraryMutation();

  // Track previous loading state to detect when saving completes
  const prevIsUpdatingCodeComponentRef = useRef(false);
  const prevIsUpdatingGlobalAssetLibraryRef = useRef(false);

  // The WASM compilers (SWC for JS, Lightning CSS for CSS) drive `compiledJs`
  // and `compiledCss`. If a save completes while WASM is still loading, the
  // payload necessarily carried the previously-known-good compiled output
  // rather than freshly-compiled output for the user's latest source. We must
  // keep `hasUnsavedChanges` true in that case so the SavingOverlay continues
  // to block navigation until the upcoming compile-and-save cycle completes.
  const { isCssWasmAvailable, isJsWasmAvailable } =
    useAppSelector(selectWasmStatus);
  const isWasmReady = isCssWasmAvailable && isJsWasmAvailable;

  useEffect(() => {
    const isSaving = isUpdatingCodeComponent || isUpdatingGlobalAssetLibrary;
    dispatch(
      setStatus({
        isSaving,
      }),
    );
    const wasSaving =
      prevIsUpdatingCodeComponentRef.current ||
      prevIsUpdatingGlobalAssetLibraryRef.current;
    // Only reset the hasUnsavedChanges flag after a save operation completes
    // and is currently not saving — and only once the WASM compilers are
    // ready, since otherwise the payload that just landed on the server pairs
    // the latest source with the previously-known-good compiled output and a
    // follow-up save with the freshly-compiled output is still pending.
    if (wasSaving && !isSaving && isWasmReady) {
      dispatch(setStatus({ hasUnsavedChanges: false }));
    }
    // Update previous state references
    prevIsUpdatingCodeComponentRef.current = isUpdatingCodeComponent;
    prevIsUpdatingGlobalAssetLibraryRef.current = isUpdatingGlobalAssetLibrary;
  }, [
    isUpdatingCodeComponent,
    isUpdatingGlobalAssetLibrary,
    isWasmReady,
    dispatch,
  ]);

  const componentId = useAppSelector(
    selectCodeComponentProperty('machineName'),
  );
  const sourceCodeJs = useAppSelector(
    selectCodeComponentProperty('sourceCodeJs'),
  );
  const compiledJs = useAppSelector(selectCodeComponentProperty('compiledJs'));
  const sourceCodeCss = useAppSelector(
    selectCodeComponentProperty('sourceCodeCss'),
  );
  const compiledCss = useAppSelector(
    selectCodeComponentProperty('compiledCss'),
  );
  const props = useAppSelector(selectCodeComponentProperty('props'));
  const slots = useAppSelector(selectCodeComponentProperty('slots'));
  const required = useAppSelector(selectCodeComponentProperty('required'));
  const dataDependencies = useAppSelector(
    selectCodeComponentProperty('dataDependencies'),
  );

  const globalSourceCodeCss = useAppSelector(
    selectGlobalAssetLibraryProperty(['css', 'original']),
  );
  const globalCompiledCss = useAppSelector(
    selectGlobalAssetLibraryProperty(['css', 'compiled']),
  );
  const globalSourceCodeJs = useAppSelector(
    selectGlobalAssetLibraryProperty(['js', 'original']),
  );
  const globalCompiledJs = useAppSelector(
    selectGlobalAssetLibraryProperty(['js', 'compiled']),
  );

  // Track the values in refs which we need in the effect that auto-saves the
  // code component, but which we don't want to trigger the auto-save.
  const { needsAutoSave } = useAppSelector(selectStatus);
  const needsAutoSaveRef = useRef(false);
  useEffect(() => {
    needsAutoSaveRef.current = needsAutoSave;
  }, [needsAutoSave]);
  const componentStatus = useAppSelector(selectCodeComponentProperty('status'));
  const componentStatusRef = useRef(false);
  useEffect(() => {
    componentStatusRef.current = componentStatus;
  }, [componentStatus]);
  const componentName = useAppSelector(selectCodeComponentProperty('name'));
  const componentNameRef = useRef<string>('');
  useEffect(() => {
    componentNameRef.current = componentName;
  }, [componentName]);
  const importedJsComponents = useAppSelector(
    selectCodeComponentProperty('importedJsComponents'),
  );
  const importedJsComponentsRef = useRef<string[]>([]);
  useEffect(() => {
    importedJsComponentsRef.current = importedJsComponents;
  }, [importedJsComponents]);

  const lastInvocationCodeComponentTimeoutRef = useRef<NodeJS.Timeout | null>(
    null,
  );
  const lastInvocationGlobalCssTimeoutRef = useRef<NodeJS.Timeout | null>(null);

  // Auto-save: code component changes.
  useEffect(() => {
    if (
      requestedComponentId !== componentId ||
      !componentId ||
      !needsAutoSaveRef.current
    ) {
      return;
    }
    if (lastInvocationCodeComponentTimeoutRef.current) {
      clearTimeout(lastInvocationCodeComponentTimeoutRef.current);
    }
    lastInvocationCodeComponentTimeoutRef.current = setTimeout(() => {
      updateCodeComponent({
        id: componentId,
        data: {
          status: componentStatusRef.current,
          name: componentNameRef.current,
          machineName: componentId,
          sourceCodeJs,
          sourceCodeCss,
          compiledJs,
          compiledCss,
          props: serializeProps(props),
          slots: serializeSlots(slots),
          required,
          importedJsComponents: importedJsComponentsRef.current,
          dataDependencies,
        },
      });
    }, 1000);
    return () => {
      if (lastInvocationCodeComponentTimeoutRef.current) {
        clearTimeout(lastInvocationCodeComponentTimeoutRef.current);
      }
    };
  }, [
    compiledCss,
    compiledJs,
    componentId,
    props,
    requestedComponentId,
    required,
    slots,
    sourceCodeCss,
    sourceCodeJs,
    dataDependencies,
    updateCodeComponent,
  ]);

  // Auto-save: global asset library changes.
  useEffect(() => {
    if (
      requestedComponentId !== componentId ||
      !componentId ||
      !needsAutoSaveRef.current
    ) {
      return;
    }
    if (lastInvocationGlobalCssTimeoutRef.current) {
      clearTimeout(lastInvocationGlobalCssTimeoutRef.current);
    }
    lastInvocationGlobalCssTimeoutRef.current = setTimeout(() => {
      updateGlobalAssetLibrary({
        id: 'global',
        data: {
          css: {
            compiled: globalCompiledCss,
            original: globalSourceCodeCss,
          },
          js: {
            original: globalSourceCodeJs,
            compiled: globalCompiledJs,
          },
        },
      });
    }, 1000);
    return () => {
      if (lastInvocationGlobalCssTimeoutRef.current) {
        clearTimeout(lastInvocationGlobalCssTimeoutRef.current);
      }
    };
  }, [
    componentId,
    globalCompiledCss,
    globalCompiledJs,
    globalSourceCodeCss,
    globalSourceCodeJs,
    requestedComponentId,
    updateGlobalAssetLibrary,
  ]);

  // Reset the hasUnsavedChanges flag when auto-save is not needed.
  useEffect(() => {
    if (!needsAutoSave) {
      dispatch(setStatus({ hasUnsavedChanges: false }));
    }
  }, [dispatch, needsAutoSave]);
};

export default useAutoSave;
