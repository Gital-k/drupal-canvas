export interface ComponentMetadataSemanticIssue {
  message: string;
  parts: string[];
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
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
  const issues: ComponentMetadataSemanticIssue[] = [];

  required.forEach((propName, index) => {
    if (!Object.hasOwn(properties, propName)) {
      issues.push({
        parts: ['required', String(index)],
        message: `Required prop "${propName}" is not defined in props.properties.`,
      });
    }
  });

  for (const [propName, propValue] of Object.entries(properties)) {
    if (!isRecord(propValue)) continue;
    if (
      required.includes(propName) &&
      (!Array.isArray(propValue.examples) || propValue.examples[0] == null)
    ) {
      issues.push({
        parts: ['props', 'properties', propName],
        message: `Required prop "${propName}" must have an example value.`,
      });
    }
    issues.push(...validateStringExamples(propName, propValue));
  }

  return issues;
}
