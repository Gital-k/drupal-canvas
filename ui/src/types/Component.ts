import type { CodeComponentSerialized } from '@/types/CodeComponent';
import type { PatternsList } from '@/types/Pattern';
import type { TransformConfig } from '@/utils/transforms';

export interface FieldData {
  [key: string]: FieldDataItem;
}

export interface DefaultValues {
  resolved: object;
  source: object;
}

export interface FieldDataItem {
  expression: string;
  sourceType: string;
  sourceTypeSettings?: {
    storage?: object;
    instance?: object;
  };
  jsonSchema?: {
    type: 'number' | 'integer' | 'string' | 'boolean' | 'array' | 'object';
    properties?: object;
    enum?: any[];
    format?: string;
  };
  default_values: DefaultValues;
  [x: string | number | symbol]: unknown;
}

interface BaseComponent {
  id: string;
  name: string;
  library: string;
  source: string;
  default_markup: string;
  css: string;
  js_header: string;
  js_footer: string;
  version: string;
  // @todo Move to PropSourceComponent in https://www.drupal.org/project/canvas/issues/3521041
  propSources: FieldData;
  broken: boolean;
}

export type libraryTypes =
  | 'dynamic_components'
  | 'primary_components'
  | 'extension_components'
  | 'elements';

// For now, these are only Blocks. Later, it will be more.
export interface DynamicComponent extends BaseComponent {
  library: 'dynamic_components';
}

// JSComponent Interface
export interface JSComponent extends BaseComponent {
  library: 'primary_components';
  source: 'Code component';
  transforms: any[];
}

export interface SlottedComponent extends BaseComponent {
  library: 'elements' | 'extension_components';
  metadata: {
    slots?: {
      [key: string]: {
        title: string;
        [key: string]: any;
      };
    };
    [key: string]: any;
  };
}

// PropSourceComponent Interface
export interface PropSourceComponent extends SlottedComponent {
  propSources: FieldData;
  dynamic_prop_source_candidates: Record<string, any>;
  transforms: TransformConfig;
}
// Union type for any component
export type CanvasComponent =
  | DynamicComponent
  | JSComponent
  | PropSourceComponent
  | SlottedComponent;

// ComponentsList representing the API response
export interface ComponentsList {
  [key: string]: CanvasComponent;
}

export type Folder = {
  id: string;
  name: string;
  items: string[];
  weight?: number;
};

export interface Folders {
  [key: string]: Folder;
}

export type FolderInList = {
  id: string;
  name: string;
  weight?: number;
  items:
    | ComponentsList
    | Record<string, CodeComponentSerialized>
    | PatternsList;
};

export type FoldersInList = FolderInList[];

/**
 * Type predicate.
 *
 * @param {CanvasComponent | undefined} component
 *   Component to test.
 *
 * @return boolean
 *   TRUE if the component has field data.
 *
 * @todo rename this to componentHasPropSources in https://www.drupal.org/project/canvas/issues/3504421
 */
export const componentHasFieldData = (
  component: CanvasComponent | undefined,
): component is PropSourceComponent => {
  return component !== undefined && 'propSources' in component;
};

export const componentHasSlots = (
  component: CanvasComponent | undefined,
): component is SlottedComponent => {
  return (
    component !== undefined &&
    'metadata' in component &&
    'slots' in component.metadata
  );
};
