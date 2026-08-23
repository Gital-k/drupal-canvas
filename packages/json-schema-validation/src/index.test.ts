import { describe, expect, it } from 'vitest';

import dataHealthSchema from '../../../docs/data-health.schema.json';
import componentMocksSchema from '../../workbench/src/lib/schemas/component-mocks.schema.json';
import contentTemplateSpecSchema from '../../workbench/src/lib/schemas/content-template-spec.schema.json';
import pageSpecSchema from '../../workbench/src/lib/schemas/page-spec.schema.json';
import pageTemplateSpecSchema from '../../workbench/src/lib/schemas/page-template-spec.schema.json';
import { createCanvasAjv } from './index';

const schemas = {
  'docs/data-health.schema.json': dataHealthSchema,
  'packages/workbench/src/lib/schemas/component-mocks.schema.json':
    componentMocksSchema,
  'packages/workbench/src/lib/schemas/content-template-spec.schema.json':
    contentTemplateSpecSchema,
  'packages/workbench/src/lib/schemas/page-spec.schema.json': pageSpecSchema,
  'packages/workbench/src/lib/schemas/page-template-spec.schema.json':
    pageTemplateSpecSchema,
};

describe('createCanvasAjv', () => {
  it.each(Object.entries(schemas))('strictly compiles %s', (_path, schema) => {
    expect(() => createCanvasAjv().compile(schema)).not.toThrow();
  });

  it('supports draft 2019 string formats without changing the dialect', () => {
    const validate = createCanvasAjv().compile({
      $schema: 'http://json-schema.org/draft-07/schema#',
      type: 'string',
      format: 'idn-email',
    });

    expect(validate('δοκιμή@παράδειγμα.δοκιμή')).toBe(true);
    expect(validate('not an email')).toBe(false);
  });
});
