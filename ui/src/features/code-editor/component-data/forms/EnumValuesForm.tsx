import React, { useEffect, useRef, useState } from 'react';
import { PlusIcon, TrashIcon } from '@radix-ui/react-icons';
import { Box, Button, Flex, Text, TextField } from '@radix-ui/themes';

import {
  FormElement,
  Label,
} from '@/features/code-editor/component-data/FormElement';

import type { CodeComponentPropEnumItem } from '@/types/CodeComponent';

const getDuplicateValueIndices = (
  arr: Array<Pick<CodeComponentPropEnumItem, 'value'>>,
) => {
  const valueCount: Record<string | number, number> = {};
  arr.forEach(({ value }) => {
    valueCount[value] = (valueCount[value] || 0) + 1;
  });
  return arr.map(({ value }) => valueCount[value] > 1 && value !== '');
};

export function EnumValuesForm({
  propId,
  values = [],
  onChange,
  type,
  isDisabled,
}: {
  propId: string;
  values: Array<CodeComponentPropEnumItem>;
  onChange: (values: Array<CodeComponentPropEnumItem>) => void;
  type: 'string' | 'integer' | 'number';
  isDisabled: boolean;
}) {
  const [touched, setTouched] = useState(values.map(({ label }) => !!label));
  const typeRef = useRef(type);

  useEffect(() => {
    if (typeRef.current !== type && type !== undefined) {
      setTouched([false]);
    }
    typeRef.current = type;
  }, [type]);

  const invalidValueIndices = getDuplicateValueIndices(values);

  const handleAdd = () => {
    onChange([...values, { label: '', value: '' }]);
  };

  const handleRemove = (index: number) => {
    const newValues = [...values];
    newValues.splice(index, 1);
    setTouched((prev) => prev.filter((_, i) => i !== index));
    onChange(newValues);
  };

  const handleValueChange = (index: number, value: string | number) => {
    const newValues = [...values];
    if (!touched[index]) {
      newValues[index] = { ...newValues[index], value, label: String(value) };
    } else {
      newValues[index] = { ...newValues[index], value };
    }
    onChange(newValues);
  };

  const handleLabelChange = (index: number, label: string) => {
    const newValues = [...values];
    newValues[index] = { ...newValues[index], label };
    setTouched((prev) => Object.assign([...prev], { [index]: true }));
    onChange(newValues);
  };

  return (
    <Flex mt="1" direction="column" gap="2" flexGrow="1" width="100%">
      {values.map((item, index) => (
        <React.Fragment key={index}>
          <Flex gap="2" align="end" flexGrow="1" width="100%">
            <Box flexGrow="1" flexShrink="1">
              <FormElement>
                <Label htmlFor={`canvas-prop-enum-value-${propId}-${index}`}>
                  Value
                </Label>
                <TextField.Root
                  autoComplete="off"
                  data-testid={`canvas-prop-enum-value-${propId}-${index}`}
                  id={`canvas-prop-enum-value-${propId}-${index}`}
                  type={
                    ['integer', 'number'].includes(type) ? 'number' : 'text'
                  }
                  step={type === 'integer' ? 1 : undefined}
                  value={item.value}
                  size="1"
                  onChange={(e) => handleValueChange(index, e.target.value)}
                  placeholder={
                    {
                      string: 'Enter a text value',
                      integer: 'Enter an integer',
                      number: 'Enter a number',
                    }[type]
                  }
                  disabled={isDisabled}
                  color={invalidValueIndices[index] ? 'red' : undefined}
                />
              </FormElement>
            </Box>
            <Box flexGrow="1" flexShrink="1">
              <FormElement>
                <Label htmlFor={`canvas-prop-enum-label-${propId}-${index}`}>
                  Label
                </Label>
                <TextField.Root
                  autoComplete="off"
                  data-testid={`canvas-prop-enum-label-${propId}-${index}`}
                  id={`canvas-prop-enum-label-${propId}-${index}`}
                  type="text"
                  value={item.label}
                  size="1"
                  onChange={(e) => handleLabelChange(index, e.target.value)}
                  placeholder="Enter a label"
                  disabled={isDisabled}
                />
              </FormElement>
            </Box>
            <Button
              aria-label="Remove value"
              data-testid={`canvas-prop-enum-value-delete-${propId}-${index}`}
              size="1"
              color="red"
              variant="soft"
              onClick={() => handleRemove(index)}
              disabled={isDisabled}
            >
              <TrashIcon />
            </Button>
          </Flex>
          {invalidValueIndices[index] && (
            <Text color="red" size="1">
              Value must be unique.
            </Text>
          )}
        </React.Fragment>
      ))}
      <Button size="1" variant="soft" onClick={handleAdd} disabled={isDisabled}>
        <Flex gap="1" align="center">
          <PlusIcon />
          <Text size="1">Add value</Text>
        </Flex>
      </Button>
    </Flex>
  );
}
