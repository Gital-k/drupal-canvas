import { Box, Flex, Select } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import {
  Divider,
  FormElement,
  Label,
} from '@/features/code-editor/component-data/FormElement';
import { useMediaPropEffect } from '@/features/code-editor/hooks/useMediaPropEffect';

import type {
  CodeComponentProp,
  CodeComponentPropImageExample,
  ValueMode,
} from '@/types/CodeComponent';

const IMAGE_SERVICE_URL = 'https://placehold.co/';

const NONE_VALUE = '_none_';
const EXAMPLE_ASPECT_RATIO_VALUES = [
  { value: '1:1', label: '1:1 (Square)', width: 600, height: 600 },
  { value: '4:3', label: '4:3 (Standard)', width: 800, height: 600 },
  { value: '16:9', label: '16:9 (Widescreen)', width: 1280, height: 720 },
  { value: '3:2', label: '3:2 (Classic Photo)', width: 900, height: 600 },
  { value: '2:1', label: '2:1 (Panoramic)', width: 1000, height: 500 },
  { value: '9:16', label: '9:16 (Vertical)', width: 720, height: 1280 },
  { value: '21:9', label: '21:9 (Ultrawide)', width: 1400, height: 600 },
];
const DEFAULT_ASPECT_RATIO = EXAMPLE_ASPECT_RATIO_VALUES[1].value;

const EXAMPLE_PIXEL_DENSITY_OPTIONS = [
  { value: '1x', label: '1x (Standard density)' },
  { value: '2x', label: '2x (High density)' },
  { value: '3x', label: '3x (Ultra-high density)' },
];
const DEFAULT_PIXEL_DENSITY = EXAMPLE_PIXEL_DENSITY_OPTIONS[1].value;

export const parseExampleSrc = (
  src: string,
): { aspectRatio: string; pixelDensity: string } => {
  const defaults = {
    aspectRatio: DEFAULT_ASPECT_RATIO,
    pixelDensity: DEFAULT_PIXEL_DENSITY,
  };

  if (!src || !src.startsWith(IMAGE_SERVICE_URL)) {
    return defaults;
  }

  try {
    const match = src.match(/(\d+)x(\d+)(?:@(\d+)x)?/);
    if (!match) return defaults;

    const [, width, height, density = '1'] = match;

    const aspectRatio =
      EXAMPLE_ASPECT_RATIO_VALUES.find(
        (ratio) =>
          ratio.width === Number(width) && ratio.height === Number(height),
      )?.value || DEFAULT_ASPECT_RATIO;

    const pixelDensity = `${density}x`;
    if (
      !EXAMPLE_PIXEL_DENSITY_OPTIONS.some(
        (option) => option.value === pixelDensity,
      )
    ) {
      return { aspectRatio, pixelDensity: DEFAULT_PIXEL_DENSITY };
    }

    return { aspectRatio, pixelDensity };
  } catch (error) {
    console.error('Error parsing example URL:', error);
    return defaults;
  }
};

export default function FormPropTypeImage({
  id,
  example,
  required,
  allowMultiple = false,
  valueMode = 'unlimited',
  limitedCount = 1,
}: Pick<CodeComponentProp, 'id'> & {
  example: CodeComponentPropImageExample | CodeComponentPropImageExample[];
  required: boolean;
  allowMultiple?: boolean;
  valueMode?: ValueMode;
  limitedCount?: number;
}) {
  const dispatch = useAppDispatch();

  const { aspectRatio, setAspectRatio, pixelDensity, setPixelDensity } =
    useMediaPropEffect(dispatch, {
      id,
      example,
      required,
      allowMultiple,
      valueMode,
      limitedCount,
      aspectRatioValues: EXAMPLE_ASPECT_RATIO_VALUES,
      defaultAspectRatio: DEFAULT_ASPECT_RATIO,
      noneValue: NONE_VALUE,
      parseExampleSrc,
      getExampleSrc: (ex) => ex.src,
      buildMediaObject: (aspectRatioData, density) => {
        const pixelDensitySuffix = density === '1x' ? '' : `@${density}`;
        const alternateWidths = `${IMAGE_SERVICE_URL}{width}x{height}${pixelDensitySuffix}.png`;
        return {
          src: `${IMAGE_SERVICE_URL}${aspectRatioData.width}x${aspectRatioData.height}${pixelDensitySuffix}.png?alternateWidths=${encodeURIComponent(alternateWidths)}`,
          width: aspectRatioData.width,
          height: aspectRatioData.height,
          alt: 'Example image placeholder',
        } as CodeComponentPropImageExample;
      },
      pixelDensityOptions: EXAMPLE_PIXEL_DENSITY_OPTIONS,
      defaultPixelDensity: DEFAULT_PIXEL_DENSITY,
    });

  return (
    <Flex direction="column" gap="4" flexGrow="1">
      <Divider />
      <Flex gap="4" width="100%">
        <Box flexBasis="50%" flexShrink="0">
          <FormElement>
            <Label htmlFor={`prop-example-${id}`}>Example aspect ratio</Label>
            <Select.Root
              value={aspectRatio}
              onValueChange={setAspectRatio}
              size="1"
            >
              <Select.Trigger id={`prop-example-${id}`} />
              <Select.Content>
                {!required && (
                  <Select.Item value={NONE_VALUE}>- None -</Select.Item>
                )}
                {EXAMPLE_ASPECT_RATIO_VALUES.map((value) => (
                  <Select.Item key={value.value} value={value.value}>
                    {value.label}
                  </Select.Item>
                ))}
              </Select.Content>
            </Select.Root>
          </FormElement>
        </Box>
        {aspectRatio !== NONE_VALUE && (
          <Box flexGrow="1">
            <FormElement>
              <Label htmlFor={`prop-example-pixel-density-${id}`}>
                Pixel density
              </Label>
              <Select.Root
                value={pixelDensity}
                onValueChange={setPixelDensity}
                size="1"
              >
                <Select.Trigger id={`prop-example-pixel-density-${id}`} />
                <Select.Content>
                  {EXAMPLE_PIXEL_DENSITY_OPTIONS.map((value) => (
                    <Select.Item key={value.value} value={value.value}>
                      {value.label}
                    </Select.Item>
                  ))}
                </Select.Content>
              </Select.Root>
            </FormElement>
          </Box>
        )}
      </Flex>
    </Flex>
  );
}
