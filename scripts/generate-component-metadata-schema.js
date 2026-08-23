import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';
import { load as parseYaml } from 'js-yaml';

const dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(dirname, '..');
const sourcePath = 'config/schema/canvas.json_schema.yml';
const templatePath = 'scripts/component-metadata.schema.template.json';
const outputPath = 'component-metadata.schema.json';
const sourceFile = path.join(root, sourcePath);

const supportedNodeKeys = new Set([
  'class',
  'constraints',
  'label',
  'mapping',
  'orderby',
  'requiredKey',
  'sequence',
  'type',
]);
const supportedConstraints = new Set([
  'Choice',
  'FullyValidatable',
  'NotBlank',
  'NotNull',
  'Range',
]);
const primitiveTypes = ['string', 'number', 'integer', 'boolean', 'object'];

function fail(configPath, message) {
  throw new Error(`${sourcePath}:${configPath}: ${message}`);
}

function isObject(value) {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function validateConstraint(name, options, configPath) {
  if (!supportedConstraints.has(name)) {
    fail(configPath, `unsupported constraint ${name}`);
  }
  if (options === null || Array.isArray(options)) {
    return;
  }
  if (!isObject(options)) {
    fail(configPath, `expected constraint options to be a mapping, sequence, or null`);
  }

  const allowedOptions = {
    Choice: new Set(['choices']),
    FullyValidatable: new Set(),
    NotBlank: new Set(['allowNull', 'message']),
    NotNull: new Set(),
    Range: new Set(['max', 'min']),
  }[name];
  for (const key of Object.keys(options)) {
    if (!allowedOptions.has(key)) {
      fail(`${configPath}.${key}`, `unsupported ${name} option`);
    }
  }
}

function validateConfigNode(node, configPath) {
  if (!isObject(node)) {
    fail(configPath, 'expected a config schema mapping');
  }
  for (const key of Object.keys(node)) {
    if (!supportedNodeKeys.has(key)) {
      fail(`${configPath}.${key}`, 'unsupported config schema construct');
    }
  }
  if (node.constraints !== undefined) {
    if (!isObject(node.constraints)) {
      fail(`${configPath}.constraints`, 'expected a constraint mapping');
    }
    for (const [name, options] of Object.entries(node.constraints)) {
      validateConstraint(name, options, `${configPath}.constraints.${name}`);
    }
  }
  if (node.mapping !== undefined) {
    if (!isObject(node.mapping)) {
      fail(`${configPath}.mapping`, 'expected a mapping');
    }
    for (const [key, child] of Object.entries(node.mapping)) {
      validateConfigNode(child, `${configPath}.mapping.${key}`);
    }
  }
  if (node.sequence !== undefined) {
    validateConfigNode(node.sequence, `${configPath}.sequence`);
  }
}

function mergeNodes(parent, child) {
  return {
    ...parent,
    ...child,
    constraints: {
      ...(parent.constraints ?? {}),
      ...(child.constraints ?? {}),
    },
    mapping: {
      ...(parent.mapping ?? {}),
      ...(child.mapping ?? {}),
    },
  };
}

function choiceValues(options, configPath) {
  const choices = Array.isArray(options) ? options : options?.choices;
  if (!Array.isArray(choices)) {
    fail(configPath, 'Choice must provide a choices sequence');
  }
  return choices;
}

function applyConstraints(schema, node, configPath) {
  for (const [name, options] of Object.entries(node.constraints ?? {})) {
    switch (name) {
      case 'Choice':
        schema.enum = choiceValues(options, `${configPath}.constraints.Choice`);
        break;
      case 'Range':
        if (options.min !== undefined) schema.minimum = options.min;
        if (options.max !== undefined) schema.maximum = options.max;
        break;
      case 'NotBlank':
        if (schema.type === 'string') schema.minLength = 1;
        else if (schema.type === 'array') schema.minItems = 1;
        else if (schema.type === 'object') schema.minProperties = 1;
        else fail(configPath, `NotBlank is unsupported for type ${schema.type}`);
        break;
      case 'FullyValidatable':
        if (schema.type === 'object') schema.additionalProperties = false;
        break;
      case 'NotNull':
        break;
      default:
        fail(`${configPath}.constraints.${name}`, 'unsupported constraint');
    }
  }
  return schema;
}

function translateNode(node, configPath) {
  let schema;
  switch (node.type) {
    case 'boolean':
      schema = { type: 'boolean' };
      break;
    case 'float':
      schema = { type: 'number' };
      break;
    case 'integer':
      schema = { type: 'integer' };
      break;
    case 'label':
    case 'string':
    case 'text':
      schema = { type: 'string' };
      break;
    case 'required_label':
      schema = { type: 'string', minLength: 1 };
      break;
    case 'uri':
      schema = { type: 'string', format: 'uri' };
      break;
    case 'canvas.json_schema.object':
      schema = { type: 'object', minProperties: 1 };
      break;
    case 'mapping': {
      const properties = {};
      const required = [];
      for (const [key, child] of Object.entries(node.mapping ?? {})) {
        properties[key] = translateNode(child, `${configPath}.mapping.${key}`);
        if (child.requiredKey !== false) required.push(key);
      }
      schema = { type: 'object', properties };
      if (required.length > 0) schema.required = required;
      break;
    }
    case 'sequence':
      schema = {
        type: 'array',
        items: translateNode(node.sequence, `${configPath}.sequence`),
      };
      break;
    default:
      fail(configPath, `unsupported config schema type ${String(node.type)}`);
  }
  return applyConstraints(schema, node, configPath);
}

function resolveDefinition(config, name, seen = new Set()) {
  const node = config[name];
  if (!node) fail(name, 'missing config schema definition');
  if (seen.has(name)) fail(name, 'circular config schema inheritance');

  if (typeof node.type !== 'string' || !config[node.type]) {
    return node;
  }
  return mergeNodes(
    resolveDefinition(config, node.type, new Set([...seen, name])),
    node,
  );
}

function exampleValueSchema(config, propType) {
  const definitionName = `canvas.json_schema.prop_examples.${propType}.*`;
  const node = config[definitionName];
  if (!node) fail(definitionName, 'missing example definition');
  const schema = translateNode(node, definitionName);
  if (propType === 'string') {
    return { $ref: '#/definitions/overlays/nonEmptyString' };
  }
  if (propType === 'object') {
    return { $ref: '#/definitions/overlays/nonEmptyObject' };
  }
  return schema;
}

function shapeSchema(config, propType) {
  const definitionName = `canvas.json_schema.prop_shape.${propType}`;
  const resolved = resolveDefinition(config, definitionName);
  const properties = {};
  const required = [];

  for (const [key, child] of Object.entries(resolved.mapping ?? {})) {
    if (key === 'type') {
      properties.type = { const: propType };
    } else if (key === 'meta:enum') {
      properties[key] = {
        type: 'object',
        additionalProperties: { type: 'string' },
      };
    } else if (key === 'items') {
      properties.items = {
        oneOf: primitiveTypes.map((type) => ({
          $ref: `#/definitions/generated/shape${type[0].toUpperCase()}${type.slice(1)}`,
        })),
      };
    } else {
      properties[key] = translateNode(child, `${definitionName}.mapping.${key}`);
    }
    if (child.requiredKey !== false) required.push(key);
  }

  const schema = {
    type: 'object',
    required,
    additionalProperties: false,
    properties,
  };
  applyConstraints(schema, resolved, definitionName);
  return schema;
}

function propSchema(config, propType) {
  const baseName = 'canvas.json_schema.prop.*';
  const shapeName = `canvas.json_schema.prop_shape.${propType}`;
  const base = config[baseName];
  const shape = resolveDefinition(config, shapeName);
  const mapping = { ...(shape.mapping ?? {}), ...(base.mapping ?? {}) };
  const properties = {};
  const required = [];

  for (const [key, child] of Object.entries(mapping)) {
    if (key === 'type') {
      properties.type = { const: propType };
    } else if (key === 'meta:enum') {
      properties[key] = {
        type: 'object',
        additionalProperties: { type: 'string' },
      };
    } else if (key === 'examples') {
      if (propType === 'array') {
        properties.examples = { type: 'array' };
      } else {
        properties.examples = {
          type: 'array',
          items: exampleValueSchema(config, propType),
        };
      }
    } else if (key === 'items' && propType === 'array') {
      properties.items = {
        oneOf: primitiveTypes.map((type) => ({
          $ref: `#/definitions/generated/shape${type[0].toUpperCase()}${type.slice(1)}`,
        })),
      };
    } else {
      properties[key] = translateNode(child, `${shapeName}.mapping.${key}`);
    }
    if (child.requiredKey !== false) required.push(key);
  }

  const schema = {
    type: 'object',
    required: [...new Set(required)],
    additionalProperties: false,
    properties,
  };

  if (propType === 'object') {
    const refs = properties.$ref.enum;
    schema.oneOf = refs.map((ref) => ({
      type: 'object',
      required: ['$ref'],
      properties: {
        $ref: { const: ref },
        examples: {
          type: 'array',
          items:
            ref === 'json-schema-definitions://canvas.module/image'
              ? { $ref: '#/definitions/overlays/imageExample' }
              : { $ref: '#/definitions/overlays/nonEmptyObject' },
        },
      },
    }));
  }
  if (propType === 'array') {
    schema.oneOf = primitiveTypes
      .filter((itemType) => itemType !== 'object')
      .map((itemType) => {
        const itemExamples =
          config[`canvas.json_schema.prop_examples.array.${itemType}`];
        if (!itemExamples) {
          fail(
            `canvas.json_schema.prop_examples.array.${itemType}`,
            'missing array example definition',
          );
        }
        const translated = translateNode(
          itemExamples,
          `canvas.json_schema.prop_examples.array.${itemType}`,
        );
        const items =
          itemType === 'string'
            ? { $ref: '#/definitions/overlays/nonEmptyString' }
            : translated.items;
        return {
          type: 'object',
          required: ['items'],
          properties: {
            items: {
              type: 'object',
              required: ['type'],
              properties: { type: { const: itemType } },
            },
            examples: {
              type: 'array',
              items: { type: 'array', items },
            },
          },
        };
      });
    const objectRefs = shapeSchema(config, 'object').properties.$ref.enum;
    schema.oneOf.push(
      ...objectRefs.map((ref) => ({
        type: 'object',
        required: ['items'],
        properties: {
          items: {
            type: 'object',
            required: ['type', '$ref'],
            properties: {
              type: { const: 'object' },
              $ref: { const: ref },
            },
          },
          examples: {
            type: 'array',
            items: {
              type: 'array',
              items:
                ref === 'json-schema-definitions://canvas.module/image'
                  ? { $ref: '#/definitions/overlays/imageExample' }
                  : { $ref: '#/definitions/overlays/nonEmptyObject' },
            },
          },
        },
      })),
    );
  }

  return schema;
}

export function generateDefinitions(config) {
  for (const [name, node] of Object.entries(config)) {
    if (name.startsWith('canvas.json_schema.')) {
      validateConfigNode(node, name);
    }
  }

  const generated = {
    propDefinition: {
      oneOf: [...primitiveTypes, 'array'].map((type) => ({
        $ref: `#/definitions/generated/prop${type[0].toUpperCase()}${type.slice(1)}`,
      })),
    },
  };
  for (const type of primitiveTypes) {
    const suffix = `${type[0].toUpperCase()}${type.slice(1)}`;
    generated[`shape${suffix}`] = shapeSchema(config, type);
    generated[`prop${suffix}`] = propSchema(config, type);
  }
  generated.propArray = propSchema(config, 'array');
  return generated;
}

async function generate() {
  const [source, templateSource] = await Promise.all([
    readFile(sourceFile, 'utf8'),
    readFile(path.join(root, templatePath), 'utf8'),
  ]);
  const config = parseYaml(source);
  if (!isObject(config)) fail('', 'expected a top-level mapping');
  const schema = JSON.parse(templateSource);
  schema.definitions.generated = generateDefinitions(config);
  return `${JSON.stringify(schema, null, 2)}\n`;
}

if (
  process.argv[1] &&
  path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)
) {
  const generated = await generate();
  const outputFile = path.join(root, outputPath);
  if (process.argv.includes('--check')) {
    const existing = await readFile(outputFile, 'utf8').catch(() => '');
    if (existing !== generated) {
      console.error(
        `${outputPath} is stale. Run npm run generate:component-metadata-schema.`,
      );
      process.exitCode = 1;
    }
  } else {
    await writeFile(outputFile, generated);
  }
}
