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

  // Log when AJAX contents change and handle cleanup
  useEffect(() => {
    console.log('DrupalForm: ajaxContents updated', {
      count: ajaxContents.length,
      formId,
    });

    // After ajaxContents updates and portals are created, clean up context elements
    if (ajaxContents.length > 0) {
      // Use requestAnimationFrame to ensure React has completed the render
      requestAnimationFrame(() => {
        ajaxContents.forEach((ajaxContent) => {
          // Only process each piece of content once
          if (!processedIdsRef.current.has(ajaxContent.id)) {
            console.log('DrupalForm: Cleaning up context for', ajaxContent.id);
            processedIdsRef.current.add(ajaxContent.id);
            ajaxContent.context.remove();

            // Find the wrapper element that contains the AJAX button
            // The wrapper might be the container itself, inside it, or the parent
            let wrapper = ajaxContent.container.querySelector(
              '[id*="-add-more-wrapper"]',
            ) as HTMLElement;

            // If not found inside, check if container itself is the wrapper
            if (!wrapper && ajaxContent.container.id?.includes('-add-more-wrapper')) {
              wrapper = ajaxContent.container;
            }

            // If still not found, check parent
            if (!wrapper) {
              wrapper = ajaxContent.container.closest('[id*="-add-more-wrapper"]') as HTMLElement;
            }

            const targetElement = wrapper || ajaxContent.container;

            console.log('DrupalForm: Attaching behaviors to', {
              containerElement: ajaxContent.container,
              containerTagName: ajaxContent.container.tagName,
              containerId: ajaxContent.container.id,
              wrapperElement: wrapper,
              wrapperId: wrapper?.id,
              targetElement,
              targetId: targetElement?.id,
            });

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

      console.log('DrupalForm: Received AJAX content event', {
        container,
        application,
        formId,
        containerParent: container.parentElement,
        contextHTML: context.outerHTML.substring(0, 200),
      });

      // Find the closest wrapper div (has id ending with -add-more-wrapper)
      // This wrapper gets completely replaced by Drupal's InsertCommand
      const wrapper = context.closest('[id*="-add-more-wrapper"]');
      const wrapperId = wrapper?.id;

      // Extract field name from wrapper ID (before the unique suffix)
      // e.g., "field-cvt-unlimited-text-add-more-wrapper--5TDIhF-vAfQ" -> "field-cvt-unlimited-text"
      const fieldNameFromWrapper = wrapperId?.match(/^(.*?)-add-more-wrapper/)?.[1];

      console.log('DrupalForm: Wrapper info', {
        wrapperId,
        fieldNameFromWrapper,
        wrapper,
        contextId: context.id,
      });

      setAjaxContents((prev) => {
        // Drupal regenerates wrapper IDs with unique suffixes on each rebuild,
        // so we need to match by field name (the stable part) instead of exact ID
        let filtered = prev;
        if (fieldNameFromWrapper) {
          // Remove all previous portals for this field, since the wrapper is being replaced
          // console.log('DrupalForm: Clearing previous portals for field:', fieldNameFromWrapper);
          // console.log('DrupalForm: Previous portals:', prev.map(p => ({
          //   id: p.id,
          //   fieldName: p.fieldName,
          //   wrapperId: p.wrapperId,
          // })));

          filtered = prev.filter((content) => {
            const contentWrapper = content.container.closest(
              '[id*="-add-more-wrapper"]',
            );
            const contentFieldName = contentWrapper?.id.match(/^(.*?)-add-more-wrapper/)?.[1];
            const keep = contentFieldName !== fieldNameFromWrapper;
            if (!keep) {
              // console.log('DrupalForm: Removing stale portal', {
              //   portalId: content.id,
              //   oldWrapperId: content.wrapperId,
              //   oldFieldName: content.fieldName,
              //   contentFieldName,
              // });
            }
            return keep;
          });

          // console.log('DrupalForm: After filtering:', filtered.map(p => ({
          //   id: p.id,
          //   fieldName: p.fieldName,
          // })));
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

        // console.log('DrupalForm: Updating ajaxContents', {
        //   previousCount: prev.length,
        //   filteredCount: filtered.length,
        //   newWrapperId: wrapperId,
        //   fieldName: fieldNameFromWrapper,
        //   newContentId: newContent.id,
        // });

        const result = [...filtered, newContent];
        console.log('DrupalForm: Final ajaxContents count:', result.length);
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
      {/* Render AJAX content using portals so it appears in the correct DOM location
          but maintains access to FormProvider context */}
      {ajaxContents.map((ajaxContent) => {
        const containerInDOM = document.body.contains(ajaxContent.container);
        const containerHasContent = ajaxContent.container.children.length > 0;
        // console.log('DrupalForm: Creating portal', {
        //   id: ajaxContent.id,
        //   container: ajaxContent.container,
        //   containerId: ajaxContent.container.id,
        //   containerInDOM,
        //   containerHasContent,
        //   containerChildCount: ajaxContent.container.children.length,
        //   application: ajaxContent.application,
        // });

        if (!containerInDOM) {
          console.warn('DrupalForm: Container not in DOM!', {
            id: ajaxContent.id,
            container: ajaxContent.container,
          });
        }

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
