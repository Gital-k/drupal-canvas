import { useState } from 'react';

import Toggle from '@/components/form/components/Toggle';
import { useFieldContext } from '@/components/form/contexts/FieldContext';
import { wrapRHF } from '@/components/form/wrapRHF';
import { a2p } from '@/local_packages/utils.js';

import type { Attributes } from '@/types/DrupalAttribute';

interface DrupalElement {
  '#default_value'?: string | number;
  '#value'?: string | number;
  [key: string]: any;
}
const DrupalToggle = ({
  attributes = {},
  element = {},
}: {
  attributes?: Attributes;
  element?: DrupalElement;
}) => {
  const [isChecked, setIsChecked] = useState(
    Object.prototype.hasOwnProperty.call(Object(element), '#value')
      ? element['#value']
      : element?.['#default_value'] || false,
  );

  const fieldContext = useFieldContext();

  return (
    <Toggle
      checked={!!isChecked}
      onCheckedChange={(value: boolean) => {
        fieldContext?.triggerChange(value);
        setIsChecked(value);
      }}
      attributes={a2p(
        {
          ...attributes,
          // Setting the `aria-checked` attribute explicitly to avoid having it
          // end up as "checked" instead of "true" due to something that the Switch
          // primitive from Radix UI (used by the Toggle component) misinterprets
          // when processing the attributes it receives.
          // The `aria-checked` attribute needs to be set to "true" or "false".
          // @see https://w3c.github.io/aria/#aria-checked
          'aria-checked': isChecked ? 'true' : 'false',
        },
        {},
        { skipAttributes: ['value', 'onChange', 'type', 'checked'] },
      )}
    />
  );
};

export default wrapRHF(DrupalToggle);
