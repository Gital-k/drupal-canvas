export interface ComponentMetadataSemanticIssue {
  message: string;
  parts: string[];
}

const CONTENT_ENTITY_REFERENCE_REF =
  'json-schema-definitions://canvas.module/content-entity-reference';
const IMAGE_REF = 'json-schema-definitions://canvas.module/image';

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isFullyQualifiedUrl(value: string): boolean {
  try {
    const url = new URL(value);
    return url.protocol.length > 0 && url.hostname.length > 0;
  } catch {
    return false;
  }
}

function isContentEntityReference(prop: Record<string, unknown>): boolean {
  return prop.type === 'object' && prop.$ref === CONTENT_ENTITY_REFERENCE_REF;
}

function validateStringExamples(
  propName: string,
  prop: Record<string, unknown>,
): ComponentMetadataSemanticIssue[] {
  if (!Array.isArray(prop.examples)) return [];
  const issues: ComponentMetadataSemanticIssue[] = [];
  const isString = prop.type === 'string';
  const isStringArray =
    prop.type === 'array' &&
    isRecord(prop.items) &&
    prop.items.type === 'string';

  prop.examples.forEach((example, exampleIndex) => {
    if (isString && example === '') {
      issues.push({
        parts: [
          'props',
          'properties',
          propName,
          'examples',
          String(exampleIndex),
        ],
        message: `Prop "${propName}" example values must not be empty strings.`,
      });
    }
    if (isStringArray && Array.isArray(example)) {
      example.forEach((item, itemIndex) => {
        if (item === '') {
          issues.push({
            parts: [
              'props',
              'properties',
              propName,
              'examples',
              String(exampleIndex),
              String(itemIndex),
            ],
            message: `Prop "${propName}" example values must not be empty strings.`,
          });
        }
      });
    }
  });
  return issues;
}

function validateImageExamples(
  propName: string,
  prop: Record<string, unknown>,
): ComponentMetadataSemanticIssue[] {
  if (!Array.isArray(prop.examples)) return [];
  const directImage = prop.$ref === IMAGE_REF;
  const imageArray =
    prop.type === 'array' &&
    isRecord(prop.items) &&
    prop.items.$ref === IMAGE_REF;
  if (!directImage && !imageArray) return [];

  const issues: ComponentMetadataSemanticIssue[] = [];
  prop.examples.forEach((example, exampleIndex) => {
    const images = imageArray && Array.isArray(example) ? example : [example];
    images.forEach((image, imageIndex) => {
      if (
        !isRecord(image) ||
        typeof image.src !== 'string' ||
        isFullyQualifiedUrl(image.src)
      ) {
        return;
      }
      const parts = [
        'props',
        'properties',
        propName,
        'examples',
        String(exampleIndex),
      ];
      if (imageArray) parts.push(String(imageIndex));
      parts.push('src');
      issues.push({
        parts,
        message: `Image prop "${propName}" example src must be a fully qualified URL with both scheme and host.`,
      });
    });
  });
  return issues;
}

export function validateComponentMetadataSemantics(
  value: unknown,
): ComponentMetadataSemanticIssue[] {
  if (!isRecord(value)) return [];
  const propsEnvelope = value.props;
  const properties =
    isRecord(propsEnvelope) && isRecord(propsEnvelope.properties)
      ? propsEnvelope.properties
      : {};
  const required = Array.isArray(value.required)
    ? value.required.filter((name): name is string => typeof name === 'string')
    : [];
  const requiredNames = new Set(required);
  const issues: ComponentMetadataSemanticIssue[] = [];

  required.forEach((propName, index) => {
    if (!Object.hasOwn(properties, propName)) {
      issues.push({
        parts: ['required', String(index)],
        message: `Required prop "${propName}" is not defined in props.properties.`,
      });
    }
  });

  const entityFields =
    isRecord(value.dataDependencies) &&
    isRecord(value.dataDependencies.entityFields)
      ? value.dataDependencies.entityFields
      : {};
  const contentEntityReferenceNames = new Set<string>();

  for (const [propName, propValue] of Object.entries(properties)) {
    if (!isRecord(propValue)) continue;
    const propPath = ['props', 'properties', propName];
    const isRequired = requiredNames.has(propName);
    const contentEntityReference = isContentEntityReference(propValue);
    if (contentEntityReference) {
      contentEntityReferenceNames.add(propName);
      if (isRequired) {
        issues.push({
          parts: propPath,
          message: `Prop "${propName}" is required, but content entity reference props must be optional.`,
        });
      }
      if (Object.hasOwn(propValue, 'examples')) {
        issues.push({
          parts: [...propPath, 'examples'],
          message: `Prop "${propName}" is a content entity reference prop and must not have examples.`,
        });
      }
      if (!Object.hasOwn(entityFields, propName)) {
        issues.push({
          parts: propPath,
          message: `Prop "${propName}" is a content entity reference prop but is missing dataDependencies.entityFields.${propName}.`,
        });
      }
    } else if (
      isRequired &&
      (!Array.isArray(propValue.examples) || propValue.examples[0] == null)
    ) {
      issues.push({
        parts: propPath,
        message: `Required prop "${propName}" must have an example value.`,
      });
    }

    if (propValue.type === 'array') {
      if (
        isRequired &&
        (typeof propValue.minItems !== 'number' || propValue.minItems < 1)
      ) {
        issues.push({
          parts: [...propPath, 'minItems'],
          message: `Required array prop "${propName}" must specify minItems: 1.`,
        });
      }
      if (!isRequired && Object.hasOwn(propValue, 'minItems')) {
        issues.push({
          parts: [...propPath, 'minItems'],
          message: `Optional array prop "${propName}" must not specify minItems.`,
        });
      }
      if (typeof propValue.maxItems === 'number' && propValue.maxItems < 2) {
        issues.push({
          parts: [...propPath, 'maxItems'],
          message: `Array prop "${propName}" must specify maxItems of at least 2.`,
        });
      }
    }

    issues.push(
      ...validateStringExamples(propName, propValue),
      ...validateImageExamples(propName, propValue),
    );
  }

  for (const propName of Object.keys(entityFields)) {
    if (!contentEntityReferenceNames.has(propName)) {
      issues.push({
        parts: ['dataDependencies', 'entityFields', propName],
        message: `dataDependencies.entityFields.${propName} does not identify a content entity reference prop.`,
      });
    }
  }

  return issues;
}
