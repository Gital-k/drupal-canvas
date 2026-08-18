import { fetchBaseQuery } from '@reduxjs/toolkit/query/react';

import { fetchCsrfToken } from '@/utils/csrf';
import { getCanvasSettings } from '@/utils/drupal-globals';

import type {
  BaseQueryApi,
  BaseQueryFn,
  FetchArgs,
  FetchBaseQueryError,
} from '@reduxjs/toolkit/query/react';
import type { RootState } from '@/app/store';
import type { AppConfiguration } from '@/features/configuration/configurationSlice';

export const baseQuery: BaseQueryFn<
  string | FetchArgs,
  unknown,
  FetchBaseQueryError
> = async (args, api, extraOptions) => {
  const state = api.getState() as RootState;
  return rawBaseQuery(state.configuration)(args, api, extraOptions);
};

export const pushCanvasLayoutRequest = () => {
  // Some requests that update components or their form should not occur at the
  // same time as a Drupal AJAX request. Those requests are identified here,
  // then a variable in canvasSettings is used to track such a request being in
  // progress. AJAX events will wait until these requests are complete.
  // @see the override of Drupal.Ajax.prototype.eventResponse in
  //   ajax.command.customizations.js
  const canvasSettings = getCanvasSettings();
  if (typeof canvasSettings?.canvasLayoutRequestInProgress === 'undefined') {
    canvasSettings.canvasLayoutRequestInProgress = [];
  }

  // Set body attribute to allow CSS to block use of elements that may cause
  // conflicts during layout / model / entity updating requests.
  document.body.setAttribute('data-canvas-layout-request-in-progress', 'true');
  canvasSettings.canvasLayoutRequestInProgress.push(true);
};

export const popCanvasLayoutRequest = () => {
  const canvasSettings = getCanvasSettings();
  canvasSettings.canvasLayoutRequestInProgress.pop();
  if (canvasSettings.canvasLayoutRequestInProgress.length === 0) {
    // Notify the application that the layout request has completed.
    const event = new CustomEvent('canvasLayoutRequestComplete');
    document.dispatchEvent(event);
    setTimeout(() => {
      if (canvasSettings.canvasLayoutRequestInProgress.length === 0) {
        document.body.removeAttribute('data-canvas-layout-request-in-progress');
      }
    }, 10);
  }
};

/**
 * Extracts entityType and entityId from a URL
 * @param url - The URL string to parse.
 * @returns An object with entityType and entityId, or undefined values if not found.
 */
export const extractEntityParams = (url: string) => {
  // Remove query parameters and hash fragments
  url = url.split('?')[0].split('#')[0];
  // The `/canvas` prefix is optional so this matches both a full
  // `window.location.href` (which carries the router basename, e.g.
  // `…/canvas/editor/…`) and a basename-stripped react-router `location.pathname`
  // (e.g. `/editor/…`).
  // Match /canvas/(editor||preview)/:entityType/:entityId/
  const matchPageEditor = url.match(
    /\/(?:canvas\/)?(editor|preview)\/([^/]+)\/([^/]+)\/?/,
  );
  // /template/:entityType/:bundle/:viewMode/:previewEntityId/
  // /canvas/api/v0/layout-content-template/{entity_type}.{template_bundle}.{template_view_mode}/{entity_id}
  const matchTemplateEditor = url.match(
    /\/(?:canvas\/)?template\/([^/]+)\/([^/]+)\/([^/]+)\/([^/]+)\/?/,
  );
  // /pattern/:patternId/
  // /canvas/api/v0/layout-pattern/{entity_id}
  const matchPatternEditor = url.match(/\/(?:canvas\/)?pattern\/([^/]+)\/?/);
  if (matchPageEditor) {
    return { entityType: matchPageEditor[2], entityId: matchPageEditor[3] };
  } else if (matchTemplateEditor) {
    return {
      entityType: matchTemplateEditor[1],
      templateBundle: matchTemplateEditor[2],
      templateViewMode: matchTemplateEditor[3],
      entityId: matchTemplateEditor[4],
    };
  } else if (matchPatternEditor) {
    return { entityType: 'pattern', entityId: matchPatternEditor[1] };
  }
  return { entityType: undefined, entityId: undefined };
};

export interface HostEntityParams {
  entityType?: string;
  entityBundle?: string;
}

/**
 * Derives the host entity currently being edited from the URL.
 *
 * Used to scope the component list to the host: the server omits block-sourced
 * Components whose required contexts cannot be satisfied for that host. Returns
 * the entity type and, when the route carries it, the bundle. Returns an empty
 * object when there is no content-entity host (the pattern editor, or a route
 * without an entity), so the caller sends no host parameters and receives only
 * the globally satisfiable Components.
 *
 * @param url - The URL string to parse (usually `window.location.href`).
 */
export const getHostEntityParams = (url: string): HostEntityParams => {
  const { entityType, templateBundle } = extractEntityParams(url);
  // `extractEntityParams` returns the sentinel 'pattern' type for the pattern
  // editor, which is not a content entity type and has no host.
  if (!entityType || entityType === 'pattern') {
    return {};
  }
  // @todo Send the host bundle for bundled content entities once the page editor supports them, in https://www.drupal.org/project/canvas/issues/3498525 — today the page editor is canvas_page-only (no bundle key), so a bundled host would send entity_type without entity_bundle and the server would return 400.
  return { entityType, entityBundle: templateBundle };
};

/**
 * Replaces {entity_type} and {entity_id} in a URL string with extracted values.
 * Throws an error if a required value is missing.
 */
export const replaceEntityParamsInUrl = (
  url: string,
  entityType?: string,
  entityId?: string,
  templateBundle?: string,
  templateViewMode?: string,
): string => {
  let newUrl = url;
  if (url.includes('{entity_type}')) {
    if (entityType !== undefined) {
      newUrl = newUrl.replace('{entity_type}', entityType);
    } else {
      throw new Error(
        `The URL "${url}" requires an Entity type, but it could not be extracted from the current location.`,
      );
    }
  }
  if (url.includes('{entity_id}')) {
    if (entityId !== undefined) {
      newUrl = newUrl.replace('{entity_id}', entityId);
    } else {
      throw new Error(
        `The URL "${url}" requires an Entity ID, but it could not be extracted from the current location.`,
      );
    }
  }
  if (url.includes('{template_bundle}')) {
    if (templateBundle !== undefined) {
      newUrl = newUrl.replace('{template_bundle}', templateBundle);
    } else {
      throw new Error(
        `The URL "${url}" requires a Template Bundle, but it could not be extracted from the current location.`,
      );
    }
  }
  if (url.includes('{template_view_mode}')) {
    if (templateViewMode !== undefined) {
      newUrl = newUrl.replace('{template_view_mode}', templateViewMode);
    } else {
      throw new Error(
        `The URL "${url}" requires a Template View Mode, but it could not be extracted from the current location.`,
      );
    }
  }
  return newUrl;
};

const rawBaseQuery = (appConfiguration: AppConfiguration) => {
  const { baseUrl } = appConfiguration;
  const defaultQuery = fetchBaseQuery({
    baseUrl,
    prepareHeaders: async (headers, api) => {
      if (api.type === 'mutation') {
        try {
          headers.set('X-CSRF-Token', await fetchCsrfToken(baseUrl));
        } catch (error) {
          console.error((error as Error).message);
        }
      }
    },
  });
  return async (
    arg: string | FetchArgs,
    api: BaseQueryApi,
    extraOptions: object = {},
  ) => {
    const url = typeof arg == 'string' ? arg : arg.url;
    const { entityType, entityId, templateBundle, templateViewMode } =
      extractEntityParams(window.location.href);
    const newUrl = replaceEntityParamsInUrl(
      url,
      entityType,
      entityId,
      templateBundle,
      templateViewMode,
    );
    const newArg = typeof arg == 'string' ? newUrl : { ...arg, url: newUrl };
    return defaultQuery(newArg, api, extraOptions);
  };
};

// Higher-order base query to inject the current autoSavesHash and clientInstanceId for all mutations (POST, PATCH, DELETE)
// to allow the backend to recognize potential conflicts where the client is updating potentially out of date data.
export const withAutoSavesInjection: (
  baseQuery: BaseQueryFn<string | FetchArgs, unknown, FetchBaseQueryError>,
) => BaseQueryFn<string | FetchArgs, unknown, FetchBaseQueryError> = (
  baseQuery,
) => {
  return (args, api, extraOptions) => {
    if (typeof args === 'object') {
      const { url } = args;
      if (
        url &&
        api.type === 'mutation' &&
        // Skip autoSaves injection for mutations that do not impact data that
        // is autosaved, such as creating folders.
        ![
          'createFolder',
          'createContentTemplate',
          'updateFolder',
          'uploadFont',
          'createColor',
          'updateColor',
          'deleteColor',
        ].includes(api.endpoint)
      ) {
        const state = api.getState() as RootState;
        const { publishReview } = state;
        const { entityType, entityId, templateBundle, templateViewMode } =
          extractEntityParams(window.location.href);
        const autoSaveKey = replaceEntityParamsInUrl(
          url,
          entityType,
          entityId,
          templateBundle,
          templateViewMode,
        );
        // We want to send back the specific autoSave hash for the particular URL being updated
        const autoSaves =
          autoSaveKey && publishReview.autoSavesHash[autoSaveKey]
            ? publishReview.autoSavesHash[autoSaveKey]
            : undefined;
        return baseQuery(
          {
            ...args,
            body: {
              ...args.body,
              ...(autoSaves && { autoSaves }),
              clientInstanceId: publishReview.clientInstanceId,
            },
          },
          api,
          extraOptions,
        );
      }
    }
    return baseQuery(args, api, extraOptions);
  };
};

// Export a baseQuery with autoSaves injection by default
export const baseQueryWithAutoSaves: BaseQueryFn<
  string | FetchArgs,
  unknown,
  FetchBaseQueryError
> = withAutoSavesInjection(baseQuery);
