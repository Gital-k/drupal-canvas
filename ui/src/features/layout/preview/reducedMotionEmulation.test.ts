import { JSDOM } from 'jsdom';
import { afterEach, describe, expect, it } from 'vitest';

import {
  CANVAS_REDUCED_MOTION_LATE_STYLE_MARKER,
  CANVAS_REDUCED_MOTION_SCRIPT_MARKER,
  CANVAS_REDUCED_MOTION_STYLE_MARKER,
  injectReducedMotionEmulation,
} from '@/features/layout/preview/reducedMotionEmulation';

import type { DOMWindow } from 'jsdom';

describe('injectReducedMotionEmulation', () => {
  it('inserts script and early style after opening head, and late style before closing head', () => {
    const html =
      '<!DOCTYPE html><html><head><title>t</title></head><body>x</body></html>';
    const out = injectReducedMotionEmulation(html);

    expect(out).toContain('<!DOCTYPE html>');
    expect(out).toContain('<title>t</title>');
    expect(out).toContain(CANVAS_REDUCED_MOTION_SCRIPT_MARKER);
    expect(out).toContain(CANVAS_REDUCED_MOTION_STYLE_MARKER);
    expect(out).toContain(CANVAS_REDUCED_MOTION_LATE_STYLE_MARKER);
    expect(out).toContain('animation: none !important');
    expect(out).toContain('transition: none !important');
    expect(out).toContain("'transform', 'none'");
    expect(out).toContain("'opacity', '1'");
    expect(out).not.toContain(
      'suppressInlineMotionTree(document.documentElement)',
    );
    expect(out).toContain('data-canvas-preview-reduced-motion');
    expect(out).toContain('__canvasRMWrapped');

    const headOpen = out.indexOf('<head>');
    const scriptIdx = out.indexOf(CANVAS_REDUCED_MOTION_SCRIPT_MARKER);
    const headClose = out.toLowerCase().indexOf('</head>');
    const styleIdx = out.indexOf(CANVAS_REDUCED_MOTION_STYLE_MARKER);
    const lateStyleIdx = out.indexOf(CANVAS_REDUCED_MOTION_LATE_STYLE_MARKER);

    expect(headOpen).toBeGreaterThanOrEqual(0);
    expect(scriptIdx).toBeGreaterThan(headOpen);
    expect(styleIdx).toBeGreaterThan(scriptIdx);
    expect(headClose).toBeGreaterThan(0);
    expect(lateStyleIdx).toBeLessThan(headClose);
    expect(lateStyleIdx).toBeGreaterThan(styleIdx);
  });

  it('inserts after html when head is missing', () => {
    const html = '<!DOCTYPE html><html><body>y</body></html>';
    const out = injectReducedMotionEmulation(html);

    expect(out).toContain('<!DOCTYPE html>');
    expect(out).toContain('<body>y</body>');
    expect(out).toContain(CANVAS_REDUCED_MOTION_SCRIPT_MARKER);
    expect(out).toContain(CANVAS_REDUCED_MOTION_STYLE_MARKER);
    expect(out).toContain(CANVAS_REDUCED_MOTION_LATE_STYLE_MARKER);

    const htmlOpen = out.match(/<html\b[^>]*>/i);
    expect(htmlOpen).toBeTruthy();
    const afterHtml = out.indexOf('>', out.indexOf('<html'));
    const scriptIdx = out.indexOf(CANVAS_REDUCED_MOTION_SCRIPT_MARKER);
    expect(scriptIdx).toBeGreaterThan(afterHtml);
  });

  it('prepends when there is no head or html element', () => {
    const html = '<body>only</body>';
    const out = injectReducedMotionEmulation(html);

    expect(out.startsWith('<script')).toBe(true);
    expect(out).toContain(CANVAS_REDUCED_MOTION_SCRIPT_MARKER);
    expect(out).toContain(CANVAS_REDUCED_MOTION_STYLE_MARKER);
    expect(out).toContain('<body>only</body>');
  });

  it('is idempotent when markers are already present', () => {
    const html = '<html><head></head><body></body></html>';
    const once = injectReducedMotionEmulation(html);
    const twice = injectReducedMotionEmulation(once);

    expect(twice).toBe(once);
  });

  it('returns empty string unchanged', () => {
    expect(injectReducedMotionEmulation('')).toBe('');
  });
});

describe('injectReducedMotionEmulation runtime (JSDOM)', () => {
  const windows: Array<DOMWindow> = [];

  afterEach(() => {
    windows.forEach((w) => w.close());
    windows.length = 0;
  });

  async function waitForReducedMotionRuntime(win: DOMWindow) {
    await new Promise<void>((resolve) => {
      if (win.document.readyState !== 'loading') {
        resolve();
        return;
      }
      win.document.addEventListener('DOMContentLoaded', () => resolve(), {
        once: true,
      });
    });
  }

  async function flushObserver(): Promise<void> {
    await Promise.resolve();
    await Promise.resolve();
  }

  it('does not scrub static inline transform present before runtime starts observing', () => {
    const html = injectReducedMotionEmulation(
      '<!DOCTYPE html><html><head></head><body><div id="keep" style="transform: rotate(45deg)">x</div></body></html>',
    );
    const dom = new JSDOM(html, {
      url: 'https://canvas-preview.test/',
      runScripts: 'dangerously',
      pretendToBeVisual: true,
    });
    windows.push(dom.window);

    const el = dom.window.document.getElementById('keep')!;
    expect(el.style.getPropertyValue('transform')).toContain('rotate(45deg)');
  });

  it('scrubs inline transform after a style attribute mutation', async () => {
    const html = injectReducedMotionEmulation(
      '<!DOCTYPE html><html><head></head><body><div id="dyn">x</div></body></html>',
    );
    const dom = new JSDOM(html, {
      url: 'https://canvas-preview.test/',
      runScripts: 'dangerously',
      pretendToBeVisual: true,
    });
    windows.push(dom.window);

    await waitForReducedMotionRuntime(dom.window);

    const el = dom.window.document.getElementById('dyn')!;
    el.setAttribute('style', 'transform: translateX(12px); opacity: 0.4');
    await flushObserver();

    expect(el.style.getPropertyValue('transform')).toBe('none');
    expect(el.style.getPropertyPriority('transform')).toBe('important');
    expect(el.style.getPropertyValue('opacity')).toBe('1');
    expect(el.style.getPropertyPriority('opacity')).toBe('important');
  });

  it('scrubs inline motion on subtrees added after load', async () => {
    const html = injectReducedMotionEmulation(
      '<!DOCTYPE html><html><head></head><body><div id="root"></div></body></html>',
    );
    const dom = new JSDOM(html, {
      url: 'https://canvas-preview.test/',
      runScripts: 'dangerously',
      pretendToBeVisual: true,
    });
    windows.push(dom.window);

    await waitForReducedMotionRuntime(dom.window);

    const mount = dom.window.document.createElement('div');
    mount.innerHTML =
      '<span class="a" style="transform: scale(1.05)">nested</span>';
    dom.window.document.getElementById('root')!.appendChild(mount);
    await flushObserver();

    const span = dom.window.document.querySelector('span.a') as HTMLElement;
    expect(span.style.getPropertyValue('transform')).toBe('none');
    expect(span.style.getPropertyPriority('transform')).toBe('important');
  });
});
