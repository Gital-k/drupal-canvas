import { readdir, readFile } from 'node:fs/promises';
import { basename } from 'node:path';
import { load as parseYaml } from 'js-yaml';
import { describe, expect, it } from 'vitest';

import componentMetadataSchema from '../../../component-metadata.schema.json';
import { createCanvasAjv } from './index';

const fixtureRoot = new URL(
  '../../../tests/fixtures/component-metadata/',
  import.meta.url,
);

async function loadFixtures(verdict) {
  const directory = new URL(`${verdict}/`, fixtureRoot);
  return Promise.all(
    (await readdir(directory)).map(async (fileName) => ({
      fileName,
      document: parseYaml(await readFile(new URL(fileName, directory), 'utf8')),
    })),
  );
}

const validFixtures = await loadFixtures('valid');
const invalidFixtures = await loadFixtures('invalid');

describe('component metadata fixtures', () => {
  const validate = createCanvasAjv({ allErrors: true }).compile(
    componentMetadataSchema,
  );

  it.each(validFixtures)('accepts $fileName', ({ document }) => {
    expect(validate(document), JSON.stringify(validate.errors, null, 2)).toBe(
      true,
    );
  });

  it.each(invalidFixtures)('rejects $fileName', ({ document, fileName }) => {
    expect(validate(document), basename(fileName)).toBe(false);
  });
});
