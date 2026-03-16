import transforms from '@/utils/transforms';

describe('Transforms - link', () => {
  const fieldData = {
    expression: '',
    sourceType: 'static:field_item:link',
    sourceTypeSettings: {
      instance: {
        title: 0,
      },
    },
  };

  it('Should return just a URI if title is disabled', () => {
    fieldData.sourceTypeSettings.instance.title = 0;
    expect(
      transforms.link([{ uri: 'https://example.com' }], {}, fieldData),
    ).toEqual('https://example.com');
  });

  it('Should support multiple values', () => {
    fieldData.sourceTypeSettings.instance.title = 0;
    expect(
      transforms.link(
        [
          { uri: 'https://example.com' },
          { uri: 'https://another.example.com' },
        ],
        { multiple: true },
        fieldData,
      ),
    ).toEqual(['https://example.com', 'https://another.example.com']);
  });

  it('Should return URI and title if title is enabled', () => {
    fieldData.sourceTypeSettings.instance.title = 2;
    expect(
      transforms.link(
        [{ uri: 'https://example.com', title: 'Click me' }],
        {},
        fieldData,
      ),
    ).toEqual({ uri: 'https://example.com', title: 'Click me' });
  });

  it('Should match on autocomplete, no title', () => {
    fieldData.sourceTypeSettings.instance.title = 0;
    expect(
      transforms.link([{ uri: 'A node title (3)' }], {}, fieldData),
    ).toEqual('entity:node/3');
  });

  it('Should match on autocomplete, with title', () => {
    fieldData.sourceTypeSettings.instance.title = 2;
    expect(
      transforms.link(
        [{ uri: 'A node title (3)', title: 'Click me' }],
        {},
        fieldData,
      ),
    ).toEqual({ uri: 'entity:node/3', title: 'Click me' });
  });
});

describe('Transforms - main property', () => {
  const fieldData = {
    expression: '',
    sourceType: 'static:field_item:link',
    sourceTypeSettings: {
      instance: {},
    },
  };

  it('Should return just the main property', () => {
    expect(
      transforms.mainProperty(
        [{ uri: 'https://example.com' }],
        { name: 'uri' },
        fieldData,
      ),
    ).toEqual('https://example.com');
  });

  it('Should allow for non-list checkboxes', () => {
    expect(
      transforms.mainProperty({ value: true }, { name: 'value' }, fieldData),
    ).toEqual(true);
  });

  it('Should work with multi-value fields', () => {
    expect(
      transforms.mainProperty(
        [{ value: 'because' }, { value: 'you' }, { value: 'asked' }],
        { name: 'value', multiple: true },
        fieldData,
      ),
    ).toEqual(['because', 'you', 'asked']);
  });

  it('Should handle null values for multi-value fields', () => {
    expect(
      transforms.mainProperty(
        null,
        { name: 'value', multiple: true },
        fieldData,
      ),
    ).toEqual([null]);
  });

  it('Should handle undefined values for multi-value fields', () => {
    expect(
      transforms.mainProperty(
        undefined,
        { name: 'value', multiple: true },
        fieldData,
      ),
    ).toEqual([null]);
  });

  it('Should sort by weight field when present', () => {
    expect(
      transforms.mainProperty(
        {
          0: { target_id: '3', weight: '0' },
          1: { target_id: '4', weight: '1' },
          2: { target_id: '5', weight: '2' },
        },
        { name: 'target_id', multiple: true },
        fieldData,
      ),
    ).toEqual(['3', '4', '5']);
  });

  it('Should sort by weight field after reordering', () => {
    expect(
      transforms.mainProperty(
        {
          0: { target_id: '5', weight: '0' },
          1: { target_id: '3', weight: '1' },
          2: { target_id: '4', weight: '2' },
        },
        { name: 'target_id', multiple: true },
        fieldData,
      ),
    ).toEqual(['5', '3', '4']);
  });

  it('Should sort by _weight field when weight is not present', () => {
    expect(
      transforms.mainProperty(
        {
          0: { target_id: '4', _weight: '0' },
          1: { target_id: '3', _weight: '1' },
        },
        { name: 'target_id', multiple: true },
        fieldData,
      ),
    ).toEqual(['4', '3']);
  });

  it('Should handle numeric weight values', () => {
    expect(
      transforms.mainProperty(
        {
          0: { target_id: '3', weight: 1 },
          1: { target_id: '4', weight: 0 },
        },
        { name: 'target_id', multiple: true },
        fieldData,
      ),
    ).toEqual(['4', '3']);
  });

  it('Should filter out empty strings from multi-value fields', () => {
    expect(
      transforms.mainProperty(
        { 0: { value: 'tag1' }, 1: { value: '' } },
        { name: 'value', multiple: true },
        fieldData,
      ),
    ).toEqual(['tag1']);
  });

  it('Should return empty array when all values are empty strings', () => {
    expect(
      transforms.mainProperty(
        { 0: { value: '' } },
        { name: 'value', multiple: true },
        fieldData,
      ),
    ).toEqual([]);
  });
});

describe('Transforms - dateTime', () => {
  it('should return null when propSource is undefined', () => {
    expect(transforms.dateTime({ date: '' }, {}, undefined)).to.equal(null);
  });
});

describe('Transforms - dateRange', () => {
  const fieldData = {
    sourceTypeSettings: {
      storage: {
        datetime_type: 'date',
      },
    },
  };

  it('should return null when propSource is undefined', () => {
    expect(
      transforms.dateRange(
        [{ value: { date: '' }, end_value: { date: '' } }],
        {},
        undefined,
      ),
    ).to.equal(null);
  });

  it('should map date range form values to value/end_value', () => {
    expect(
      transforms.dateRange(
        [
          {
            value: { date: '2026-05-02' },
            end_value: { date: '2026-06-02' },
          },
        ],
        {},
        fieldData,
      ),
    ).to.deep.equal({
      value: '2026-05-02',
      end_value: '2026-06-02',
    });
  });

  it('should map datetime range form values to UTC ISO date strings', () => {
    const dateTimeFieldData = {
      sourceTypeSettings: {
        storage: {
          datetime_type: 'datetime',
        },
      },
    };

    expect(
      transforms.dateRange(
        [
          {
            value: { date: '2026-05-02', time: '07:21:35' },
            end_value: { date: '2026-06-02', time: '09:45:12' },
          },
        ],
        {},
        dateTimeFieldData,
      ),
    ).to.deep.equal({
      value: '2026-05-02T07:21:35.000Z',
      end_value: '2026-06-02T09:45:12.000Z',
    });
  });
});
