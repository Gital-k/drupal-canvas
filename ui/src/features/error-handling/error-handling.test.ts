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
