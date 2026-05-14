import { describe, expect, it } from 'vitest';

import { extractErrorMessageFromApiResponse } from '@/features/error-handling/error-handling';

describe('extractErrorMessageFromApiResponse', () => {
  it('returns a fallback message when errors are absent or empty', () => {
    expect(extractErrorMessageFromApiResponse(undefined)).toBe(
      'Error occurred, see browser console for more details.',
    );
    expect(extractErrorMessageFromApiResponse({ data: { errors: [] } })).toBe(
      'Error occurred, see browser console for more details.',
    );
  });

  it('returns a plain string for a single JSON:API-style violation', () => {
    const error = {
      data: {
        errors: [
          {
            detail: 'The name field is required.',
            source: { pointer: 'name' },
          },
        ],
      },
    };
    expect(extractErrorMessageFromApiResponse(error)).toBe(
      'The name field is required.',
    );
  });

  it('returns an HTML <ul> list for multiple JSON:API-style violations', () => {
    const error = {
      data: {
        errors: [
          {
            detail: 'The name field is required.',
            source: { pointer: 'name' },
          },
          {
            detail: 'The source code is invalid.',
            source: { pointer: 'sourceCodeJs' },
          },
        ],
      },
    };
    const result = extractErrorMessageFromApiResponse(error);
    expect(result).toContain('<ul>');
    expect(result).toContain('<li>The name field is required.</li>');
    expect(result).toContain('<li>The source code is invalid.</li>');
  });

  it('deduplicates exact duplicate errors (same pointer AND same message)', () => {
    const error = {
      data: {
        errors: [
          {
            detail: 'The name field is required.',
            source: { pointer: 'name' },
          },
          {
            detail: 'The name field is required.',
            source: { pointer: 'name' },
          },
          {
            detail: 'Source code is invalid.',
            source: { pointer: 'sourceCodeJs' },
          },
        ],
      },
    };
    const result = extractErrorMessageFromApiResponse(error);
    const count = (result.match(/The name field is required\./g) ?? []).length;
    expect(count).toBe(1);
    expect(result).toContain('Source code is invalid.');
  });

  it('suppresses follow-on prop errors when a primary "Prop X has invalid example value" is already shown', () => {
    // Mirrors the real Canvas API response where all these errors share pointer ''.
    const error = {
      data: {
        errors: [
          {
            detail:
              'Prop "first" has invalid example value: [] Double value found.',
            source: { pointer: '' },
          },
          {
            detail:
              'Prop "second" has invalid example value: [] Double value found.',
            source: { pointer: '' },
          },
          {
            detail:
              'Prop "third" has invalid example value: [] Double value found.',
            source: { pointer: '' },
          },
          {
            detail:
              'The "meta:enum" keys for the "second" prop enum cannot contain a dot.',
            source: { pointer: '' },
          },
          {
            detail:
              'The values for the "second" prop enum must be defined in "meta:enum".',
            source: { pointer: '' },
          },
          {
            detail: 'This value should be of the correct primitive type.',
            source: { pointer: 'props.first.examples.0' },
          },
          {
            detail: 'This value should be of the correct primitive type.',
            source: { pointer: 'props.second.examples.0' },
          },
          {
            detail: 'This value should be of the correct primitive type.',
            source: { pointer: 'props.third.examples.0' },
          },
        ],
      },
    };
    const result = extractErrorMessageFromApiResponse(error);
    // Primary "Prop X" errors are shown (one per prop).
    expect(result).toContain('Prop "first" has invalid example value');
    expect(result).toContain('Prop "second" has invalid example value');
    expect(result).toContain('Prop "third" has invalid example value');
    // Follow-on errors for sdff are suppressed.
    expect(result).not.toContain('meta:enum');
    expect(result).not.toContain('must be defined in');
    // Low-quality primitive type fallbacks are suppressed.
    expect(result).not.toContain(
      'This value should be of the correct primitive type.',
    );
  });


  it('shows "This value should be of the correct primitive type." once per affected field when it is the only message type', () => {
    const error = {
      data: {
        errors: [
          {
            detail: 'This value should be of the correct primitive type.',
            source: { pointer: 'props/0/exampleValue' },
          },
          {
            detail: 'This value should be of the correct primitive type.',
            source: { pointer: 'props/1/exampleValue' },
          },
        ],
      },
    };
    const result = extractErrorMessageFromApiResponse(error);
    // Both fields are affected — message appears twice in the list.
    const count = (
      result.match(/This value should be of the correct primitive type\./g) ??
      []
    ).length;
    expect(count).toBe(2);
    expect(result).toContain('<ul>');
  });

  it('deduplicates multiple general errors with the same message into one', () => {
    const error = {
      data: {
        errors: [{ detail: 'General error.' }, { detail: 'General error.' }],
      },
    };
    expect(extractErrorMessageFromApiResponse(error)).toBe('General error.');
  });

  it('sorts errors with a pointer before errors without a pointer', () => {
    const error = {
      data: {
        errors: [
          { detail: 'General validation error.' },
          {
            detail: 'The name field is required.',
            source: { pointer: 'name' },
          },
        ],
      },
    };
    const result = extractErrorMessageFromApiResponse(error);
    const pointerIndex = result.indexOf('The name field is required.');
    const generalIndex = result.indexOf('General validation error.');
    expect(pointerIndex).toBeLessThan(generalIndex);
  });

  it('returns a fallback when all violation objects lack a detail field', () => {
    const error = {
      data: {
        errors: [{ source: { pointer: 'name' } }],
      },
    };
    expect(extractErrorMessageFromApiResponse(error)).toBe(
      'Error occurred, see browser console for more details.',
    );
  });

  it('returns a plain string for a single string error', () => {
    const error = {
      data: { errors: ['Invalid request body.'] },
    };
    expect(extractErrorMessageFromApiResponse(error)).toBe(
      'Invalid request body.',
    );
  });

  it('returns an HTML <ul> list for multiple string errors', () => {
    const error = {
      data: { errors: ['First error.', 'Second error.'] },
    };
    const result = extractErrorMessageFromApiResponse(error);
    expect(result).toContain('<ul>');
    expect(result).toContain('<li>First error.</li>');
    expect(result).toContain('<li>Second error.</li>');
  });
});
