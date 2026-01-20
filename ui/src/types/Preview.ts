import { getCanvasSettings } from '@/utils/drupal-globals';

export interface viewportSize {
  name: string;
  id: string;
  width: number;
  height: number;
}

/**
 * Aspect ratios (height/width) for calculating viewport heights from widths.
 *
 * These ratios are derived from the original default viewport sizes:
 * - mobile: 800/468 ≈ 1.71 (portrait orientation)
 * - tablet: 768/1024 = 0.75 (landscape orientation, 4:3)
 * - desktop: 1080/1920 = 0.5625 (landscape orientation, 16:9)
 * - large_desktop: 1440/2560 = 0.5625 (landscape orientation, 16:9)
 */
const aspectRatios: Record<string, number> = {
  mobile: 800 / 468, // ~1.71 (portrait)
  tablet: 768 / 1024, // 0.75 (4:3 landscape)
  desktop: 1080 / 1920, // 0.5625 (16:9 landscape)
  large_desktop: 1440 / 2560, // 0.5625 (16:9 landscape)
};

/**
 * Name mappings for viewport IDs.
 */
const viewportNames: Record<string, string> = {
  mobile: 'Mobile',
  tablet: 'Tablet',
  desktop: 'Desktop',
  large_desktop: 'Large Desktop',
};

/**
 * Default viewport widths used when no theme configuration is provided.
 */
const defaultViewportWidths: Record<string, number> = {
  mobile: 468,
  tablet: 1024,
  desktop: 1920,
  large_desktop: 2560,
};

/**
 * Creates a viewport size object from a width and viewport ID.
 *
 * Height is calculated dynamically based on the viewport's aspect ratio.
 *
 * @param {string} id
 *   The viewport ID (mobile, tablet, desktop, large_desktop).
 * @param {number} width
 *   The viewport width in pixels.
 *
 * @return {viewportSize}
 *   A viewport size object with calculated height.
 */
function createViewportSize(id: string, width: number): viewportSize {
  const aspectRatio = aspectRatios[id] ?? aspectRatios.desktop;
  const height = Math.round(width * aspectRatio);
  return {
    name: viewportNames[id] || id,
    id,
    width,
    height,
  };
}

/**
 * Gets viewport sizes, using theme configuration if available.
 *
 * Themes can define custom viewport widths in {theme}.canvas.yml:
 * @code
 * viewports:
 *   mobile: 468
 *   tablet: 1024
 *   desktop: 1920
 *   large_desktop: 2560
 * @endcode
 *
 * If theme configuration is provided, it will be used. Otherwise, the default
 * viewport sizes are returned.
 *
 * @return {viewportSize[]}
 *   Array of viewport size objects.
 */
export function getViewportSizes(): viewportSize[] {
  const canvasSettings = getCanvasSettings();
  const customViewports = canvasSettings?.viewports;
  const viewportIds: string[] = [
    'mobile',
    'tablet',
    'desktop',
    'large_desktop',
  ];

  // Fall back to default widths if width is not customized.
  return viewportIds.map((id) => {
    const customWidth = customViewports?.[id];
    let width: number;

    // Check if custom width is provided and valid.
    if (customWidth !== undefined) {
      // Accept both numbers and numeric strings for robustness.
      const numericWidth =
        typeof customWidth === 'string'
          ? parseInt(customWidth, 10)
          : customWidth;
      // Use custom width if it's a valid positive integer.
      if (
        typeof numericWidth === 'number' &&
        !isNaN(numericWidth) &&
        isFinite(numericWidth) &&
        numericWidth > 0 &&
        Number.isInteger(numericWidth)
      ) {
        width = numericWidth;
      } else {
        // Invalid custom width, fall back to default.
        width = defaultViewportWidths[id];
      }
    } else {
      // No custom width provided, use default.
      width = defaultViewportWidths[id];
    }

    return createViewportSize(id, width);
  });
}
