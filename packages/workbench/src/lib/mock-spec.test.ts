import { describe, expect, it } from 'vitest';

import { validateMockSpecArray } from './mock-spec';

describe('mock-spec', () => {
  it('parses valid mock spec arrays', () => {
    const result = validateMockSpecArray(
      [
        {
          root: 'root',
          elements: {
            root: {
              type: 'js.card',
              props: {},
            },
          },
        },
      ],
      '/tmp/components/card/mocks.json',
    );

    expect(result.specs).toHaveLength(1);
    expect(result.warnings).toEqual([]);
  });

  it('returns warning for non-array files', () => {
    const result = validateMockSpecArray(
      {
        root: 'root',
        elements: {},
      },
      '/tmp/components/card/mocks.json',
    );

    expect(result.specs).toEqual([]);
    expect(result.warnings).toEqual([
      {
        code: 'invalid_mock_spec_array',
        message:
          'Mock file must contain an array of json-render specs: /tmp/components/card/mocks.json',
        path: '/tmp/components/card/mocks.json',
      },
    ]);
  });

  it('warns and skips invalid entries in mixed arrays', () => {
    const result = validateMockSpecArray(
      [
        {
          root: 'root',
          elements: {
            root: {
              type: 'js.card',
            },
          },
        },
        {
          root: 5,
          elements: {},
        },
      ],
      '/tmp/components/card/mocks.json',
    );

    expect(result.specs).toHaveLength(1);
    expect(result.warnings).toEqual([
      {
        code: 'invalid_mock_spec_entry',
        message:
          'Mock spec entry at index 1 is invalid in /tmp/components/card/mocks.json.',
        path: '/tmp/components/card/mocks.json',
      },
    ]);
  });

  it('validates mock entries against json-render catalog component types', () => {
    const result = validateMockSpecArray(
      [
        {
          root: 'root',
          elements: {
            root: {
              type: 'unknown-component',
              props: {},
            },
          },
        },
      ],
      '/tmp/components/card/mocks.json',
      { componentNames: ['card'] },
    );

    expect(result.specs).toEqual([]);
    expect(result.warnings).toHaveLength(1);
    expect(result.warnings[0].code).toBe('invalid_mock_spec_entry');
  });

  it('accepts plain and js-prefixed discovered component names', () => {
    const result = validateMockSpecArray(
      [
        {
          root: 'a',
          elements: {
            a: {
              type: 'pricing-table',
              props: {},
            },
          },
        },
        {
          root: 'b',
          elements: {
            b: {
              type: 'js.pricing_table',
              props: {},
            },
          },
        },
      ],
      '/tmp/components/pricing-table/mocks.json',
      { componentNames: ['pricing-table'] },
    );

    expect(result.specs).toHaveLength(2);
    expect(result.warnings).toEqual([]);
  });
});
