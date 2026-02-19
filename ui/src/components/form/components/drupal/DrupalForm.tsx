import { useEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import { createPortal } from 'react-dom';

import Form from '@/components/form/components/Form';
import { FormProvider } from '@/components/form/contexts/FormContext';
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
  targetElement: HTMLElement;
  fieldIdentifier?: string;
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

            // Attach Drupal behaviors to the target element
            if (window.Drupal?.attachBehaviors) {
              window.Drupal.attachBehaviors(
                ajaxContent.targetElement,
                ajaxContent.settings as { doNotReinvoke?: boolean },
              );
            }
          }
        });
      });
    }
  }, [ajaxContents, formId]);

  // Listen for AJAX content being added to this form.
  useEffect(() => {
    const formElement = formRef.current;
    if (!formElement) return;

    // When AJAX adds elements to the form, they are automatically in the
    // expected location within the DOM. They are not, however
    // descendants of this form within the React component tree. This process
    // identifies the newly added content and creates a React portal to
    // ensure it descends from this form, thus giving it access to the
    // FormProvider context.
    const handleAjaxContent = (event: Event) => {
      const customEvent = event as CustomEvent<{
        container: HTMLElement;
        application: any;
        context: HTMLElement;
        settings: { doNotReinvoke?: boolean };
      }>;
      const { container, application, context, settings } = customEvent.detail;

      // The context parent is the AJAX wrapper.
      const targetElement = context.parentElement || container;

      // Extract the stable part of the ID (before Drupal's hash suffix)
      // e.g., "field-name--Q1pTo5xBlOo" -> "field-name"
      const fieldIdentifier = targetElement.id
        ? targetElement.id.split('--')[0]
        : undefined;
      setAjaxContents((prev) => {
        // Remove previous content for the same wrapper.
        let filtered = prev;
        if (fieldIdentifier) {
          filtered = prev.filter((content) => {
            return content.fieldIdentifier !== fieldIdentifier;
          });
        }

        const newContent = {
          container,
          application,
          context,
          settings,
          id: `ajax-${Date.now()}-${Math.random()}`,
          targetElement,
          fieldIdentifier,
        };

        return [...filtered, newContent];
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
