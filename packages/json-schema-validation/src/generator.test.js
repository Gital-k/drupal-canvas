import { readFile } from 'node:fs/promises';
import { load as parseYaml } from 'js-yaml';
import { describe, expect, it } from 'vitest';

import { generateDefinitions } from '../../../scripts/generate-component-metadata-schema.js';

const source = await readFile(
  new URL('../../../config/schema/canvas.json_schema.yml', import.meta.url),
  'utf8',
);

function loadConfig() {
  return parseYaml(source);
}

describe('component metadata schema generator', () => {
  it('projects supported config schema choices', () => {
    const config = loadConfig();
    config[
      'canvas.json_schema.prop_shape.string'
    ].mapping.format.constraints.Choice.choices.push('hostname');

    const definitions = generateDefinitions(config);

    expect(definitions.propString.properties.format.enum).toContain('hostname');
  });

  it('fails with the config schema source path for unsupported constructs', () => {
    const config = loadConfig();
    config['canvas.json_schema.prop_shape.string'].unsupported = true;

    expect(() => generateDefinitions(config)).toThrow(
      'config/schema/canvas.json_schema.yml:canvas.json_schema.prop_shape.string.unsupported',
    );
  });
});
