import { InfoCircledIcon } from '@radix-ui/react-icons';
import {
  Box,
  Callout,
  Flex,
  Select,
  Switch,
  TextField,
} from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  addProp,
  removeProp,
  reorderProps,
  selectCodeComponentProperty,
  selectUnsavedProps,
  toggleRequired,
  updateProp,
} from '@/features/code-editor/codeEditorSlice';
import derivedPropTypes from '@/features/code-editor/component-data/derivedPropTypes';
import {
  FormElement,
  Label,
} from '@/features/code-editor/component-data/FormElement';
import FormPropTypeBoolean from '@/features/code-editor/component-data/forms/FormPropTypeBoolean';
import FormPropTypeEnum from '@/features/code-editor/component-data/forms/FormPropTypeEnum';
import FormPropTypeFormattedText from '@/features/code-editor/component-data/forms/FormPropTypeFormattedText';
import FormPropTypeImage from '@/features/code-editor/component-data/forms/FormPropTypeImage';
import FormPropTypeLink from '@/features/code-editor/component-data/forms/FormPropTypeLink';
import FormPropTypeTextField from '@/features/code-editor/component-data/forms/FormPropTypeTextField';
import FormPropTypeVideo from '@/features/code-editor/component-data/forms/FormPropTypeVideo';
import SortableList from '@/features/code-editor/component-data/SortableList';
import { getPropMachineName } from '@/features/code-editor/utils/utils';

import type {
  CodeComponentProp,
  CodeComponentPropImageExample,
  CodeComponentPropVideoExample,
} from '@/types/CodeComponent';

export default function Props() {
  const dispatch = useAppDispatch();
  const props = useAppSelector(selectCodeComponentProperty('props'));
  const required = useAppSelector(selectCodeComponentProperty('required'));
  const componentStatus = useAppSelector(selectCodeComponentProperty('status'));
  const unsavedProps = useAppSelector(selectUnsavedProps);

  const handleAddProp = () => {
    dispatch(addProp());
  };

  const handleRemoveProp = (propId: string) => {
    dispatch(removeProp({ propId }));
  };

  const handleReorder = (oldIndex: number, newIndex: number) => {
    dispatch(reorderProps({ oldIndex, newIndex }));
  };

  const renderPropContent = (prop: CodeComponentProp) => {
    const propName = getPropMachineName(prop.name);
    const propIsUnsaved = unsavedProps.includes(prop.id);
    return (
      <Flex direction="column" flexGrow="1">
        <Flex mb="4" gap="4" align="end" width="100%" wrap="wrap">
          <Box flexShrink="0" flexGrow="1">
            <FormElement>
              <Label htmlFor={`prop-name-${prop.id}`}>Prop name</Label>
              <TextField.Root
                autoComplete="off"
                id={`prop-name-${prop.id}`}
                placeholder="Enter a name"
                value={prop.name}
                size="1"
                onChange={(e) =>
                  dispatch(
                    updateProp({
                      id: prop.id,
                      updates: { name: e.target.value },
                    }),
                  )
                }
                disabled={componentStatus && !propIsUnsaved}
              />
            </FormElement>
          </Box>
          <Box flexShrink="0" minWidth="120px">
            <FormElement>
              <Label htmlFor={`prop-type-${prop.id}`}>Type</Label>
              <Select.Root
                value={prop.derivedType as string}
                size="1"
                onValueChange={(value) => {
                  const selectedPropType = derivedPropTypes.find(
                    (item) => item.type === value,
                  );
                  if (selectedPropType) {
                    dispatch(
                      updateProp({
                        id: prop.id,
                        updates: {
                          derivedType: value,
                          example: '',
                          enum: undefined,
                          $ref: undefined,
                          format: undefined,
                          ...selectedPropType.init,
                        } as Partial<CodeComponentProp>,
                      }),
                    );
                  }
                }}
                disabled={componentStatus && !propIsUnsaved}
              >
                <Select.Trigger id={`prop-type-${prop.id}`} />
                <Select.Content>
                  {derivedPropTypes.map((type) => (
                    <Select.Item key={type.type} value={type.type}>
                      {type.displayName}
                    </Select.Item>
                  ))}
                </Select.Content>
              </Select.Root>
            </FormElement>
          </Box>

          <Flex direction="column" gap="2">
            <Label htmlFor={`prop-required-${prop.id}`}>Required</Label>
            <Switch
              id={`prop-required-${prop.id}`}
              checked={required.includes(propName)}
              size="1"
              mb="1"
              onCheckedChange={() =>
                dispatch(
                  toggleRequired({
                    propId: prop.id,
                  }),
                )
              }
              disabled={componentStatus}
            />
          </Flex>
        </Flex>

        {(() => {
          const disableInput = componentStatus && !propIsUnsaved;
          switch (prop.derivedType) {
            case 'text':
            case 'integer':
            case 'number':
              return (
                <FormPropTypeTextField
                  id={prop.id}
                  type={prop.type as 'string' | 'number' | 'integer'}
                  example={prop.example as string}
                  isDisabled={disableInput}
                />
              );
            case 'formattedText':
              return (
                <FormPropTypeFormattedText
                  id={prop.id}
                  example={prop.example}
                  isDisabled={disableInput}
                />
              );
            case 'link':
              return (
                <FormPropTypeLink
                  id={prop.id}
                  example={prop.example as string}
                  isDisabled={disableInput}
                />
              );
            case 'image':
              return (
                <FormPropTypeImage
                  id={prop.id}
                  example={prop.example as CodeComponentPropImageExample}
                  isDisabled={disableInput}
                  required={required.includes(propName)}
                />
              );
            case 'video':
              return (
                <FormPropTypeVideo
                  id={prop.id}
                  example={prop.example as CodeComponentPropVideoExample}
                  isDisabled={disableInput}
                  required={required.includes(propName)}
                />
              );
            case 'boolean':
              return (
                <FormPropTypeBoolean
                  id={prop.id}
                  example={prop.example as string}
                  isDisabled={disableInput}
                />
              );
            case 'listText':
            case 'listInteger':
              return (
                <FormPropTypeEnum
                  type={prop.type as 'string' | 'number' | 'integer'}
                  id={prop.id}
                  required={required.includes(propName)}
                  enum={prop.enum || []}
                  example={prop.example as string}
                  isDisabled={disableInput}
                />
              );
          }
        })()}
      </Flex>
    );
  };

  return (
    <>
      {/* If a component is exposed, show a callout to inform the user that props and slots are locked */}
      {componentStatus && (
        <Box flexGrow="1" pt="4" maxWidth="500px" mx="auto">
          <Callout.Root size="1" variant="surface">
            <Callout.Icon>
              <InfoCircledIcon />
            </Callout.Icon>
            <Callout.Text>
              This component has been added to <b>Components</b>.<br />
              Publishing changes to props and slots will create a new version.
            </Callout.Text>
          </Callout.Root>
        </Box>
      )}
      <SortableList
        items={props.filter((prop) => prop.derivedType !== null)}
        onAdd={handleAddProp}
        onReorder={handleReorder}
        onRemove={handleRemoveProp}
        renderContent={renderPropContent}
        getItemId={(item) => item.id}
        data-testid="prop"
        moveAriaLabel="Move prop"
        removeAriaLabel="Remove prop"
        isDisabled={componentStatus}
      />
    </>
  );
}
