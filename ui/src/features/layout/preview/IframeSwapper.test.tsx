import { afterEach, describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, waitFor } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import IframeSwapper from '@/features/layout/preview/IframeSwapper';
import {
  CANVAS_REDUCED_MOTION_SCRIPT_MARKER,
  CANVAS_REDUCED_MOTION_STYLE_MARKER,
} from '@/features/layout/preview/reducedMotionEmulation';

describe('IframeSwapper', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    vi.clearAllMocks();
  });

  it('injects reduced-motion markers into preview iframe srcdoc', async () => {
    const setIsReloading = vi.fn();
    const store = makeStore();
    const html = '<!DOCTYPE html><html><head></head><body>x</body></html>';

    render(
      <AppWrapper store={store} location="/" path="/">
        <IframeSwapper
          srcDocument={html}
          setIsReloading={setIsReloading}
          interactive={false}
        />
      </AppWrapper>,
    );

    const iframeB = document.querySelector(
      'iframe[data-canvas-iframe="B"]',
    ) as HTMLIFrameElement;

    await waitFor(() => {
      expect(iframeB.srcdoc).toContain(CANVAS_REDUCED_MOTION_SCRIPT_MARKER);
      expect(iframeB.srcdoc).toContain(CANVAS_REDUCED_MOTION_STYLE_MARKER);
    });
  });

  it('activates the inactive iframe after load', async () => {
    const setIsReloading = vi.fn();
    const store = makeStore();
    const html = '<!DOCTYPE html><html><body>x</body></html>';

    render(
      <AppWrapper store={store} location="/" path="/">
        <IframeSwapper
          srcDocument={html}
          setIsReloading={setIsReloading}
          interactive={false}
        />
      </AppWrapper>,
    );

    const iframeB = document.querySelector(
      'iframe[data-canvas-iframe="B"]',
    ) as HTMLIFrameElement;

    await waitFor(() => {
      expect(iframeB.srcdoc.length).toBeGreaterThan(0);
    });

    await act(async () => {
      fireEvent.load(iframeB);
    });

    await waitFor(() => {
      expect(iframeB.getAttribute('data-canvas-swap-active')).toBe('true');
    });
  });
});
