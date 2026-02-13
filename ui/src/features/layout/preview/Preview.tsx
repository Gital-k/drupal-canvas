import { useEffect, useRef } from 'react';
import { useErrorBoundary } from 'react-error-boundary';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectLayout,
  selectModel,
  selectUpdatePreview,
} from '@/features/layout/layoutModelSlice';
import ComponentHtmlMapProvider from '@/features/layout/preview/DataToHtmlMapContext';
import Viewport from '@/features/layout/preview/Viewport';
import { selectPageData } from '@/features/pageData/pageDataSlice';
import {
  selectPreviewBackgroundUpdate,
  selectPreviewHtml,
} from '@/features/pagePreview/previewSlice';
import {
  selectEditorFrameContext,
  selectSelectedComponentUuid,
} from '@/features/ui/uiSlice';
import { useEntityTitle } from '@/hooks/useEntityTitle';
import { usePostTemplateLayoutMutation } from '@/services/componentAndLayout';
import { contentApi } from '@/services/content';
import {
  selectUpdateComponentLoadingState,
  useQueuedPostPreviewMutation,
} from '@/services/preview';
import { isAjaxing } from '@/utils/isAjaxing';

import type React from 'react';

interface PreviewProps {}

const Preview: React.FC<PreviewProps> = () => {
  const layout = useAppSelector(selectLayout);
  const updatePreview = useAppSelector(selectUpdatePreview);
  const model = useAppSelector(selectModel);
  const selectedComponent = useAppSelector(selectSelectedComponentUuid);
  const backgroundUpdate = useAppSelector(selectPreviewBackgroundUpdate);
  const selectedComponentId = selectedComponent || 'noop';
  const entity_form_fields = useAppSelector(selectPageData);
  const title = useEntityTitle();
  const previousEntityFormTitle = useRef(title);
  // @todo stop hardcoding `path` after https://drupal.org/i/3503446.
  const previousEntityFormAlias = useRef(entity_form_fields['path[0][alias]']);
  const pendingPreviewRequest = useRef<boolean>(false);
  const pendingTemplatePreviewRequest = useRef<boolean>(false);
  const shouldInvalidatePageList = useRef<boolean>(false);
  const priorPayloadRef = useRef('');

  // Track the actual values that should trigger preview updates
  const previousLayoutRef = useRef(layout);
  const previousModelRef = useRef(model);
  const previousEntityFormFieldsRef = useRef(entity_form_fields);

  const [postPreview, { isLoading: isFetching }] = useQueuedPostPreviewMutation(
    {
      fixedCacheKey: 'editorFramePreview',
    },
  );
  const [postTemplatePreview, { isLoading: isTemplateFetching }] =
    usePostTemplateLayoutMutation({
      fixedCacheKey: 'editorFrameTemplatePreview',
    });
  const isPatching = useAppSelector((state) =>
    selectUpdateComponentLoadingState(state, selectedComponentId),
  );
  const dispatch = useAppDispatch();
  const frameSrcDoc = useAppSelector(selectPreviewHtml);
  const { showBoundary } = useErrorBoundary();
  const editorFrameContext = useAppSelector(selectEditorFrameContext);

  useEffect(() => {
    // Check if the data that actually matters for preview has changed
    const layoutChanged = previousLayoutRef.current !== layout;
    const modelChanged = previousModelRef.current !== model;
    const entityFormFieldsChanged =
      previousEntityFormFieldsRef.current !== entity_form_fields;

    const dataChanged =
      layoutChanged || modelChanged || entityFormFieldsChanged;

    // console.log('[Preview useEffect] Triggered', {
    //   updatePreview,
    //   dataChanged,
    //   layoutChanged,
    //   modelChanged,
    //   entityFormFieldsChanged,
    // });

    // Only proceed if updatePreview is true AND the actual data has changed
    if (!updatePreview || !dataChanged) {
      // console.log(
      //   '[Preview useEffect] Skipping - updatePreview:',
      //   updatePreview,
      //   'dataChanged:',
      //   dataChanged,
      // );
      return;
    }

    // Update refs to track new values
    previousLayoutRef.current = layout;
    previousModelRef.current = model;
    previousEntityFormFieldsRef.current = entity_form_fields;
    const sendPreviewRequest = async (
      currentLayout: typeof layout,
      currentModel: typeof model,
      currentEntityFormFields: typeof entity_form_fields,
    ) => {
      try {
        // Trigger the mutation with current values at execution time
        await postPreview({
          layout: currentLayout,
          model: currentModel,
          entity_form_fields: currentEntityFormFields,
        });
      } catch (err) {
        showBoundary(err);
      }
    };
    const sendTemplatePreviewRequest = async (
      currentLayout: typeof layout,
      currentModel: typeof model,
      currentEntityFormFields: typeof entity_form_fields,
    ) => {
      const payload = {
        layout: currentLayout,
        model: currentModel,
        entity_form_fields: currentEntityFormFields,
      };
      const stringPayload: string = JSON.stringify(payload);
      try {
        if (priorPayloadRef.current === stringPayload) {
          // console.log('Skipping template preview request, payload unchanged.');
          //Skip sending the request if the payload hasn't changed.
          return;
        }
        // Trigger the mutation with current values at execution time
        await postTemplatePreview(payload).unwrap();
        priorPayloadRef.current = stringPayload;
      } catch (err) {
        showBoundary(err);
      }
    };

    const executePendingPreviewRequest = () => {
      if (pendingPreviewRequest.current) {
        pendingPreviewRequest.current = false;
        sendPreviewRequest(layout, model, entity_form_fields).then(() => {
          // @todo stop hardcoding `path` after https://drupal.org/i/3503446.
          if (shouldInvalidatePageList.current) {
            shouldInvalidatePageList.current = false;
            dispatch(
              contentApi.util.invalidateTags([{ type: 'Content', id: 'LIST' }]),
            );
          }
        });
      }
    };

    const executePendingTemplatePreviewRequest = () => {
      if (pendingTemplatePreviewRequest.current) {
        pendingTemplatePreviewRequest.current = false;
        sendTemplatePreviewRequest(layout, model, entity_form_fields).then(
          () => {},
        );
      }
    };

    const schedulePreviewRequest = () => {
      if (isAjaxing()) {
        pendingPreviewRequest.current = true;

        // Set up an interval to check when AJAX is done
        const interval = setInterval(() => {
          if (!isAjaxing()) {
            clearInterval(interval);
            executePendingPreviewRequest();
          }
        }, 50);
      } else {
        sendPreviewRequest(layout, model, entity_form_fields).then(() => {
          // @todo stop hardcoding `path` after https://drupal.org/i/3503446.
          if (shouldInvalidatePageList.current) {
            shouldInvalidatePageList.current = false;
            dispatch(
              contentApi.util.invalidateTags([{ type: 'Content', id: 'LIST' }]),
            );
          }
        });
      }
    };

    const scheduleTemplatePreviewRequest = () => {
      if (isAjaxing()) {
        pendingTemplatePreviewRequest.current = true;

        // Set up an interval to check when AJAX is done
        const interval = setInterval(() => {
          if (!isAjaxing()) {
            clearInterval(interval);
            executePendingTemplatePreviewRequest();
          }
        }, 50);
      } else {
        sendTemplatePreviewRequest(layout, model, entity_form_fields).then(
          () => {},
        );
      }
    };

    // Specifically when updating the Title or Alias, the page list used in the navigator must be re-fetched so that
    // it can display those updated values.
    if (
      title !== previousEntityFormTitle.current ||
      // @todo stop hardcoding `path` after https://drupal.org/i/3503446.
      entity_form_fields['path[0][alias]'] !== previousEntityFormAlias.current
    ) {
      shouldInvalidatePageList.current = true;
      previousEntityFormTitle.current = title;
      // @todo stop hardcoding `path` after https://drupal.org/i/3503446.
      previousEntityFormAlias.current = entity_form_fields['path[0][alias]'];
    }

    // console.log(
    //   '[Preview useEffect] Scheduling preview request for context:',
    //   editorFrameContext,
    // );

    if (editorFrameContext === 'template') {
      scheduleTemplatePreviewRequest();
    } else if (editorFrameContext === 'entity') {
      schedulePreviewRequest();
    }
  }, [
    layout,
    model,
    postPreview,
    entity_form_fields,
    updatePreview,
    showBoundary,
    dispatch,
    title,
    postTemplatePreview,
    editorFrameContext,
  ]);

  return (
    <ComponentHtmlMapProvider>
      <Viewport
        frameSrcDoc={frameSrcDoc}
        isFetching={
          (isFetching || isPatching || isTemplateFetching) && !backgroundUpdate
        }
      />
    </ComponentHtmlMapProvider>
  );
};
export default Preview;
