import type { PropsValues } from '@drupal-canvas/types';
import type {
  PropSource,
  StaticPropSource,
} from '@/features/layout/layoutModelSlice';
import { VALUE_THAT_MEANS_REMOVE } from '@/utils/function-utils';

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

  // Pass through the removal sentinel so flaggedForRemoval can detect it
  // downstream and trigger the fallback to example/default values.
  if ((value as unknown) === VALUE_THAT_MEANS_REMOVE) {
    return VALUE_THAT_MEANS_REMOVE;
  }

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
  const returnValue = records.map((record: PropsValues) => {
    if (record === null || record === undefined) {
      return null;
    }
    if (name in record) {
      return record[name];
    }
    return null;
  });
  if (multiple) {
    return returnValue.filter((v) => v !== null && v !== '');
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
  const returnValue = records.map((record: PropsValues) => {
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
        return `entity:node/${match[1]}`;
      }
      return uri;
    }
    if (record === null) {
      return record;
    }
    const match = record.uri.match(ENTITY_AUTOCOMPLETE_MATCH);
    if (match !== null) {
      // LinkWidget with autocomplete support only supports matching on node
      // entities.
      // @todo Add support for other entity types once core does -
      // https://www.drupal.org/i/2423093
      return { ...record, uri: `entity:node/${match[1]}` };
    }
    return record;
  });
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
  const returnValue = records.map((value: null | string) => {
    if (value === null) {
      return null;
    }
    if (to === 'number') {
      return Number(value);
    }
    if (to === 'integer') {
      return parseInt(value);
    }
    if (value === 'false') {
      return false;
    }
    return Boolean(value);
  });
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

  const returnValue = records.map((record: PropsValues) => {
    if (record === null) {
      return null;
    }
    let timeString = '12:00:00';
    if (!('date' in record)) {
      return null;
    }
    const dateString = record.date;
    if (type === 'date') {
      return dateString;
    }
    if ('time' in record) {
      timeString = record.time;
    }
    // @todo Update this in https://www.drupal.org/project/canvas/issues/3501281, which will allow removing the FE-special casing in \Drupal\canvas\PropExpressions\StructuredData\Evaluator::evaluate()
    return new Date(`${dateString} ${timeString}+0000`).toISOString();
  });
  if (options.multiple) {
    return returnValue;
  }
  const singleValue = returnValue.shift();
  if (singleValue === undefined) {
    return null;
  }
  return singleValue;
};

const mediaSelection: Transformer<
  BaseTransformOptions,
  string | null | PropsValues | Array<PropsValues>
> = (value, options) => {
  const { multiple = false } = options;
  if (!value || typeof value !== 'object' || !('selection' in value)) {
    // No selection wrapper at all — signal removal so the prop falls back to
    // example/default via flaggedForRemoval rather than persisting stale data.
    return VALUE_THAT_MEANS_REMOVE;
  }
  const selection = value.selection;
  // Treat empty selections (empty object or empty array) as removal.
  if (
    selection === null ||
    selection === undefined ||
    (typeof selection === 'object' && Object.keys(selection).length === 0)
  ) {
    return VALUE_THAT_MEANS_REMOVE;
  }
  if (multiple) {
    // For multi-value fields, ensure the selection is an array so downstream
    // transforms (mainProperty) can iterate and sort by weight.
    return Array.isArray(selection) ? selection : Object.values(selection);
  }
  return selection;
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
