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

  it('accepts the complete valid metadata fixture', async () => {
    const parsed = await parseComponentMetadata(
      path.join(fixtureRoot, 'valid/all-prop-types.yml'),
    );

    expect(validateParsedComponentMetadata(parsed)).toEqual([]);
  });

  it('validates prop examples directly against their JSON Schema', async () => {
    const filePath = path.join(
      fixtureRoot,
      'prop-invalid/example-does-not-match-schema.yml',
    );
    const parsed = await parseComponentMetadata(filePath);

    expect(validateParsedComponentMetadata(parsed)).toEqual([
      expect.objectContaining({
        path: '$.props.properties.count.examples[0]',
        line: 10,
        message: 'must be integer',
      }),
    ]);
  });

  it('resolves Canvas-owned prop schema references', async () => {
    const filePath = path.join(
      fixtureRoot,
      'prop-invalid/canvas-ref-example-does-not-match.yml',
    );
    const parsed = await parseComponentMetadata(filePath);

    expect(validateParsedComponentMetadata(parsed)).toEqual([
      expect.objectContaining({
        path: '$.props.properties.image.examples[0]',
        line: 10,
        message: "must contain required property 'src'",
      }),
    ]);
  });

  it.each([
    [
      'empty-string-examples.yml',
      '$.props.properties.text.examples[0]',
      'must not be empty strings',
    ],
    [
      'relative-image-examples.yml',
      '$.props.properties.image.examples[0].src',
      'must be a fully qualified URL',
    ],
  ])(
    'validates installation-independent semantics in %s',
    async (fixtureName, expectedPath, expectedMessage) => {
      const filePath = path.join(fixtureRoot, 'semantic-invalid', fixtureName);
      const parsed = await parseComponentMetadata(filePath);

      expect(validateParsedComponentMetadata(parsed)).toEqual(
        expect.arrayContaining([
          expect.objectContaining({
            path: expectedPath,
            message: expect.stringContaining(expectedMessage),
          }),
        ]),
      );
    },
  );

  it('validates relationships between metadata fields', async () => {
    const filePath = path.join(
      fixtureRoot,
      'semantic-invalid/metadata-relationships.yml',
    );
    const parsed = await parseComponentMetadata(filePath);
    const diagnostics = validateParsedComponentMetadata(parsed);

    expect(diagnostics).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          path: '$.required[0]',
          message:
            'Required prop "missing" is not defined in props.properties.',
        }),
        expect.objectContaining({
          path: '$.props.properties.requiredList.minItems',
          message:
            'Required array prop "requiredList" must specify minItems: 1.',
        }),
        expect.objectContaining({
          path: '$.props.properties.reference.examples',
          message:
            'Prop "reference" is a content entity reference prop and must not have examples.',
        }),
        expect.objectContaining({
          path: '$.dataDependencies.entityFields.optionalList',
          message:
            'dataDependencies.entityFields.optionalList does not identify a content entity reference prop.',
        }),
      ]),
    );
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
            "must not contain projected property 'x-allowed-entity-type-id'",
        }),
      ]),
    );
    await expect(readValidatedComponentMetadata(filePath)).rejects.toThrow(
      'Line 9, Column 33',
    );
  });
});
