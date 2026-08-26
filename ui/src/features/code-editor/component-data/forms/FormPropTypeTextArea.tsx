import clsx from 'clsx';
import { Flex, Text, TextArea } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import { updateProp } from '@/features/code-editor/codeEditorSlice';
import {
  Divider,
  FormElement,
  Label,
} from '@/features/code-editor/component-data/FormElement';
import {
  DEFAULT_EXAMPLES,
  REQUIRED_EXAMPLE_ERROR_MESSAGE,
} from '@/features/code-editor/component-data/Props';
import { useRequiredProp } from '@/features/code-editor/hooks/useRequiredProp';

import type { CodeComponentProp } from '@/types/CodeComponent';

import styles from '@/features/code-editor/component-data/FormElement.module.css';

/**
 * Textarea widget shared by the prop types whose value spans multiple lines.
 *
 * Used by both the `formattedText` and `multilineText` prop types, which differ
 * only in the schema they serialize to and in the example value prefilled when
 * the prop is required. That is what `derivedType` keys into DEFAULT_EXAMPLES for.
 */
export default function FormPropTypeTextArea({
  id,
  example,
  isDisabled = false,
  required,
  derivedType,
}: Pick<CodeComponentProp, 'id' | 'example'> & {
  isDisabled?: boolean;
  required: boolean;
  derivedType: 'formattedText' | 'multilineText';
}) {
  const dispatch = useAppDispatch();
  const { showRequiredError, setShowRequiredError } = useRequiredProp(
    required,
    example as string,
    () => {
      dispatch(
        updateProp({
          id,
          updates: { example: DEFAULT_EXAMPLES[derivedType] },
        }),
      );
    },
    [dispatch, id, derivedType],
  );

  return (
    <Flex direction="column" gap="4" flexGrow="1">
      <Divider />
      <FormElement>
        <Label htmlFor={`prop-example-${id}`}>Example value</Label>
        <TextArea
          id={`prop-example-${id}`}
          placeholder="Enter a text value"
          value={example as string}
          size="1"
          onChange={(e) => {
            dispatch(
              updateProp({
                id,
                updates: {
                  example: e.target.value,
                },
              }),
            );
            // Show/hide error based on whether field is empty while required
            setShowRequiredError(required && !e.target.value);
          }}
          disabled={isDisabled}
          className={clsx({
            [styles.error]: showRequiredError,
          })}
          {...(showRequiredError ? { 'data-invalid-prop-value': true } : {})}
        />
        {showRequiredError && (
          <Text color="red" size="1">
            {REQUIRED_EXAMPLE_ERROR_MESSAGE}
          </Text>
        )}
      </FormElement>
    </Flex>
  );
}
