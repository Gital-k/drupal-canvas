import { describe, expect, it } from 'vitest';

import { createAjv } from './ajv';

describe('createAjv', () => {
  it('creates a strict draft-07 validator with Canvas formats', () => {
    const validate = createAjv().compile({
      $schema: 'http://json-schema.org/draft-07/schema#',
      type: 'string',
      format: 'idn-email',
    });

    // cspell:ignore δοκιμή παράδειγμα
    expect(validate('δοκιμή@παράδειγμα.δοκιμή')).toBe(true);
    expect(validate('not an email')).toBe(false);
  });
});
