import { fetchBaseQuery } from '@reduxjs/toolkit/query/react';

import { getCanvasSettings } from '@/utils/drupal-globals';

import type {
  BaseQueryApi,
  BaseQueryFn,
  FetchArgs,
  FetchBaseQueryError,
} from '@reduxjs/toolkit/query/react';
import type { RootState } from '@/app/store';
import type { AppConfiguration } from '@/features/configuration/configurationSlice';

// Global debouncing state for baseQuery
const requestDebounceMap = new Map<
  string,
  {
    timestamp: number;
    promise?: Promise<any>;
    result?: any;
  }
>();
const REQUEST_DEBOUNCE_TIME = 300; // ms

// Timing statistics for determining optimal debounce time
const requestTimingStats = new Map<
  string,
  {
    lastRequestTime: number;
    timeBetweenRequests: number[];
    duplicatesSaved: number;
  }
>();

/**
 * Analyzes request timing patterns and recommends optimal debounce time.
 * Call this in browser console: window.analyzeDebounceTimings()
 */
export const analyzeDebounceTimings = () => {
  console.group('📊 Request Debounce Timing Analysis');

  if (requestTimingStats.size === 0) {
    console.log('No request data collected yet.');
    console.groupEnd();
    return;
  }

  let totalDuplicatesSaved = 0;
  const allTimings: number[] = [];

  requestTimingStats.forEach((stats, key) => {
    const shortKey = key.substring(0, 40);
    totalDuplicatesSaved += stats.duplicatesSaved;
    allTimings.push(...stats.timeBetweenRequests);

    if (stats.timeBetweenRequests.length > 0) {
      const min = Math.min(...stats.timeBetweenRequests);
      const max = Math.max(...stats.timeBetweenRequests);
      const avg =
        stats.timeBetweenRequests.reduce((a, b) => a + b, 0) /
        stats.timeBetweenRequests.length;
      const median = stats.timeBetweenRequests.sort((a, b) => a - b)[
        Math.floor(stats.timeBetweenRequests.length / 2)
      ];

      console.group(`🔑 ${shortKey}...`);
      console.log(`Total requests: ${stats.timeBetweenRequests.length + 1}`);
      console.log(`Duplicates prevented: ${stats.duplicatesSaved}`);
      console.log('Request timing (ms):');
      console.log(`  Min: ${min.toFixed(0)}ms`);
      console.log(`  Max: ${max.toFixed(0)}ms`);
      console.log(`  Avg: ${avg.toFixed(0)}ms`);
      console.log(`  Median: ${median.toFixed(0)}ms`);
      console.log(
        `  All timings: [${stats.timeBetweenRequests.map((t) => t.toFixed(0)).join(', ')}]`,
      );
      console.groupEnd();
    }
  });

  if (allTimings.length > 0) {
    const sortedTimings = allTimings.sort((a, b) => a - b);
    const p50 = sortedTimings[Math.floor(sortedTimings.length * 0.5)];
    const p95 = sortedTimings[Math.floor(sortedTimings.length * 0.95)];
    const p99 = sortedTimings[Math.floor(sortedTimings.length * 0.99)];
    const min = Math.min(...allTimings);
    const max = Math.max(...allTimings);
    const avg = allTimings.reduce((a, b) => a + b, 0) / allTimings.length;

    console.group('📈 Overall Statistics');
    console.log(`Total duplicates prevented: ${totalDuplicatesSaved}`);
    console.log(`Current debounce time: ${REQUEST_DEBOUNCE_TIME}ms`);
    console.log('\nTime between consecutive identical requests:');
    console.log(`  Min: ${min.toFixed(0)}ms`);
    console.log(`  Avg: ${avg.toFixed(0)}ms`);
    console.log(`  P50 (median): ${p50.toFixed(0)}ms`);
    console.log(`  P95: ${p95.toFixed(0)}ms`);
    console.log(`  P99: ${p99.toFixed(0)}ms`);
    console.log(`  Max: ${max.toFixed(0)}ms`);
    console.groupEnd();

    console.group('💡 Recommendations');
    if (p95 < REQUEST_DEBOUNCE_TIME) {
      console.log(
        `✅ You could REDUCE debounce time to ${Math.ceil(p95 + 50)}ms`,
      );
      console.log(
        `   (Current: ${REQUEST_DEBOUNCE_TIME}ms, P95: ${p95.toFixed(0)}ms)`,
      );
    } else if (min < 50) {
      console.log(
        `⚠️  Some requests arrive very quickly (min: ${min.toFixed(0)}ms)`,
      );
      console.log(
        `   Current debounce time (${REQUEST_DEBOUNCE_TIME}ms) seems appropriate.`,
      );
    } else {
      console.log(
        `✅ Current debounce time (${REQUEST_DEBOUNCE_TIME}ms) looks good.`,
      );
    }

    if (totalDuplicatesSaved === 0) {
      console.log(
        `ℹ️  No duplicates detected - debounce time might be too short or no duplicates occurring.`,
      );
    } else {
      console.log(
        `✅ Prevented ${totalDuplicatesSaved} duplicate request(s) - debouncer is working!`,
      );
    }
    console.groupEnd();
  }

  console.groupEnd();

  return {
    totalDuplicatesSaved,
    currentDebounceTime: REQUEST_DEBOUNCE_TIME,
    allTimings,
    requestCount: requestTimingStats.size,
  };
};

// Make it available globally for console access
if (typeof window !== 'undefined') {
  (window as any).analyzeDebounceTimings = analyzeDebounceTimings;
}

/**
 * Creates a debounced baseQuery that prevents duplicate network requests
 * while preserving Redux actions
 */
const createDebouncedBaseQuery = (
  originalBaseQuery: BaseQueryFn<
    string | FetchArgs,
    unknown,
    FetchBaseQueryError
  >,
): BaseQueryFn<string | FetchArgs, unknown, FetchBaseQueryError> => {
  return async (args, api, extraOptions) => {
    // Extract URL for checking - handle both string and FetchArgs types
    const url = typeof args === 'string' ? args : args.url;
    const method = typeof args === 'string' ? 'GET' : args.method || 'GET';
    const body = typeof args === 'string' ? undefined : args.body;

    // Only debounce layout API calls that cause duplicate issues
    const isLayoutAPI =
      url.includes('canvas/api/v0/layout') ||
      url.includes('canvas/api/v0/layout-content-template');

    if (!isLayoutAPI) {
      // Not a layout API call, proceed normally
      return originalBaseQuery(args, api, extraOptions);
    }

    // Create a unique key for this request
    const requestKey = JSON.stringify({
      url,
      method,
      body,
    });
    // const shortKey = requestKey.substring(0, 128);
    const currentTime = Date.now();

    // Track timing between requests for statistics
    let timeSinceLastRequest: number | null = null;
    const statsEntry = requestTimingStats.get(requestKey);
    if (statsEntry) {
      timeSinceLastRequest = currentTime - statsEntry.lastRequestTime;
      statsEntry.timeBetweenRequests.push(timeSinceLastRequest);
      statsEntry.lastRequestTime = currentTime;
    } else {
      requestTimingStats.set(requestKey, {
        lastRequestTime: currentTime,
        timeBetweenRequests: [],
        duplicatesSaved: 0,
      });
    }

    // console.log(
    //   `[baseQuery-debounce] REQUEST ATTEMPT - Time ${new Date().getTime()} Key: ${shortKey}, Method: ${method}, URL: ${url}${timeSinceLastRequest !== null ? `, Time since last: ${timeSinceLastRequest}ms` : ''}`,
    // );

    // Check if we have a recent identical request
    const existingRequest = requestDebounceMap.get(requestKey);
    if (existingRequest) {
      const timeDiff = currentTime - existingRequest.timestamp;

      if (timeDiff < REQUEST_DEBOUNCE_TIME) {
        // Track that we saved a duplicate
        const stats = requestTimingStats.get(requestKey);
        if (stats) {
          stats.duplicatesSaved++;
        }

        // console.log(
        //   `[baseQuery-debounce] DUPLICATE DETECTED - Time ${new Date().getTime()} Key: ${shortKey}, TimeDiff: ${timeDiff}ms, DuplicatesSaved: ${stats?.duplicatesSaved || 0}`,
        // );

        // If there's an ongoing promise, wait for it
        if (existingRequest.promise) {
          // console.log(
          //   `[baseQuery-debounce] REUSING ONGOING REQUEST - Time ${new Date().getTime()} Key: ${shortKey}`,
          // );
          try {
            const result = await existingRequest.promise;
            return result;
          } catch (error) {
            // console.log(
            //   `[baseQuery-debounce] ONGOING REQUEST FAILED -Time ${new Date().getTime()}  Key: ${shortKey}, proceeding with new request`,
            // );
            // Fall through to make new request
          }
        } else if (existingRequest.result) {
          // We have a cached result, return it
          // console.log(
          //   `[baseQuery-debounce] RETURNING CACHED RESULT - Time ${new Date().getTime()} Key: ${shortKey}`,
          // );
          return existingRequest.result;
        }
      }
    }

    // console.log(
    //   `[baseQuery-debounce] PROCEEDING WITH NETWORK REQUEST - Time ${new Date().getTime()} Key: ${shortKey}`,
    // );

    // Make the actual request using the original baseQuery
    const requestPromise = originalBaseQuery(args, api, extraOptions);

    // Store the promise for reuse by concurrent requests
    requestDebounceMap.set(requestKey, {
      timestamp: currentTime,
      promise: requestPromise as Promise<any>,
    });

    // Handle completion
    try {
      const result = await requestPromise;
      const completionTime = Date.now();
      // console.log(
      //   `[baseQuery-debounce] REQUEST COMPLETED - Time ${new Date().getTime()} Key: ${shortKey}, took ${completionTime - currentTime}ms`,
      // );

      // Cache the result and mark as completed
      // This prevents duplicate requests for the full debounce window
      requestDebounceMap.set(requestKey, {
        timestamp: completionTime, // Use completion time for debounce window
        result,
      });

      // Clean up after debounce time from completion
      setTimeout(() => {
        const entry = requestDebounceMap.get(requestKey);
        // Only clean up if this is still our entry (timestamp matches completion time)
        if (entry && entry.timestamp === completionTime) {
          requestDebounceMap.delete(requestKey);
          // console.log(`[baseQuery-debounce] CLEANED UP - Key: ${shortKey}`);
        }
      }, REQUEST_DEBOUNCE_TIME);

      return result;
    } catch (error) {
      // console.log(
      //   `[baseQuery-debounce] REQUEST FAILED - Key: ${shortKey}`,
      //   error,
      // );
      // For failed requests, clean up immediately so it can be retried
      const entry = requestDebounceMap.get(requestKey);
      if (entry && entry.timestamp === currentTime) {
        requestDebounceMap.delete(requestKey);
      }
      throw error;
    }
  };
};

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
  canvasSettings.canvasLayoutRequestInProgress.push(true);
};

export const popCanvasLayoutRequest = () => {
  const canvasSettings = getCanvasSettings();
  canvasSettings.canvasLayoutRequestInProgress.pop();
  if (canvasSettings.canvasLayoutRequestInProgress.length === 0) {
    // Notify the application that the layout request has completed.
    const event = new CustomEvent('canvasLayoutRequestComplete');
    document.dispatchEvent(event);
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
  // Match /canvas/(editor||preview)/:entityType/:entityId/
  const matchPageEditor = url.match(
    /\/canvas\/(editor|preview)\/([^/]+)\/([^/]+)\/?/,
  );
  // /template/:entityType/:bundle/:viewMode/:previewEntityId/
  // /canvas/api/v0/layout-content-template/{entity_type}.{template_bundle}.{template_view_mode}/{entity_id}
  const matchTemplateEditor = url.match(
    /\/canvas\/template\/([^/]+)\/([^/]+)\/([^/]+)\/([^/]+)\/?/,
  );
  if (matchPageEditor) {
    return { entityType: matchPageEditor[2], entityId: matchPageEditor[3] };
  } else if (matchTemplateEditor) {
    return {
      entityType: matchTemplateEditor[1],
      templateBundle: matchTemplateEditor[2],
      templateViewMode: matchTemplateEditor[3],
      entityId: matchTemplateEditor[4],
    };
  }
  return { entityType: undefined, entityId: undefined };
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
        const csrfResponse = await fetch(`${baseUrl}session/token`);
        if (csrfResponse.ok) {
          const csrfToken = await csrfResponse.text();
          headers.set('X-CSRF-Token', csrfToken);
        } else {
          console.error('Failed to generate the CSRF token.');
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
        !['createFolder', 'createContentTemplate', 'updateFolder'].includes(
          api.endpoint,
        )
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

// Export a baseQuery with autoSaves injection and debouncing by default
export const baseQueryWithAutoSaves: BaseQueryFn<
  string | FetchArgs,
  unknown,
  FetchBaseQueryError
> = withAutoSavesInjection(createDebouncedBaseQuery(baseQuery));
