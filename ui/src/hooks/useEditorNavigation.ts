import { useCallback } from 'react';
import { useHref, useLocation, useNavigate } from 'react-router-dom';

import { DEFAULT_REGION } from '@/features/ui/uiSlice';
import { getCanvasSettings } from '@/utils/drupal-globals';
import {
  removeComponentFromPathname,
  setPreviewEntityIdInPathname,
  setRegionInPathname,
} from '@/utils/route-utils';

import type { NavigateOptions } from 'react-router-dom';
import type { TemplateViewMode } from '@/services/componentAndLayout';

const canvasSettings = getCanvasSettings();

/**
 * Minimal template view mode properties needed for navigation.
 * Extracts only the essential fields from TemplateViewMode required to construct a route.
 */
export type TemplateViewModeNavigation = Pick<
  TemplateViewMode,
  'entityType' | 'bundle' | 'viewMode'
> & {
  suggestedPreviewEntityId?: number | string;
  links?: TemplateViewMode['links'];
};

/**
 * Hook for editor navigation functions.
 *
 * Provides a unified API for navigating between different editor views:
 * - Entity editor (e.g., nodes, blocks)
 * - Template editor (for view modes)
 * - Code editor (for custom components)
 *
 * Also handles region selection within the current route and exposes
 * navigation utilities globally via `canvasSettings.navUtils`.
 */
export function useEditorNavigation() {
  const navigate = useNavigate();
  const location = useLocation();
  // useHref('/') returns the router basename with a trailing slash (e.g. "/canvas/").
  // Used to strip the basename from server-provided URLs so React Router receives
  // a path relative to its own root.
  const rootHref = useHref('/');

  /**
   * Updates the current route to select a specific region.
   * Removes any component selection from the path and sets the region segment.
   */
  const setSelectedRegion = useCallback(
    (regionId?: string) => {
      // Remove any /component/:componentId from the path first
      const basePath = removeComponentFromPathname(location.pathname);
      // Use the utility to robustly set /region/:regionId
      const newPath = setRegionInPathname(basePath, regionId, DEFAULT_REGION);
      navigate(newPath);
    },
    [navigate, location.pathname],
  );

  /**
   * Updates the preview entity ID in the current template editor route.
   * Preserves any existing component selection in the path.
   * Only works for template editor routes (/template/:entityType/:bundle/:viewMode).
   *
   * @param entityId - The entity ID to set as the preview entity (optional)
   * @throws {Error} If the current route is not a template editor route
   */
  const setTemplatePreviewEntityId = useCallback(
    (entityId?: string | number) => {
      const newPath = setPreviewEntityIdInPathname(location.pathname, entityId);
      navigate(newPath);
    },
    [navigate, location.pathname],
  );

  /**
   * Constructs a URL path for the entity editor.
   * @param entityType - The type of entity (e.g., 'node', 'block').
   * @param entityId - The ID of the entity to edit.
   * @returns The URL path string, or empty string if parameters are missing.
   */
  const urlForEditor = useCallback(
    (entityType?: string, entityId?: string | number) => {
      if (!entityType || !entityId) {
        console.warn(
          '[useEditorNavigation] urlForEditor called with undefined parameters:',
          { entityType, entityId },
        );
        return '';
      }
      return `/editor/${entityType}/${entityId}`;
    },
    [],
  );

  /**
   * Constructs a URL path for the template editor from the server-provided
   * edit-form link. The link is stripped of the router basename so React Router
   * can resolve it correctly.
   * @param viewMode - The template view mode, must include links['edit-form'].
   * @returns The URL path string, or empty string if the link is unavailable.
   */
  const urlForTemplateEditor = useCallback(
    (viewMode?: TemplateViewModeNavigation) => {
      if (!viewMode?.links?.['edit-form']) {
        console.warn(
          '[useEditorNavigation] urlForTemplateEditor: no `edit-form` link available for viewMode:',
          viewMode,
        );
        return '';
      }
      const templateUrl = viewMode.suggestedPreviewEntityId
        ? viewMode.links['edit-form'] + `/${viewMode.suggestedPreviewEntityId}`
        : viewMode.links['edit-form'];
      // The server URL includes the router basename (e.g. "/canvas/template/...").
      // Strip it so React Router resolves the path correctly relative to its root.
      const basename = rootHref.endsWith('/') ? rootHref.slice(0, -1) : rootHref;
      return templateUrl.startsWith(basename)
        ? templateUrl.slice(basename.length)
        : templateUrl;
    },
    [rootHref],
  );

  /**
   * Constructs a URL path for the code editor.
   * @param machineName - The machine name of the component to edit.
   * @returns The URL path string, or empty string if machineName is missing.
   */
  const urlForCodeEditor = useCallback((machineName?: string) => {
    if (!machineName) {
      console.warn(
        '[useEditorNavigation] urlForCodeEditor called with undefined machineName',
      );
      return '';
    }
    return `/code-editor/component/${machineName}`;
  }, []);

  /**
   * Navigates to the entity editor for a given entity.
   * @param entityType - The type of entity (e.g., 'node', 'canvas_page').
   * @param entityId - The ID of the entity to edit.
   * @param options - Optional React Router navigation options.
   */
  const navigateToEditor = useCallback(
    (
      entityType?: string,
      entityId?: string | number,
      options?: NavigateOptions,
    ) => {
      if (!entityType || !entityId) {
        console.warn(
          '[useEditorNavigation] navigateToEditor called with undefined parameters:',
          { entityType, entityId },
        );
        return;
      }
      navigate(urlForEditor(entityType, entityId), options);
    },
    [navigate, urlForEditor],
  );

  /**
   * Navigates to the template editor for a given view mode.
   * @param viewMode - The template view mode containing entityType, bundle, viewMode.
   * @param options - Optional React Router navigation options.
   */
  const navigateToTemplateEditor = useCallback(
    (viewMode?: TemplateViewModeNavigation, options?: NavigateOptions) => {
      if (!viewMode?.entityType || !viewMode?.bundle || !viewMode?.viewMode) {
        console.warn(
          '[useEditorNavigation] navigateToTemplateEditor called with undefined or incomplete viewMode:',
          viewMode,
        );
        return;
      }
      navigate(urlForTemplateEditor(viewMode), options);
    },
    [navigate, urlForTemplateEditor],
  );

  /**
   * Navigates to the code editor for a given component.
   * @param machineName - The machine name of the component to edit.
   * @param options - Optional React Router navigation options.
   */
  const navigateToCodeEditor = useCallback(
    (machineName?: string, options?: NavigateOptions) => {
      if (!machineName) {
        console.warn(
          '[useEditorNavigation] navigateToCodeEditor called with undefined machineName',
        );
        return;
      }
      navigate(urlForCodeEditor(machineName), options);
    },
    [navigate, urlForCodeEditor],
  );

  /**
   * Collection of all navigation utilities provided by this hook.
   * Also exposed globally via canvasSettings.navUtils for external access.
   */
  const editorNavUtils = {
    setSelectedRegion,
    setTemplatePreviewEntityId,
    urlForEditor,
    urlForTemplateEditor,
    urlForCodeEditor,
    navigateToEditor,
    navigateToTemplateEditor,
    navigateToCodeEditor,
  };

  // Expose navigation utilities globally so external code can access them
  canvasSettings.navUtils = editorNavUtils;

  return editorNavUtils;
}

export default useEditorNavigation;
