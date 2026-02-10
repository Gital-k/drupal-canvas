import type { PropsValues } from '@drupal-canvas/types';
import type {
  PropSource,
  StaticPropSource,
} from '@/features/layout/layoutModelSlice';

export interface TransformOptions {
  [key: string]: any;
}

export type Transform = {
  [key in keyof Transforms]: TransformOptions;
};

export interface TransformConfig {
  [key: keyof PropsValues]: Partial<Transform>;
}

export const ENTITY_AUTOCOMPLETE_MATCH = /.+\s\(([^)]+)\)/;

type PropsValuesOrArrayOfPropsValues =
  | Array<PropsValues>
  | PropsValues
  | null
  | undefined;

type BaseTransformOptions = {
  multiple?: boolean;
};

type Transformer<
  TransformerOptions extends BaseTransformOptions,
  TransformerReturn extends unknown = any,
  TransformerInput extends unknown = PropsValuesOrArrayOfPropsValues,
  FieldPropShape extends PropSource = StaticPropSource,
> = (
  value: TransformerInput,
  options: TransformerOptions,
  fieldPropShape: FieldPropShape,
) => TransformerReturn;

const mainProperty: Transformer<{
  name?: keyof PropsValues;
  multiple?: boolean;
}> = (value, options): any => {
  const { name = 'value', multiple = false } = options;

  // Handle null/undefined input for entire value
  if (value === null || value === undefined) {
    return multiple ? [null] : null;
  }

  let records: Array<PropsValues> = Array.isArray(value) ? value : [value];
  if (multiple && typeof value === 'object' && value !== null) {
    // Convert object to array of entries for sorting
    const entries = Object.entries(value);

    // Check if all entries have a weight field (for drag-and-drop reordering)
    // Support both 'weight' (media library) and '_weight' (other widgets)
    const hasWeights =
      entries.length > 0 &&
      entries.every(
        ([, item]) =>
          typeof item === 'object' &&
          item !== null &&
          ('_weight' in item || 'weight' in item),
      );

    if (hasWeights) {
      // Sort by weight before extracting values
      entries.sort(([, a], [, b]) => {
        const weightA =
          typeof a === 'object' && a !== null
            ? Number(a._weight ?? a.weight) || 0
            : 0;
        const weightB =
          typeof b === 'object' && b !== null
            ? Number(b._weight ?? b.weight) || 0
            : 0;
        return weightA - weightB;
      });
      records = entries.map(([, item]) => item);
    } else {
      records = Object.values(value);
    }
  }
  const returnValue = records.reduce(
    (carry: Array<any>, record: PropsValues) => {
      if (record === null || record === undefined) {
        return carry.concat(null);
      }
      if (name in record) {
        return carry.concat(record[name]);
      }
      return carry.concat(null);
    },
    [],
  );
  if (multiple) {
    return returnValue;
  }
  return returnValue.shift();
};

const firstRecord: Transformer<BaseTransformOptions, null | PropsValues> = (
  value,
) => {
  if (
    value === null ||
    value === undefined ||
    (Array.isArray(value) && value.length === 0)
  ) {
    return null;
  }
  if (Array.isArray(value)) {
    return value.pop() as PropsValues;
  }
  return value;
};

export interface LinkPropShape extends StaticPropSource {
  sourceTypeSettings: {
    instance: {
      // @see DRUPAL_DISABLED
      // @see DRUPAL_OPTIONAL
      // @see DRUPAL_REQUIRED
      title: 0 | 1 | 2;
    };
  };
}

const link: Transformer<
  BaseTransformOptions,
  Array<null | string | PropsValues> | null | string | PropsValues,
  PropsValuesOrArrayOfPropsValues,
  LinkPropShape
> = (value, options, propSource) => {
  const records: Array<PropsValues> = Array.isArray(value) ? value : [value];
  const returnValue = records.reduce(
    (carry: Array<null | string | PropsValues>, record: PropsValues) => {
      // `1` corresponds to `DRUPAL_OPTIONAL` and `2` to DRUPAL_REQUIRED on the
      // server side.
      if (![1, 2].includes(propSource?.sourceTypeSettings?.instance?.title)) {
        const uri = mainProperty(
          [record],
          { name: 'uri', multiple: false },
          propSource,
        );
        const match = uri.match(ENTITY_AUTOCOMPLETE_MATCH);
        if (match !== null) {
          // LinkWidget with autocomplete support only supports matching on node
          // entities.
          // @todo Add support for other entity types once core does -
          // https://www.drupal.org/i/2423093
          return carry.concat(`entity:node/${match[1]}`);
        }
        return carry.concat(uri);
      }
      if (record === null) {
        return carry.concat(record);
      }
      const match = record.uri.match(ENTITY_AUTOCOMPLETE_MATCH);
      if (match !== null) {
        // LinkWidget with autocomplete support only supports matching on node
        // entities.
        // @todo Add support for other entity types once core does -
        // https://www.drupal.org/i/2423093
        return carry.concat({ ...record, uri: `entity:node/${match[1]}` });
      }
      return carry.concat(record);
    },
    [],
  );
  if (options.multiple) {
    return returnValue;
  }
  const singleValue = returnValue.shift();
  if (singleValue === undefined) {
    return null;
  }
  return singleValue;
};

const cast: Transformer<
  {
    to: 'number' | 'boolean' | 'integer';
    multiple?: boolean;
  },
  null | number | boolean | Array<null | number | boolean>,
  null | string | Array<null | string>
> = (value, options) => {
  const { to = 'number', multiple = false } = options;
  const records: Array<null | string> = Array.isArray(value) ? value : [value];
  const returnValue = records.reduce(
    (carry: Array<null | number | boolean>, value: null | string) => {
      if (value === null) {
        return carry.concat(null);
      }
      if (to === 'number') {
        return carry.concat(Number(value));
      }
      if (to === 'integer') {
        return carry.concat(parseInt(value));
      }
      if (value === 'false') {
        return carry.concat(false);
      }
      return carry.concat(Boolean(value));
    },
    [],
  );
  if (multiple) {
    return returnValue;
  }
  const singleValue = returnValue.shift();
  if (singleValue === undefined) {
    return null;
  }
  return singleValue;
};

interface DateFieldPropSource extends StaticPropSource {
  sourceTypeSettings: {
    storage: {
      // @see \Drupal\datetime\Plugin\Field\FieldType\DateTimeItem::DATETIME_TYPE_DATE
      // @see \Drupal\datetime\Plugin\Field\FieldType\DateTimeItem::DATETIME_TYPE_DATETIME
      datetime_type: 'date' | 'datetime';
    };
  };
}

const dateTime: Transformer<
  {
    type: 'date' | 'datetime';
    multiple?: boolean;
  },
  null | string | Array<null | string>,
  PropsValuesOrArrayOfPropsValues,
  DateFieldPropSource
> = (value, options, propSource) => {
  if (propSource === null || propSource === undefined) {
    return null;
  }
  const type = propSource.sourceTypeSettings.storage.datetime_type;
  // @see \Drupal\Component\Datetime\DateTimePlus::setDefaultDateTime
  const records: Array<PropsValues> = Array.isArray(value) ? value : [value];

  const returnValue = records.reduce(
    (carry: Array<null | string>, record: PropsValues) => {
      if (record === null) {
        return carry.concat(null);
      }
      let timeString = '12:00:00';
      if (!('date' in record)) {
        return carry.concat(null);
      }
      const dateString = record.date;
      if (type === 'date') {
        return carry.concat(dateString);
      }
      if ('time' in record) {
        timeString = record.time;
      }
      // @todo Update this in https://www.drupal.org/project/canvas/issues/3501281, which will allow removing the FE-special casing in \Drupal\canvas\PropExpressions\StructuredData\Evaluator::evaluate()
      return carry.concat(
        new Date(`${dateString} ${timeString}+0000`).toISOString(),
      );
    },
    [],
  );
  if (options.multiple) {
    return returnValue;
  }
  const singleValue = returnValue.shift();
  if (singleValue === undefined) {
    return null;
  }
  return singleValue;
};

const mediaSelection: Transformer<BaseTransformOptions, null | PropsValues> = (
  value,
) => {
  if (value && typeof value === 'object' && 'selection' in value) {
    return value.selection;
  }
  return null;
};

const transforms = {
  mainProperty,
  firstRecord,
  dateTime,
  mediaSelection,
  cast,
  link,
};

export type Transforms = typeof transforms;

export default transforms;
