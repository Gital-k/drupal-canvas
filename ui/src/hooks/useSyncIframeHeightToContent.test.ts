import { describe, expect, test } from 'vitest';

import { getPreviewContentHeight } from './useSyncIframeHeightToContent';

describe('getPreviewContentHeight', () => {
  test('returns the maximum of html/body scroll and offset sizes', () => {
    const documentElement = {
      scrollHeight: 100,
      offsetHeight: 50,
    } as HTMLElement;
    const body = {
      scrollHeight: 220,
      offsetHeight: 30,
    } as HTMLElement;
    expect(getPreviewContentHeight(documentElement, body)).toBe(220);
  });
});
