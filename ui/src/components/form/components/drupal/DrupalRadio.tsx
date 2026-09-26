import { createContext, useContext, useRef, useState } from 'react';

import { RadioGroup, RadioItem } from '@/components/form/components/Radio';
import { useFieldContext } from '@/components/form/contexts/FieldContext';
import { withRHF } from '@/components/form/react-hook-form/withRHF';
import { a2p } from '@/local_packages/utils.js';

import type { Attributes } from '@/types/DrupalAttribute';

interface RadioContextType {
  selected: string | number | null;
  updateSelected: (value: string | number | undefined) => void;
}

const RadioContext = createContext<RadioContextType>({
  selected: null,
  updateSelected: () => {},
});
const DrupalRadioGroup = ({
  attributes = {},
  children,
}: {
  attributes?: Attributes;
  children?: React.ReactNode;
}) => {
  // Do not use `||` to fall back to the next candidate: a radio group whose
  // selected value is '0' is perfectly valid, but '0' is falsy in JavaScript
  // and would be discarded, leaving the group with nothing selected. Only an
  // absent value ('' is what canvas_stark renders when there is no default)
  // may fall through.
  // @see canvas_stark_preprocess_radios()
  const defaultValue =
    (attributes.value as string | null | undefined) ??
    (attributes['data-canvas-radio-default-value'] as
      | string
      | null
      | undefined);
  const initialValue =
    defaultValue === undefined || defaultValue === '' ? null : defaultValue;
  const [selected, setSelected] = useState<string | number | null>(
    initialValue,
  );

  // Adjust the selection when the group is re-rendered with a different value
  // than the one it was last rendered with. The form is re-fetched after
  // publishing, and without this the state from the previous render would win,
  // leaving the group showing a value that is no longer the stored one.
  const [renderedValue, setRenderedValue] = useState<string | number | null>(
    initialValue,
  );
  if (initialValue !== renderedValue) {
    setRenderedValue(initialValue);
    setSelected(initialValue);
  }

  const fieldContext = useFieldContext();

  // Callback provided to each radio item in the group, which will update the
  // selected value that is kept track of within this component.
  const updateSelected = (value: string | number | undefined) => {
    fieldContext?.triggerChange(value);
    setSelected(value ?? null);
  };

  return (
    // Wrap the radios group in a context that keeps track of the selected value.
    <RadioContext.Provider value={{ selected, updateSelected }}>
      <RadioGroup
        attributes={a2p(attributes, {}, { skipAttributes: ['onChange'] })}
      >
        {children}
      </RadioGroup>
    </RadioContext.Provider>
  );
};

const DrupalRadioItem = ({ attributes = {} }: { attributes?: Attributes }) => {
  // Somewhere in the hyperscriptify process, a value of 0 *or* '0' is
  // being converted into an empty string. This is a quick fix to get this
  // working, and the underlying cause can be addressed later as it has proven
  // to not be a quick fix.
  if (attributes.value === '') {
    attributes.value = '0';
  }

  const { selected, updateSelected } = useContext(RadioContext);

  // Store the initial value in a ref to ensure it does not change. The parent
  // `DrupalRadioGroup` will handle value changes, and these radio items
  // represent an available option.
  const valueRef = useRef<string | number | undefined>(
    typeof attributes.value === 'string' || typeof attributes.value === 'number'
      ? attributes.value
      : undefined,
  );

  const isChecked = `${selected}` === `${valueRef.current}`;

  return (
    <RadioItem
      attributes={{
        ...attributes,
        value: `${valueRef.current}`,
        checked: isChecked,
        // The checked attribute does not reliably appear in the DOM despite
        // the element itself having the checked property as `true` and the
        // input rendering as checked. This has no functional impact outside
        // of tests that are looking to confirm the input is set as checked, so
        // we add this attribute to make the checked status known via DOM query.
        'data-drupal-canvas-checked': isChecked,
      }}
      onChange={() => updateSelected(valueRef.current)}
    />
  );
};

const WrappedDrupalRadioGroup = withRHF(DrupalRadioGroup);

export { WrappedDrupalRadioGroup as DrupalRadioGroup, DrupalRadioItem };
