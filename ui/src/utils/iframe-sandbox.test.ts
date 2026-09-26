import { describe, expect, it } from 'vitest';

import { getIframeSandbox } from './iframe-sandbox';

describe('getIframeSandbox', () => {
  it('adds allow-same-origin for a same-origin absolute URL', () => {
    const sameOriginUrl = `${window.location.origin}/some/extension/index.html`;
    expect(getIframeSandbox(sameOriginUrl, 'allow-scripts')).toBe(
      'allow-scripts allow-same-origin',
    );
  });

  it('adds allow-same-origin for a relative URL', () => {
    expect(getIframeSandbox('index.html', 'allow-scripts')).toBe(
      'allow-scripts allow-same-origin',
    );
  });

  it('omits allow-same-origin for a cross-origin absolute URL', () => {
    expect(
      getIframeSandbox('https://example.com/index.html', 'allow-scripts'),
    ).toBe('allow-scripts');
  });

  it('omits allow-same-origin for an unparseable URL', () => {
    expect(getIframeSandbox('https://[not-a-valid-host', 'allow-scripts')).toBe(
      'allow-scripts',
    );
  });

  it('returns the base tokens unchanged when no URL is given', () => {
    expect(getIframeSandbox(undefined, 'allow-scripts')).toBe('allow-scripts');
    expect(getIframeSandbox(null, 'allow-scripts')).toBe('allow-scripts');
    expect(getIframeSandbox('', 'allow-scripts')).toBe('allow-scripts');
  });
});
