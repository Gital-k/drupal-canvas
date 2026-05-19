import { Flex, Switch } from '@radix-ui/themes';

import { useAppDispatch } from '@/app/hooks';
import {
  Divider,
  FormElement,
  Label,
} from '@/features/code-editor/component-data/FormElement';
import { dispatchUpdateProp } from '@/features/code-editor/utils/arrayPropUtils';

import type { CodeComponentProp } from '@/types/CodeComponent';

export default function FormPropTypeBoolean({
  id,
  example,
  isDisabled = false,
}: Pick<CodeComponentProp, 'id' | 'example'> & {
  isDisabled?: boolean;
}) {
  const dispatch = useAppDispatch();

  return (
    <Flex direction="column" gap="4" flexGrow="1">
      <Divider />
      <FormElement>
        <Label htmlFor={`prop-example-${id}`}>Example value</Label>
        <Switch
          id={`prop-example-${id}`}
          checked={example === true}
          onCheckedChange={(checked) =>
            dispatchUpdateProp(dispatch, id, { example: checked })
          }
          size="1"
          disabled={isDisabled}
        />
      </FormElement>
    </Flex>
  );
}
