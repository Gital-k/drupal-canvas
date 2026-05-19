import {
  Box,
  Flex,
  Heading,
  ScrollArea,
  Separator,
  Text,
  TextField,
} from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  updateMachineName,
  updateProperty,
  updateSubmitSettings,
} from '@/features/formBuilder/formBuilderSlice';
import {
  selectSelectedElement,
  selectSubmitSettings,
} from '@/features/formBuilder/selectors';

import { AttributesProperty } from './properties/AttributesProperty';
import { BooleanProperty } from './properties/BooleanProperty';
import { NumberProperty } from './properties/NumberProperty';
import { OptionsProperty } from './properties/OptionsProperty';
import { SelectProperty } from './properties/SelectProperty';
import { StringProperty } from './properties/StringProperty';
import { TextProperty } from './properties/TextProperty';

import type {
  FormElementSchema,
  PropertySchema,
} from '@/features/formBuilder/types';

interface FormPropertyPanelProps {
  schemas: Record<string, FormElementSchema>;
}

export function FormPropertyPanel({ schemas }: FormPropertyPanelProps) {
  const dispatch = useAppDispatch();
  const selected = useAppSelector(selectSelectedElement);
  const submitSettings = useAppSelector(selectSubmitSettings);

  if (!selected) {
    return (
      <Box data-testid="form-properties-empty" p="3">
        <Text size="1" color="gray">
          Select an element to edit its properties.
        </Text>
      </Box>
    );
  }

  if (selected.type === '__submit_settings__') {
    return (
      <ScrollArea>
        <Box p="3">
          <Heading size="2" mb="3">
            Form settings
          </Heading>
          <Flex direction="column" gap="3">
            <StringProperty
              label="Submit button text"
              value={submitSettings.button_text}
              onChange={(v) =>
                dispatch(updateSubmitSettings({ button_text: v }))
              }
            />
            <TextProperty
              label="Confirmation message"
              value={submitSettings.confirmation_message}
              onChange={(v) =>
                dispatch(updateSubmitSettings({ confirmation_message: v }))
              }
            />
            <StringProperty
              label="Redirect URL (optional)"
              value={submitSettings.redirect_url}
              onChange={(v) =>
                dispatch(updateSubmitSettings({ redirect_url: v }))
              }
              placeholder="/thank-you"
            />
          </Flex>
        </Box>
      </ScrollArea>
    );
  }

  const schema = schemas[selected.type];

  if (!schema) {
    return (
      <Box p="3">
        <Text size="1" color="gray">
          No editable properties for this element type.
        </Text>
      </Box>
    );
  }

  const propertiesByGroup = {
    basic: [] as [string, PropertySchema][],
    validation: [] as [string, PropertySchema][],
    advanced: [] as [string, PropertySchema][],
  };

  for (const [key, propSchema] of Object.entries(schema.properties)) {
    propertiesByGroup[propSchema.group].push([key, propSchema]);
  }

  const renderProperty = (key: string, propSchema: PropertySchema) => {
    const value = selected.properties[key] ?? propSchema.default ?? '';
    const onChange = (v: unknown) =>
      dispatch(updateProperty({ uuid: selected.uuid, key, value: v }));

    switch (propSchema.type) {
      case 'string':
        return (
          <StringProperty
            key={key}
            label={propSchema.label}
            value={value as string}
            onChange={onChange as (v: string) => void}
            required={propSchema.required}
          />
        );
      case 'text':
        return (
          <TextProperty
            key={key}
            label={propSchema.label}
            value={value as string}
            onChange={onChange as (v: string) => void}
          />
        );
      case 'boolean':
        return (
          <BooleanProperty
            key={key}
            label={propSchema.label}
            value={value as boolean}
            onChange={onChange as (v: boolean) => void}
          />
        );
      case 'number':
        return (
          <NumberProperty
            key={key}
            label={propSchema.label}
            value={value as number}
            onChange={onChange as (v: number | undefined) => void}
          />
        );
      case 'select':
        return (
          <SelectProperty
            key={key}
            label={propSchema.label}
            value={value as string}
            options={propSchema.options ?? {}}
            onChange={onChange as (v: string) => void}
          />
        );
      case 'options':
        return (
          <OptionsProperty
            key={key}
            label={propSchema.label}
            value={value as Record<string, string>}
            onChange={onChange as (v: Record<string, string>) => void}
          />
        );
      case 'attributes':
        return (
          <AttributesProperty
            key={key}
            label={propSchema.label}
            value={value as Record<string, string | string[]>}
            onChange={
              onChange as (v: Record<string, string | string[]>) => void
            }
          />
        );
      default:
        return null;
    }
  };

  return (
    <ScrollArea style={{ height: '100%' }}>
      <Box
        data-testid="form-properties"
        data-element-uuid={selected.uuid}
        data-element-type={selected.type}
        p="3"
      >
        <Heading size="2" mb="3">
          {schema.label}
        </Heading>

        {propertiesByGroup.basic.length > 0 && (
          <Flex
            data-testid="form-properties-basic"
            direction="column"
            gap="3"
            mb="3"
          >
            {propertiesByGroup.basic.map(([key, prop]) =>
              renderProperty(key, prop),
            )}
          </Flex>
        )}

        {propertiesByGroup.validation.length > 0 && (
          <>
            <Separator size="4" my="3" />
            <Text size="1" weight="medium" color="gray" mb="2" as="div">
              Validation
            </Text>
            <Flex
              data-testid="form-properties-validation"
              direction="column"
              gap="3"
              mb="3"
            >
              {propertiesByGroup.validation.map(([key, prop]) =>
                renderProperty(key, prop),
              )}
            </Flex>
          </>
        )}

        {propertiesByGroup.advanced.length > 0 && (
          <>
            <Separator size="4" my="3" />
            <Text size="1" weight="medium" color="gray" mb="2" as="div">
              Advanced
            </Text>
            <Flex
              data-testid="form-properties-advanced"
              direction="column"
              gap="3"
              mb="3"
            >
              {propertiesByGroup.advanced.map(([key, prop]) =>
                renderProperty(key, prop),
              )}
            </Flex>
          </>
        )}

        <Separator size="4" my="3" />
        <Text size="1" weight="medium" color="gray" mb="2" as="div">
          Field key
        </Text>
        <label data-testid="form-property-machine-name">
          <Text as="div" size="1" weight="medium" mb="1">
            Machine name
          </Text>
          <TextField.Root
            size="1"
            value={selected.machineName}
            onChange={(e) =>
              dispatch(
                updateMachineName({
                  uuid: selected.uuid,
                  machineName: e.target.value,
                }),
              )
            }
            pattern="[a-z0-9_]+"
          />
        </label>
      </Box>
    </ScrollArea>
  );
}
