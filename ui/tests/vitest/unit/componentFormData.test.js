import {
  shouldSkipPropValidation,
  validateNewValue,
} from '@/components/form/react-hook-form/fields/componentFieldValidation';
import { getPropsValues } from '@/components/form/react-hook-form/fields/componentFormData';

describe('componentFieldValidation - required multivalue prop validation', () => {
  const selectedComponent = 'test-uuid';
  const selectedComponentType = 'sdc.test.multivalue-text';

  const components = {
    [selectedComponentType]: {
      id: selectedComponentType,
      name: 'Multivalue text example',
      library: 'elements',
      source: 'sdc',
      default_markup: '',
      css: '',
      js_header: '',
      js_footer: '',
      version: '1.0',
      broken: false,
      metadata: {},
      transforms: {},
      propSources: {
        tags: {
          expression: '',
          sourceType: 'static:field_item:string',
          required: true,
          jsonSchema: {
            type: 'array',
            items: { type: 'string' },
            minItems: 1,
          },
          default_values: {
            resolved: ['default tag'],
            source: { value: ['default tag'] },
          },
        },
        optional_tags: {
          expression: '',
          sourceType: 'static:field_item:string',
          required: false,
          jsonSchema: {
            type: 'array',
            items: { type: 'string' },
          },
          default_values: {
            resolved: [],
            source: { value: [] },
          },
        },
        links: {
          expression: '',
          sourceType: 'static:field_item:link',
          required: true,
          sourceTypeSettings: {
            instance: { title: 0 },
          },
          jsonSchema: {
            type: 'array',
            items: { type: 'string', format: 'uri-reference' },
            minItems: 1,
          },
          default_values: {
            resolved: ['/default-path'],
            source: { value: ['/default-path'] },
          },
        },
        optional_links: {
          expression: '',
          sourceType: 'static:field_item:link',
          required: false,
          sourceTypeSettings: {
            instance: { title: 0 },
          },
          jsonSchema: {
            type: 'array',
            items: { type: 'string', format: 'uri-reference' },
          },
          default_values: {
            resolved: [],
            source: { value: [] },
          },
        },
      },
    },
  };

  const inputAndUiData = {
    selectedComponent,
    selectedComponentType,
    components,
    layout: [],
    version: '1.0',
    editorFrameContext: 'entity',
    model: {
      [selectedComponent]: {
        resolved: { tags: ['existing'], links: ['/existing'] },
        source: {},
      },
    },
  };

  /**
   * Creates a minimal HTMLInputElement stub attached to a form.
   */
  const makeInput = (name, { required = false } = {}) => {
    const form = document.createElement('form');
    const input = document.createElement('input');
    input.name = name;
    input.required = required;
    form.appendChild(input);
    return input;
  };

  describe('shouldSkipPropValidation', () => {
    it('does not skip validation for empty value on required array prop with minItems', () => {
      const input = makeInput(
        `canvas_component_props[${selectedComponent}][tags][0][value]`,
      );
      const result = shouldSkipPropValidation(
        input.name,
        input,
        inputAndUiData,
        '',
      );
      expect(result).toBe(false);
    });

    it('skips validation for empty value on optional array prop without minItems', () => {
      const input = makeInput(
        `canvas_component_props[${selectedComponent}][optional_tags][0][value]`,
      );
      const result = shouldSkipPropValidation(
        input.name,
        input,
        inputAndUiData,
        '',
      );
      expect(result).toBe(true);
    });

    it('does not skip validation for empty value on required link array prop with minItems', () => {
      const input = makeInput(
        `canvas_component_props[${selectedComponent}][links][0][uri]`,
      );
      const result = shouldSkipPropValidation(
        input.name,
        input,
        inputAndUiData,
        '',
      );
      expect(result).toBe(false);
    });

    it('skips validation for empty value on optional link array prop without minItems', () => {
      const input = makeInput(
        `canvas_component_props[${selectedComponent}][optional_links][0][uri]`,
      );
      const result = shouldSkipPropValidation(
        input.name,
        input,
        inputAndUiData,
        '',
      );
      expect(result).toBe(true);
    });
  });

  describe('validateNewValue', () => {
    it('returns invalid for empty value on required array prop with minItems', () => {
      const input = makeInput(
        `canvas_component_props[${selectedComponent}][tags][0][value]`,
      );
      const event = { target: input };
      const result = validateNewValue(
        event,
        '',
        input.name,
        selectedComponent,
        inputAndUiData,
      );
      expect(result.valid).toBe(false);
      expect(result.errorMessage).toBe('This field is required.');
    });

    it('returns valid for non-empty value on required array prop with minItems', () => {
      const input = makeInput(
        `canvas_component_props[${selectedComponent}][tags][0][value]`,
      );
      const event = { target: input };
      const result = validateNewValue(
        event,
        'hello',
        input.name,
        selectedComponent,
        inputAndUiData,
      );
      expect(result.valid).toBe(true);
    });

    it('returns invalid for empty URI on required link array prop with minItems', () => {
      const input = makeInput(
        `canvas_component_props[${selectedComponent}][links][0][uri]`,
      );
      const event = { target: input };
      const result = validateNewValue(
        event,
        '',
        input.name,
        selectedComponent,
        inputAndUiData,
      );
      expect(result.valid).toBe(false);
      expect(result.errorMessage).toBe('This field is required.');
    });

    it('returns invalid for null URI on required link array prop with minItems', () => {
      const input = makeInput(
        `canvas_component_props[${selectedComponent}][links][0][uri]`,
      );
      const event = { target: input };
      const result = validateNewValue(
        event,
        null,
        input.name,
        selectedComponent,
        inputAndUiData,
      );
      expect(result.valid).toBe(false);
      expect(result.errorMessage).toBe('This field is required.');
    });

    it('returns valid for non-empty URI on required link array prop with minItems', () => {
      const input = makeInput(
        `canvas_component_props[${selectedComponent}][links][0][uri]`,
      );
      const event = { target: input };
      const result = validateNewValue(
        event,
        '/about-us',
        input.name,
        selectedComponent,
        inputAndUiData,
      );
      expect(result.valid).toBe(true);
    });
  });
});

describe('componentFormData - getPropsValues propSource fallback', () => {
  const selectedComponent = 'test-uuid';
  const selectedComponentType = 'sdc.test.date-time-example';

  const dateTimeFieldSource = {
    sourceType: 'static:field_item:datetime',
    sourceTypeSettings: {
      storage: { datetime_type: 'datetime' },
    },
  };

  const components = {
    [selectedComponentType]: {
      id: selectedComponentType,
      name: 'Date time example',
      library: 'elements',
      source: 'sdc',
      default_markup: '',
      css: '',
      js_header: '',
      js_footer: '',
      version: '1.0',
      broken: false,
      metadata: {},
      transforms: {},
      propSources: {
        date_time: {
          expression: '',
          sourceType: 'static:field_item:datetime',
          sourceTypeSettings: dateTimeFieldSource.sourceTypeSettings,
          default_values: { resolved: {}, source: {} },
        },
      },
    },
  };

  const transformConfig = {
    date_time: { dateTime: {} },
  };

  const formState = {
    [`canvas_component_props[${selectedComponent}][date_time][date]`]:
      '2026-01-15',
    [`canvas_component_props[${selectedComponent}][date_time][time]`]:
      '14:30:00',
  };

  it('does not drop the value when source[key] is missing', () => {
    // Simulates a freshly-evaluated model where `date_time` has an empty
    // default value and has not been saved yet, so the backend has
    // stripped it from `source`.
    const inputAndUiData = {
      selectedComponent,
      selectedComponentType,
      components,
      layout: [],
      version: '1.0',
      editorFrameContext: 'entity',
      model: {
        [selectedComponent]: {
          resolved: {},
          source: {},
        },
      },
    };

    const { propsValues } = getPropsValues(
      formState,
      inputAndUiData,
      transformConfig,
    );

    expect(propsValues.date_time).toBe('2026-01-15T14:30:00.000Z');
  });

  it('still works when source[key] is present', () => {
    const inputAndUiData = {
      selectedComponent,
      selectedComponentType,
      components,
      layout: [],
      version: '1.0',
      editorFrameContext: 'entity',
      model: {
        [selectedComponent]: {
          resolved: {},
          source: { date_time: dateTimeFieldSource },
        },
      },
    };

    const { propsValues } = getPropsValues(
      formState,
      inputAndUiData,
      transformConfig,
    );

    expect(propsValues.date_time).toBe('2026-01-15T14:30:00.000Z');
  });
});
