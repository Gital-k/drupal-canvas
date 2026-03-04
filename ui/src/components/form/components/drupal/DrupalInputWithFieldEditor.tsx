import DrupalInput from '@/components/form/components/drupal/DrupalInput';
import { FieldItemEditor } from '@/components/form/field-item-editor';
import {
  getDeltaFromAttributes,
  getFieldCardinality,
  getFieldNameFromAttributes,
  isTextField,
} from '@/components/form/field-item-editor/fieldItemUtils';
import { shouldUseFieldItemEditor } from '@/components/form/field-item-editor/utils/featureFlags';

import type { Attributes } from '@/types/DrupalAttribute';

const DrupalInputWithFieldEditor = ({
  attributes = {},
  ...props
}: {
  attributes?: Attributes;
  [key: string]: any;
}) => {
  if (!shouldUseFieldItemEditor() || !isTextField(attributes)) {
    return <DrupalInput attributes={attributes} {...props} />;
  }

  const delta = getDeltaFromAttributes(attributes);
  const cardinality = getFieldCardinality(attributes);
  const fieldName = getFieldNameFromAttributes(attributes, '');

  if (!fieldName) {
    return <DrupalInput attributes={attributes} {...props} />;
  }

  if (delta === 0) {
    return (
      <>
        <FieldItemEditor
          fieldName={fieldName}
          fieldLabel={
            typeof attributes['data-field-label'] === 'string'
              ? attributes['data-field-label']
              : undefined
          }
          cardinality={cardinality}
          isRequired={!!attributes.required}
        />
        <div style={{ display: 'none' }}>
          <DrupalInput attributes={attributes} {...props} />
        </div>
      </>
    );
  }

  return <DrupalInput attributes={attributes} {...props} />;
};

export default DrupalInputWithFieldEditor;
