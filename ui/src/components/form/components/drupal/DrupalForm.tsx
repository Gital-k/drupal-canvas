import { useEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import { createPortal } from 'react-dom';

import Form from '@/components/form/components/Form';
import { FormProvider } from '@/contexts/FormContext';
import { a2p } from '@/local_packages/utils.js';

import type { ReactNode } from 'react';
import type { FormId } from '@/features/form/formStateSlice';
import type { Attributes } from '@/types/DrupalAttribute';

interface AjaxContent {
  container: HTMLElement;
  application: any;
  context: HTMLElement;
  settings: { doNotReinvoke?: boolean };
  id: string;
  wrapperId?: string;
  fieldName?: string;
}

const DrupalForm = ({
  attributes = {},
  renderChildren = null,
}: {
  attributes: Attributes;
  renderChildren: ReactNode;
}) => {
  const formId = attributes['data-form-id'] as FormId;
  const formRef = useRef<HTMLFormElement>(null);
  const [ajaxContents, setAjaxContents] = useState<AjaxContent[]>([]);
  const processedIdsRef = useRef<Set<string>>(new Set());

  useEffect(() => {
    // After ajaxContents updates and portals are created, clean up context elements
    if (ajaxContents.length > 0) {
      // Use requestAnimationFrame to ensure React has completed the render
      requestAnimationFrame(() => {
        ajaxContents.forEach((ajaxContent) => {
          // Only process each piece of content once
          if (!processedIdsRef.current.has(ajaxContent.id)) {
            processedIdsRef.current.add(ajaxContent.id);
            ajaxContent.context.remove();

            // Find the wrapper element that contains the AJAX button
            // The wrapper might be the container itself, inside it, or the parent
            let wrapper = ajaxContent.container.querySelector(
              '[id*="-add-more-wrapper"]',
            ) as HTMLElement;

            // If not found inside, check if container itself is the wrapper
            if (
              !wrapper &&
              ajaxContent.container.id?.includes('-add-more-wrapper')
            ) {
              wrapper = ajaxContent.container;
            }

            // If still not found, check parent
            if (!wrapper) {
              wrapper = ajaxContent.container.closest(
                '[id*="-add-more-wrapper"]',
              ) as HTMLElement;
            }

            const targetElement = wrapper || ajaxContent.container;

            // Attach Drupal behaviors to the wrapper (or container as fallback)
            if (window.Drupal?.attachBehaviors) {
              window.Drupal.attachBehaviors(
                targetElement,
                ajaxContent.settings as { doNotReinvoke?: boolean },
              );
            }
          }
        });
      });
    }
  }, [ajaxContents, formId]);

  // Listen for AJAX content being added to this form
  useEffect(() => {
    const formElement = formRef.current;
    if (!formElement) return;

    const handleAjaxContent = (event: Event) => {
      const customEvent = event as CustomEvent<{
        container: HTMLElement;
        application: any;
        context: HTMLElement;
        settings: { doNotReinvoke?: boolean };
      }>;
      const { container, application, context, settings } = customEvent.detail;

      // Find the closest wrapper div (has id ending with -add-more-wrapper)
      // This wrapper gets completely replaced by Drupal's InsertCommand
      const wrapper = context.closest('[id*="-add-more-wrapper"]');
      const wrapperId = wrapper?.id;

      // Extract field name from wrapper ID (before the unique suffix)
      // e.g., "field-cvt-unlimited-text-add-more-wrapper--5TDIhF-vAfQ" -> "field-cvt-unlimited-text"
      const fieldNameFromWrapper = wrapperId?.match(
        /^(.*?)-add-more-wrapper/,
      )?.[1];

      setAjaxContents((prev) => {
        // Drupal regenerates wrapper IDs with unique suffixes on each rebuild,
        // so we need to match by field name (the stable part) instead of exact ID
        let filtered = prev;
        if (fieldNameFromWrapper) {
          filtered = prev.filter((content) => {
            const contentWrapper = content.container.closest(
              '[id*="-add-more-wrapper"]',
            );
            const contentFieldName = contentWrapper?.id.match(
              /^(.*?)-add-more-wrapper/,
            )?.[1];
            // Portal remains if true.
            return contentFieldName !== fieldNameFromWrapper;
          });
        }

        const newContent = {
          container,
          application,
          context,
          settings,
          id: `ajax-${Date.now()}-${Math.random()}`,
          wrapperId,
          fieldName: fieldNameFromWrapper,
        };

        const result = [...filtered, newContent];
        return result;
      });
    };

    formElement.addEventListener(
      'canvas:renderAjaxContent',
      handleAjaxContent as EventListener,
    );

    return () => {
      formElement.removeEventListener(
        'canvas:renderAjaxContent',
        handleAjaxContent as EventListener,
      );
    };
  }, []);

  const formContent = (
    <>
      {renderChildren}
      {/* Render AJAX content using portals so it appears in the correct DOM
      location and has access to the FormProvider context */}
      {ajaxContents.map((ajaxContent) => {
        return createPortal(
          ajaxContent.application,
          ajaxContent.container,
          ajaxContent.id,
        );
      })}
    </>
  );

  // If no formId, render without FormProvider (e.g., external forms)
  if (!formId) {
    return (
      <Form
        ref={formRef}
        attributes={{ ...a2p(attributes, {}, { skipAttributes: ['class'] }) }}
        className={clsx(attributes.class)}
      >
        {formContent}
      </Form>
    );
  }

  return (
    <FormProvider formId={formId}>
      <Form
        ref={formRef}
        attributes={{ ...a2p(attributes, {}, { skipAttributes: ['class'] }) }}
        className={clsx(attributes.class)}
      >
        {formContent}
      </Form>
    </FormProvider>
  );
};

export default DrupalForm;
