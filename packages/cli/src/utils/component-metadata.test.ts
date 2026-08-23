import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

import {
  normalizeComponentMetadata,
  parseComponentMetadata,
  readValidatedComponentMetadata,
  validateParsedComponentMetadata,
} from './component-metadata';

const dirname = path.dirname(fileURLToPath(import.meta.url));
const fixtureRoot = path.resolve(
  dirname,
  '../../../../tests/fixtures/component-metadata',
);

describe('component metadata', () => {
  it('validates raw metadata before applying defaults', async () => {
    const parsed = await parseComponentMetadata(
      path.join(fixtureRoot, 'valid/minimal.yml'),
    );

    expect(parsed.value).toEqual({
      name: 'Minimal',
      machineName: 'minimal',
    });
    expect(validateParsedComponentMetadata(parsed)).toEqual([]);
    expect(normalizeComponentMetadata(parsed.value)).toEqual({
      name: 'Minimal',
      machineName: 'minimal',
      status: true,
      required: [],
      props: { properties: {} },
      slots: {},
      dataDependencies: {},
    });
  });

  it('reports schema failures at YAML locations', async () => {
    const filePath = path.join(fixtureRoot, 'invalid/projected-prop-key.yml');
    const parsed = await parseComponentMetadata(filePath);
    const diagnostics = validateParsedComponentMetadata(parsed);

    expect(diagnostics).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          path: '$.props.properties.reference.x-allowed-entity-type-id',
          line: 9,
          column: 33,
          message:
            "must not contain unknown property 'x-allowed-entity-type-id'",
        }),
      ]),
    );
    await expect(readValidatedComponentMetadata(filePath)).rejects.toThrow(
      'Line 9, Column 33',
    );
  });
});
