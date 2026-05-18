import { useEffect, useRef, useState } from 'react';

import { updateProp } from '@/features/code-editor/codeEditorSlice';

import type { AppDispatch } from '@/app/store';
import type { CodeComponentProp, ValueMode } from '@/types/CodeComponent';

interface AspectRatioEntry {
  value: string;
  label: string;
  width: number;
  height: number;
}

export interface UseMediaPropEffectOptions<
  TExample,
  TAspectRatioEntry extends AspectRatioEntry,
> {
  id: CodeComponentProp['id'];
  example: TExample | TExample[];
  required: boolean;
  allowMultiple: boolean;
  valueMode: ValueMode;
  limitedCount: number;
  aspectRatioValues: TAspectRatioEntry[];
  defaultAspectRatio: string;
  noneValue: string;
  parseExampleSrc: (src: string) => {
    aspectRatio: string;
    pixelDensity?: string;
  };
  getExampleSrc: (example: TExample) => string;
  buildMediaObject: (
    aspectRatioData: TAspectRatioEntry,
    pixelDensity: string,
    aspectOrDensityChanged: boolean,
  ) => TExample;
  pixelDensityOptions?: Array<{ value: string; label: string }>;
  defaultPixelDensity?: string;
}

export function useMediaPropEffect<
  TExample,
  TAspectRatioEntry extends AspectRatioEntry,
>(
  dispatch: AppDispatch,
  options: UseMediaPropEffectOptions<TExample, TAspectRatioEntry>,
) {
  const {
    id,
    example,
    required,
    allowMultiple,
    valueMode,
    limitedCount,
    aspectRatioValues,
    defaultAspectRatio,
    noneValue,
    parseExampleSrc,
    getExampleSrc,
    buildMediaObject,
    defaultPixelDensity = '1x',
  } = options;

  const mediaExample = Array.isArray(example)
    ? (example[0] ?? null)
    : (example ?? null);

  const exampleSrc = mediaExample
    ? getExampleSrc(mediaExample as TExample)
    : '';
  const parsed = parseExampleSrc(exampleSrc);

  const [aspectRatio, setAspectRatio] = useState(parsed.aspectRatio);
  const [pixelDensity, setPixelDensity] = useState(
    parsed.pixelDensity ?? defaultPixelDensity,
  );
  const [localRequired, setLocalRequired] = useState(required);

  // Use a ref to track the previous values of the UI-controlled inputs (aspectRatio,
  // pixelDensity) and the multi-value settings (allowMultiple, valueMode, limitedCount).
  //
  // Why this is needed to prevent an infinite loop:
  //   1. The main useEffect below reads these values and dispatches `updateProp`, which
  //      writes a new `example` object/array back into the Redux store.
  //   2. `example` is a dependency of that same effect (needed to build the correct
  //      multi-value array in unlimited mode).
  //   3. Without a guard, each dispatch → new `example` reference → effect re-runs →
  //      another dispatch → … repeating forever.
  //
  // By comparing the current UI-input values against the ref before dispatching, the
  // effect exits early when nothing the user actually changed, breaking the cycle.
  const prevValuesRef = useRef({
    aspectRatio: parsed.aspectRatio,
    pixelDensity: parsed.pixelDensity ?? defaultPixelDensity,
    allowMultiple,
    valueMode,
    limitedCount,
  });

  // Track if we're still initializing (on first mount)
  const isInitialMount = useRef(true);

  const parseExampleSrcRef = useRef(parseExampleSrc);
  parseExampleSrcRef.current = parseExampleSrc;

  const defaultPixelDensityRef = useRef(defaultPixelDensity);
  defaultPixelDensityRef.current = defaultPixelDensity;

  const noneValueRef = useRef(noneValue);
  noneValueRef.current = noneValue;

  const defaultAspectRatioRef = useRef(defaultAspectRatio);
  defaultAspectRatioRef.current = defaultAspectRatio;

  const aspectRatioValuesRef = useRef(aspectRatioValues);
  aspectRatioValuesRef.current = aspectRatioValues;

  const buildMediaObjectRef = useRef(buildMediaObject);
  buildMediaObjectRef.current = buildMediaObject;

  // Sync state with incoming example prop (for pre-population on reload)
  useEffect(() => {
    // If example is empty array, keep current aspectRatio (don't reset to default)
    if (Array.isArray(example) && example.length === 0) {
      return;
    }
    // If example has valid content, parse and update state
    if (exampleSrc) {
      const parsedValues = parseExampleSrcRef.current(exampleSrc);
      setAspectRatio(parsedValues.aspectRatio);
      if (parsedValues.pixelDensity) {
        setPixelDensity(parsedValues.pixelDensity);
      }
      // Also update the ref so the main effect doesn't trigger on mount
      prevValuesRef.current = {
        ...prevValuesRef.current,
        aspectRatio: parsedValues.aspectRatio,
        pixelDensity:
          parsedValues.pixelDensity ?? defaultPixelDensityRef.current,
      };
    }
  }, [exampleSrc, example]);

  useEffect(() => {
    // Mark as no longer initial mount after first render
    if (isInitialMount.current) {
      isInitialMount.current = false;
    }
  }, []);

  useEffect(() => {
    // Track changes to the required prop, update aspect ratio if needed.
    setLocalRequired(required);
    if (
      required !== localRequired &&
      required &&
      aspectRatio === noneValueRef.current
    ) {
      setAspectRatio(defaultAspectRatioRef.current);
    }
  }, [required, localRequired, aspectRatio]);

  useEffect(() => {
    // Skip on initial mount - let the sync effect handle loading saved values
    if (isInitialMount.current) {
      return;
    }

    // Check if any relevant values have changed
    const hasChanged =
      prevValuesRef.current.aspectRatio !== aspectRatio ||
      prevValuesRef.current.pixelDensity !== pixelDensity ||
      prevValuesRef.current.allowMultiple !== allowMultiple ||
      prevValuesRef.current.valueMode !== valueMode ||
      prevValuesRef.current.limitedCount !== limitedCount;

    // Check if we need to initialize with a default value on first mount
    const isEmptyExample = Array.isArray(example) && example.length === 0;
    const isFirstRender = prevValuesRef.current.aspectRatio === null;

    // Only run if values actually changed OR if this is first render with empty example
    if (!hasChanged && !(isFirstRender && isEmptyExample)) {
      return;
    }

    // Detect if aspect ratio or pixel density changed (not just mode switches)
    const aspectOrDensityChanged =
      prevValuesRef.current.aspectRatio !== aspectRatio ||
      prevValuesRef.current.pixelDensity !== pixelDensity;

    // Update ref with new values
    prevValuesRef.current = {
      aspectRatio,
      pixelDensity,
      allowMultiple,
      valueMode,
      limitedCount,
    };

    if (aspectRatio === noneValueRef.current) {
      dispatch(
        updateProp({
          id,
          updates: { example: allowMultiple ? [] : '' },
        }),
      );
      return;
    }

    const aspectRatioData =
      aspectRatioValuesRef.current.find((r) => r.value === aspectRatio) ??
      aspectRatioValuesRef.current[0];

    const mediaObject = buildMediaObjectRef.current(
      aspectRatioData,
      pixelDensity,
      aspectOrDensityChanged,
    );

    // For multi-value mode, create an array
    if (allowMultiple) {
      const currentArray = Array.isArray(example) ? example : [];

      // For limited mode, ensure we have exactly limitedCount items
      if (valueMode === 'limited') {
        // Update all items to use the new mediaObject (aspect ratio/pixel density changes should apply to all)
        dispatch(
          updateProp({
            id,
            updates: {
              example: Array.from(
                { length: limitedCount },
                () => mediaObject,
              ) as any,
            },
          }),
        );
      } else {
        // For unlimited mode:
        // - If aspect ratio or pixel density changed, update all existing items
        // - Otherwise, keep existing array or create one with single item
        let newArray: TExample[];
        if (aspectOrDensityChanged && currentArray.length > 0) {
          // Update all existing items with new aspect ratio/pixel density
          newArray = currentArray.map(() => mediaObject);
        } else {
          // Keep existing items or create initial item
          newArray = currentArray.length > 0 ? currentArray : [mediaObject];
        }
        dispatch(updateProp({ id, updates: { example: newArray as any } }));
      }
    } else {
      // Single value mode
      dispatch(updateProp({ id, updates: { example: mediaObject as any } }));
    }
  }, [
    aspectRatio,
    pixelDensity,
    dispatch,
    id,
    allowMultiple,
    valueMode,
    limitedCount,
    example,
  ]);

  return {
    aspectRatio,
    setAspectRatio,
    pixelDensity,
    setPixelDensity,
  };
}
