import { useEffect, useMemo } from 'react';
import { ChevronDownIcon } from '@radix-ui/react-icons';
import {
  Box,
  Button,
  Checkbox,
  Flex,
  Popover,
  Select,
  Text,
} from '@radix-ui/themes';

import './FormPropTypeEnum.css';

import { useAppDispatch } from '@/app/hooks';
import {
  Divider,
  FormElement,
  Label,
  RequiredFieldError,
} from '@/features/code-editor/component-data/FormElement';
import { EnumValuesForm } from '@/features/code-editor/component-data/forms/EnumValuesForm';
import { DEFAULT_ENUM_OPTIONS } from '@/features/code-editor/component-data/Props';
import { useRequiredProp } from '@/features/code-editor/hooks/useRequiredProp';
import {
  dispatchUpdateProp,
  hasNonEmptyArrayValue,
} from '@/features/code-editor/utils/arrayPropUtils';
import {
  VALUE_MODE_LIMITED,
  VALUE_MODE_UNLIMITED,
} from '@/types/CodeComponent';

import type {
  CodeComponentProp,
  CodeComponentPropEnumItem,
  ValueMode,
} from '@/types/CodeComponent';

const NONE_VALUE = '_none_';

const validateValue = (item: CodeComponentPropEnumItem): boolean => {
  return item.value !== '' && item.label !== '';
};

export default function FormPropTypeEnum({
  id,
  enum: enumValues = [],
  example: defaultValue,
  required,
  type,
  isDisabled = false,
  allowMultiple = false,
  valueMode = VALUE_MODE_UNLIMITED,
  limitedCount = 1,
}: Pick<CodeComponentProp, 'id' | 'enum'> & {
  example: string | string[];
  required: boolean;
  type: 'string' | 'integer' | 'number';
  isDisabled?: boolean;
  allowMultiple?: boolean;
  valueMode?: ValueMode;
  limitedCount?: number;
}) {
  const dispatch = useAppDispatch();
  const isLimitedMode = valueMode === VALUE_MODE_LIMITED;

  const validEnumValues = useMemo(() => {
    return enumValues.filter((item) => validateValue(item));
  }, [enumValues]);

  const hasNonEmptyMultiValue = hasNonEmptyArrayValue(
    allowMultiple ? defaultValue : [],
  );
  const normalizedExampleForHook: string | string[] = allowMultiple
    ? hasNonEmptyMultiValue
      ? 'non-empty'
      : ''
    : (defaultValue as string);

  const { showRequiredError, setShowRequiredError } = useRequiredProp(
    required,
    normalizedExampleForHook,
    () => {
      if (validEnumValues.length === 0) {
        // No options yet — create a default option and select it.
        const defaultOption = DEFAULT_ENUM_OPTIONS[type];
        dispatchUpdateProp(dispatch, id, {
          enum: [defaultOption],
          example: allowMultiple
            ? [String(defaultOption.value)]
            : String(defaultOption.value),
        });
      } else if (allowMultiple || !defaultValue) {
        // Options exist — select the first one.
        dispatchUpdateProp(dispatch, id, {
          example: allowMultiple
            ? [String(validEnumValues[0].value)]
            : String(validEnumValues[0].value),
        });
      }
    },
    [dispatch, id, type, validEnumValues, allowMultiple],
  );

  // Normalize defaultValue to a string array and filter to only valid enum values.
  const validSelectedValues = useMemo(() => {
    if (!allowMultiple) return [];
    const selected = Array.isArray(defaultValue)
      ? defaultValue.map((v) => String(v))
      : defaultValue
        ? [String(defaultValue)]
        : [];
    const validValueStrings = validEnumValues.map((item) => String(item.value));
    return selected.filter((v) => validValueStrings.includes(v));
  }, [allowMultiple, defaultValue, validEnumValues]);

  // Show error when required but no valid example or no options.
  // Also handles the multi-value case where defaultValue is an empty array
  // (![] is false in JS, so we use hasNonEmptyMultiValue instead).
  useEffect(() => {
    const hasNoValue = allowMultiple ? !hasNonEmptyMultiValue : !defaultValue;
    if (required && (validEnumValues.length === 0 || hasNoValue)) {
      setShowRequiredError(true);
    } else {
      setShowRequiredError(false);
    }
  }, [
    required,
    defaultValue,
    validEnumValues.length,
    setShowRequiredError,
    allowMultiple,
    hasNonEmptyMultiValue,
  ]);

  const handleDefaultValueChange = (value: string | number) => {
    dispatchUpdateProp(dispatch, id, {
      example: value === NONE_VALUE ? '' : String(value),
    });
  };

  return (
    <Flex direction="column" gap="4" flexGrow="1">
      <FormElement>
        <Text size="1" weight="medium" as="div">
          Options
        </Text>
        <EnumValuesForm
          propId={id}
          values={enumValues || []}
          type={type}
          isDisabled={isDisabled}
          onChange={(values) => {
            const validNewValues = (values || []).filter((item) =>
              validateValue(item),
            );

            const updates: Partial<CodeComponentProp> = { enum: values };

            // If in limited mode and valid options count decreased, update limitedCount.
            // Only reduce when valid options actually decreased (not when an empty row is
            // being added), to avoid resetting a user-configured cardinality back to 1.
            if (
              allowMultiple &&
              isLimitedMode &&
              validNewValues.length < validEnumValues.length &&
              validNewValues.length < limitedCount
            ) {
              updates.limitedCount = Math.max(1, validNewValues.length);
            }

            // For allowMultiple, filter out selected values that no longer exist
            if (allowMultiple && Array.isArray(defaultValue)) {
              const validValueStrings = validNewValues.map((item) =>
                String(item.value),
              );
              const filteredSelected = defaultValue.filter((v) =>
                validValueStrings.includes(String(v)),
              );
              if (filteredSelected.length !== defaultValue.length) {
                updates.example = filteredSelected;
              }
            } else {
              // For single value: Update default value if:
              // 1. Current default value doesn't exist in new values, OR
              // 2. Current default value is empty, prop is required, and there are valid values.
              if (
                !validNewValues.some((item) => item.value === defaultValue) ||
                (!defaultValue && validNewValues.length > 0)
              ) {
                if (required && validNewValues.length > 0) {
                  updates.example = String(validNewValues[0].value);
                } else {
                  updates.example = '';
                }
              }
            }

            dispatchUpdateProp(dispatch, id, updates);
          }}
        />
      </FormElement>
      {validEnumValues.length > 0 && (
        <>
          <Divider />
          <FormElement>
            <Label htmlFor={`prop-enum-default-${id}`}>
              Default value
              {allowMultiple && isLimitedMode && ` (max ${limitedCount})`}
            </Label>
            {allowMultiple ? (
              <Popover.Root>
                <Popover.Trigger>
                  <Button
                    variant="outline"
                    size="1"
                    color="gray"
                    className="fpe-btn-popover-trigger"
                  >
                    <Text size="1" truncate>
                      {validSelectedValues.length > 0
                        ? `${validSelectedValues.length} selected`
                        : '- None -'}
                    </Text>
                    <ChevronDownIcon />
                  </Button>
                </Popover.Trigger>
                <Popover.Content
                  className="fpe-popover-content"
                  style={{ width: 'var(--radix-popover-trigger-width)' }}
                  side="bottom"
                  align="start"
                >
                  <Flex direction="column" gap="2">
                    {validEnumValues.map((item, index) => {
                      const isSelected = validSelectedValues.includes(
                        String(item.value),
                      );
                      const isAtLimit =
                        isLimitedMode &&
                        validSelectedValues.length >= limitedCount &&
                        !isSelected;
                      return (
                        <Text
                          as="label"
                          key={`${item.value}-${index}`}
                          size="2"
                        >
                          <Flex gap="2" align="center">
                            <Checkbox
                              size="1"
                              checked={isSelected}
                              disabled={isAtLimit || isDisabled}
                              onCheckedChange={(checked) => {
                                let newSelected: string[];
                                if (checked) {
                                  newSelected = [
                                    ...validSelectedValues,
                                    String(item.value),
                                  ];
                                } else {
                                  newSelected = validSelectedValues.filter(
                                    (v) => v !== String(item.value),
                                  );
                                }

                                // Convert back to proper type for backend
                                const typedExample =
                                  type === 'integer' || type === 'number'
                                    ? newSelected.map((v) => Number(v))
                                    : newSelected;

                                dispatchUpdateProp(dispatch, id, {
                                  example: typedExample,
                                });
                              }}
                            />
                            {item.label}
                          </Flex>
                        </Text>
                      );
                    })}
                    {isLimitedMode && (
                      <>
                        <Box className="fpe-divider" />
                        <Text size="1" color="gray">
                          Selected: {validSelectedValues.length} /{' '}
                          {limitedCount}
                        </Text>
                      </>
                    )}
                  </Flex>
                </Popover.Content>
              </Popover.Root>
            ) : (
              <Select.Root
                value={
                  defaultValue === '' ? NONE_VALUE : (defaultValue as string)
                }
                onValueChange={(value) => {
                  handleDefaultValueChange(value);
                  // Show/hide error based on whether value is empty while required
                  setShowRequiredError(required && value === NONE_VALUE);
                }}
                size="1"
              >
                <Select.Trigger id={`prop-enum-default-${id}`} />
                <Select.Content>
                  {!required && (
                    <Select.Item value={NONE_VALUE}>- None -</Select.Item>
                  )}
                  {validEnumValues.map((item, index) => (
                    <Select.Item
                      key={`${item.value}-${index}`}
                      value={String(item.value)}
                    >
                      {item.label}
                    </Select.Item>
                  ))}
                </Select.Content>
              </Select.Root>
            )}
          </FormElement>
        </>
      )}
      <RequiredFieldError show={showRequiredError} />
    </Flex>
  );
}
