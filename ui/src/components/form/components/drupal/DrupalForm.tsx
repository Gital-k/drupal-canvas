import clsx from 'clsx';

import Form from '@/components/form/components/Form';
import { FormProvider } from '@/contexts/FormContext';
import { a2p } from '@/local_packages/utils.js';

import type { ReactNode } from 'react';
import type { FormId } from '@/features/form/formStateSlice';
import type { Attributes } from '@/types/DrupalAttribute';

const DrupalForm = ({
  attributes = {},
  renderChildren = null,
}: {
  attributes: Attributes;
  renderChildren: ReactNode;
}) => {
  const formId = attributes['data-form-id'] as FormId;

  // If no formId, render without FormProvider (e.g., external forms)
  if (!formId) {
    return (
      <Form
        attributes={{ ...a2p(attributes, {}, { skipAttributes: ['class'] }) }}
        className={clsx(attributes.class)}
      >
        {renderChildren}
      </Form>
    );
  }

  return (
    <FormProvider formId={formId}>
      <Form
        attributes={{ ...a2p(attributes, {}, { skipAttributes: ['class'] }) }}
        className={clsx(attributes.class)}
      >
        {renderChildren}
      </Form>
    </FormProvider>
  );
};

export default DrupalForm;
