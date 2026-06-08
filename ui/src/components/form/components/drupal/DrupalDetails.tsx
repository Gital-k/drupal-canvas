import clsx from 'clsx';
import { Box } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import { AccordionDetails } from '@/components/form/components/Accordion';
import Details from '@/components/form/components/Details';
import { FORM_TYPES } from '@/features/form/constants';
import { setGroupOpen } from '@/features/form/formStateSlice';
import { a2p } from '@/local_packages/utils.js';

import type { ReactNode } from 'react';
import type { Attributes } from '@/types/DrupalAttribute';

import descriptionStyles from './InputDescription.module.css';

const DrupalDetails = ({
  attributes = {},
  errors = null,
  title = '',
  summaryAttributes = {},
  description = null,
  children = null,
  value = null,
  required = false,
  element = {},
}: {
  attributes: Attributes;
  errors: ReactNode;
  title: string;
  summaryAttributes?: Attributes;
  description: ReactNode;
  children?: ReactNode;
  value: ReactNode;
  required: boolean;
  element: { [key: string]: any };
}) => {
  const dispatch = useAppDispatch();
  const groupId = attributes['data-canvas-prop-group'] as string | undefined;
  const openFromRedux = useAppSelector((state) =>
    groupId
      ? state.formState[FORM_TYPES.COMPONENT_INSTANCE_FORM]?.openGroups?.[
          groupId
        ]
      : undefined,
  );

  const descriptionClasses = clsx('description', descriptionStyles.description);
  if (element?.['#accordion_items']) {
    return (
      <AccordionDetails
        title={title}
        attributes={a2p(attributes, {}, { skipAttributes: ['class'] })}
        summaryAttributes={a2p(summaryAttributes, {
          class: clsx(required && ['js-form-required', 'form-required']),
        })}
      >
        {errors && <Box>{errors}</Box>}
        {description && <Box className={descriptionClasses}>{description}</Box>}
        <Box>{children}</Box>
        {value && <Box>{value}</Box>}
      </AccordionDetails>
    );
  } else {
    if (groupId) {
      const fallbackOpen = Boolean(attributes.open);
      const open = openFromRedux ?? fallbackOpen;
      return (
        <Details
          title={title}
          attributes={a2p(attributes, {}, { skipAttributes: ['class'] })}
          summaryAttributes={a2p(summaryAttributes)}
          open={open}
          onOpenChange={(nextOpen) => {
            dispatch(
              setGroupOpen({
                formId: FORM_TYPES.COMPONENT_INSTANCE_FORM,
                groupId,
                open: nextOpen,
              }),
            );
          }}
        >
          {children}
        </Details>
      );
    }
    return (
      <Details
        title={title}
        attributes={a2p(attributes, {}, { skipAttributes: ['class'] })}
        summaryAttributes={a2p(summaryAttributes)}
      >
        {children}
      </Details>
    );
  }
};

export default DrupalDetails;
