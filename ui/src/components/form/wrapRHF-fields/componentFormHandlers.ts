import {
  ComponentPreviewUpdateEvent,
  getPropsValues,
  POLLED_BACKGROUND_TIMEOUT,
  syncPropSourcesToResolvedValues,
} from '@/components/form/formUtil';
import { isEvaluatedComponentModel } from '@/features/layout/layoutModelSlice';
import { setPreviewBackgroundUpdate } from '@/features/pagePreview/previewSlice';
import { flaggedForRemoval } from '@/utils/function-utils';

import type { PropsValues } from '@drupal-canvas/types';
import type { Dispatch } from '@reduxjs/toolkit';
import type { InputUIData } from '@/types/Form';

/**
 * Dependencies needed for component form state handling
 */
interface ComponentFormHandlerDependencies {
  dispatch: Dispatch;
  transforms: any;
  selectedComponentType: string;
  selectedComponent: string;
  propName: string;
  isScalarProp: boolean;
  polledBackgroundUpdate: React.MutableRefObject<number | null>;
  component: any;
  patchComponent: (params: any) => void;
  editorFrameContext: string;
  version: string;
}

/**
 * Creates a form state handler for component instance forms.
 * Handles complex component model transformations, prop validation,
 * preview updates, and backend synchronization.
 */
export const createComponentFormStateHandler = (
  deps: ComponentFormHandlerDependencies,
) => {
  return (
    newFormState: PropsValues,
    newInputAndUiData: InputUIData,
    currFormState: PropsValues,
  ) => {
    // Apply (client-side) transforms for form state.
    const { propsValues: values, selectedModel } = getPropsValues(
      { ...currFormState, ...newFormState },
      newInputAndUiData,
      // If transforms are not available (typically because TransformsContext is
      // not available to the input), fall back to the transforms stored in the
      // global window object.
      Object.keys(deps.transforms || {}).length > 0
        ? deps.transforms
        : (window as any)._canvasTransforms[deps.selectedComponentType],
    );

    // And then send data to backend - this will:
    // a) Trigger server-side validation/transformation (massaging of widget values)
    // b) Update both the preview and the model - see the pessimistic update
    //    in onQueryStarted in preview.ts
    // @see \Drupal\Core\Field\WidgetInterface::massageFormValues()
    const resolved = { ...selectedModel.resolved, ...values };

    // Check the object for any values that are flagged for removal. Note that
    // removal flagging is not necessary for all prop types. It is used for
    // props with complex prop shapes where the empty-indicating value is nested
    // with the structure.
    Object.keys(values).forEach((prop) => {
      if (
        flaggedForRemoval(values[prop]) &&
        deps.component?.propSources?.[prop]
      ) {
        // If the prop is optional, it can be removed.
        if (!deps.component.propSources[prop]?.required) {
          if (isEvaluatedComponentModel(selectedModel)) {
            // The source value can also be updated to empty when permitted.
            if (!Object.isFrozen(selectedModel.source[prop])) {
              selectedModel.source[prop].value = [];
            }
          }
          resolved[prop] = [];
        } else {
          // If the prop is required, we need to set it back to the default.
          resolved[prop] =
            deps.component.propSources[prop].default_values.resolved;
        }
      }
    });

    let backgroundPreviewUpdate = false;
    if (deps.isScalarProp) {
      // Fire an event to allow listeners to attempt real-time updates.
      const PreviewUpdateEvent = new ComponentPreviewUpdateEvent(
        deps.selectedComponent,
        deps.propName,
        resolved[deps.propName],
      );
      document.dispatchEvent(PreviewUpdateEvent);
      deps.dispatch(
        // Flag if any listeners were able to perform a real-time update.
        setPreviewBackgroundUpdate(
          PreviewUpdateEvent.getPreviewBackgroundUpdate(),
        ),
      );
      backgroundPreviewUpdate = PreviewUpdateEvent.getPreviewBackgroundUpdate();
    }

    if (isEvaluatedComponentModel(selectedModel) && deps.component) {
      const updateBackend = () => {
        deps.patchComponent({
          type: deps.editorFrameContext,
          componentInstanceUuid: deps.selectedComponent,
          componentType: `${deps.selectedComponentType}@${deps.version}`,
          model: {
            source: syncPropSourcesToResolvedValues(
              selectedModel.source,
              deps.component,
              resolved,
            ),
            resolved,
          },
        });
      };
      if (backgroundPreviewUpdate) {
        if (deps.polledBackgroundUpdate.current !== null) {
          clearTimeout(deps.polledBackgroundUpdate.current);
        }
        // If we're doing a background update, debounce that so we don't make
        // multiple requests for a single update. If we're not doing a
        // background preview update, we should schedule this immediately -
        // debouncing in InputBehaviors will handle preventing this firing too
        // many times in succession.
        deps.polledBackgroundUpdate.current = setTimeout(
          updateBackend,
          POLLED_BACKGROUND_TIMEOUT,
        ) as any as number;
        return;
      }
      updateBackend();
      return;
    }
    deps.patchComponent({
      type: deps.editorFrameContext,
      componentInstanceUuid: deps.selectedComponent,
      componentType: `${deps.selectedComponentType}@${deps.version}`,
      model: {
        ...selectedModel,
        resolved,
      },
    });
  };
};
