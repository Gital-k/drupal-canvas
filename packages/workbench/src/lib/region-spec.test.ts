import { describe, expect, it } from 'vitest';

import { normalizeRegionSpec, parseRegionSpec } from './region-spec';

describe('normalizeRegionSpec', () => {
  it('wraps top-level elements in a canvas:component-tree root', () => {
    const normalized = normalizeRegionSpec({
      theme: 'olivero',
      region: 'header',
      elements: {
        'el-1': { type: 'js.logo', props: {} },
        'el-2': { type: 'js.nav', props: {} },
      },
    });

    expect(normalized.id).toBe('olivero.header');
    expect(normalized.spec.root).toBe('canvas:component-tree');
    expect(normalized.spec.elements['canvas:component-tree']).toMatchObject({
      type: 'canvas:component-tree',
      children: ['el-1', 'el-2'],
    });
  });

  it('excludes slot-referenced elements from the synthetic top-level children', () => {
    const normalized = normalizeRegionSpec({
      theme: 'olivero',
      region: 'header',
      elements: {
        root: {
          type: 'js.header',
          props: {},
          slots: { branding: ['logo'] },
        },
        logo: { type: 'js.logo', props: {} },
      },
    });

    expect(
      normalized.spec.elements['canvas:component-tree'].children,
    ).toEqual(['root']);
  });
});

describe('parseRegionSpec', () => {
  it('parses a valid region file', () => {
    const result = parseRegionSpec(
      {
        theme: 'olivero',
        region: 'header',
        elements: {
          a: { type: 'js.logo', props: {} },
        },
      },
      '/tmp/regions/olivero.header.json',
      { componentNames: ['js.logo'] },
    );

    expect(result.issues).toHaveLength(0);
    expect(result.region?.id).toBe('olivero.header');
    expect(result.region?.theme).toBe('olivero');
    expect(result.region?.region).toBe('header');
  });

  it('rejects a region file missing `theme`', () => {
    const result = parseRegionSpec(
      { region: 'header', elements: {} },
      '/tmp/regions/header.json',
    );

    expect(result.region).toBeNull();
    expect(
      result.issues.some((issue) =>
        issue.message.includes('non-empty theme'),
      ),
    ).toBe(true);
  });

  it('rejects a region file missing `region`', () => {
    const result = parseRegionSpec(
      { theme: 'olivero', elements: {} },
      '/tmp/regions/olivero.json',
    );

    expect(result.region).toBeNull();
    expect(
      result.issues.some((issue) =>
        issue.message.includes('non-empty region'),
      ),
    ).toBe(true);
  });

  it('rejects unexpected top-level keys', () => {
    const result = parseRegionSpec(
      {
        theme: 'olivero',
        region: 'header',
        title: 'Header',
        elements: {},
      },
      '/tmp/regions/olivero.header.json',
    );

    expect(
      result.issues.some((issue) =>
        issue.message.includes('unexpected top-level keys'),
      ),
    ).toBe(true);
  });

  it('rejects a region file that defines canvas:component-tree directly', () => {
    const result = parseRegionSpec(
      {
        theme: 'olivero',
        region: 'header',
        elements: {
          'canvas:component-tree': { type: 'canvas:component-tree', props: {} },
        },
      },
      '/tmp/regions/olivero.header.json',
    );

    expect(result.region).toBeNull();
    expect(
      result.issues.some((issue) =>
        issue.message.includes('canvas:component-tree'),
      ),
    ).toBe(true);
  });

  it('rejects elements referencing unknown component types', () => {
    const result = parseRegionSpec(
      {
        theme: 'olivero',
        region: 'header',
        elements: {
          a: { type: 'js.unknown', props: {} },
        },
      },
      '/tmp/regions/olivero.header.json',
      { componentNames: ['js.logo'] },
    );

    expect(result.region).toBeNull();
    expect(result.issues.length).toBeGreaterThan(0);
  });
});
