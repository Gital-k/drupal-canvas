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

  const prevValuesRef = useRef({
    aspectRatio: parsed.aspectRatio,
    pixelDensity: parsed.pixelDensity ?? defaultPixelDensity,
    allowMultiple,
    valueMode,
    limitedCount,
  });

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

  useEffect(() => {
    if (Array.isArray(example) && example.length === 0) {
      return;
    }
    if (exampleSrc) {
      const parsedValues = parseExampleSrcRef.current(exampleSrc);
      setAspectRatio(parsedValues.aspectRatio);
      if (parsedValues.pixelDensity) {
        setPixelDensity(parsedValues.pixelDensity);
      }
      prevValuesRef.current = {
        ...prevValuesRef.current,
        aspectRatio: parsedValues.aspectRatio,
        pixelDensity:
          parsedValues.pixelDensity ?? defaultPixelDensityRef.current,
      };
    }
  }, [exampleSrc, example]);

  useEffect(() => {
    if (isInitialMount.current) {
      isInitialMount.current = false;
    }
  }, []);

  useEffect(() => {
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
    if (isInitialMount.current) {
      return;
    }

    const hasChanged =
      prevValuesRef.current.aspectRatio !== aspectRatio ||
      prevValuesRef.current.pixelDensity !== pixelDensity ||
      prevValuesRef.current.allowMultiple !== allowMultiple ||
      prevValuesRef.current.valueMode !== valueMode ||
      prevValuesRef.current.limitedCount !== limitedCount;

    const isEmptyExample = Array.isArray(example) && example.length === 0;
    const isFirstRender = prevValuesRef.current.aspectRatio === null;

    if (!hasChanged && !(isFirstRender && isEmptyExample)) {
      return;
    }

    const aspectOrDensityChanged =
      prevValuesRef.current.aspectRatio !== aspectRatio ||
      prevValuesRef.current.pixelDensity !== pixelDensity;

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

    if (allowMultiple) {
      const currentArray = Array.isArray(example) ? example : [];

      if (valueMode === 'limited') {
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
        let newArray: TExample[];
        if (aspectOrDensityChanged && currentArray.length > 0) {
          newArray = currentArray.map(() => mediaObject);
        } else {
          newArray = currentArray.length > 0 ? currentArray : [mediaObject];
        }
        dispatch(updateProp({ id, updates: { example: newArray as any } }));
      }
    } else {
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
