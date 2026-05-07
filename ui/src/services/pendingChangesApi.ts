// Need to use the React-specific entry point to import createApi
import { createApi } from '@reduxjs/toolkit/query/react';

import {
  setConflicts,
  setErrors,
  setPreviousPendingChanges,
} from '@/components/review/PublishReview.slice';
import { baseQuery } from '@/services/baseQuery';
import { componentAndLayoutApi } from '@/services/componentAndLayout';

interface Owner {
  name: string;
  avatar: string | null;
  uri: string;
  id: number;
}

export interface PendingChange {
  owner: Owner;
  entity_type: string;
  entity_id: string | number;
  data_hash: string;
  langcode: string;
  label: string;
  updated: number;
  hasConflict?: boolean;
  conflict?: ConflictMetadata;
}

export type PendingChanges = {
  [x: string]: PendingChange;
};

interface SuccessResponse {
  message: string;
}

export interface ConflictError {
  code?: number;
  detail: string;
  source: {
    pointer: string;
  };
  meta?: ConflictErrorMeta;
}

export interface ConflictMetadata {
  status: boolean;
  conflictId: string;
  timestamp: number;
}

export interface ConflictErrorMeta {
  entity_type?: string;
  entity_id?: string | number;
  label?: string;
  api_auto_save_key?: string;
  conflict?: ConflictMetadata;
  resolvable?: boolean;
}

export interface PendingChangesResponse {
  data: PendingChanges;
  errors?: ConflictError[];
}

type PendingChangesApiResponse = PendingChanges | PendingChangesResponse;

const isPendingChangesResponse = (
  response: PendingChangesApiResponse,
): response is PendingChangesResponse => {
  const data = (response as { data?: unknown }).data;
  return typeof data === 'object' && data !== null && !('owner' in data);
};

export const normalizeConflictTimestamp = (
  timestamp?: number,
): number | undefined => {
  if (typeof timestamp !== 'number') {
    return undefined;
  }
  return timestamp < 1_000_000_000_000 ? timestamp * 1000 : timestamp;
};

export const normalizeConflictMetadata = (
  conflict?: ConflictMetadata,
): ConflictMetadata | undefined => {
  if (!conflict) {
    return undefined;
  }

  return {
    ...conflict,
    timestamp: normalizeConflictTimestamp(conflict.timestamp) ?? Date.now(),
  };
};

export const getAutoSaveKeyFromError = (
  error: ConflictError,
): string | undefined => error.meta?.api_auto_save_key || error.source.pointer;

const normalizePendingChangesResponse = (
  response: PendingChangesApiResponse,
): PendingChangesResponse => {
  if (isPendingChangesResponse(response)) {
    return response;
  }

  return {
    data: response,
  };
};

export const applyConflictStateFromResponse = (
  response: PendingChangesApiResponse,
): PendingChanges => {
  const normalizedResponse = normalizePendingChangesResponse(response);
  const byPointer = new Map<string, ConflictError>();
  for (const error of normalizedResponse.errors ?? []) {
    const pointer = getAutoSaveKeyFromError(error);
    if (pointer) {
      byPointer.set(pointer, error);
    }
  }

  return Object.fromEntries(
    Object.entries(normalizedResponse.data ?? {}).map(([pointer, change]) => {
      const conflictError = byPointer.get(pointer);
      const conflictMeta = conflictError?.meta;
      const conflict = normalizeConflictMetadata(conflictMeta?.conflict);

      return [
        pointer,
        {
          ...change,
          hasConflict: !!conflict,
          conflict,
        },
      ];
    }),
  );
};

export interface ErrorResponse {
  errors: Array<ConflictError>;
}

type DiscardPendingChangeArg = PendingChange & {
  pointer?: string;
};

export enum STATUS_CODE {
  CONFLICT = 409,
  UNPROCESSABLE_ENTITY = 422,
}

export enum CONFLICT_CODE {
  UNEXPECTED = 1,
  EXPECTED = 2,
  DETECTED = 4,
}

// Define a service using a base URL and expected endpoints
export const pendingChangesApi = createApi({
  reducerPath: 'pendingChangesApi',
  baseQuery,
  tagTypes: ['PendingChanges'],
  endpoints: (builder) => ({
    getAllPendingChanges: builder.query<PendingChanges, void>({
      query: () => ({
        url: `/canvas/api/v0/auto-saves/pending`,
        // Treat HTTP 409 (conflict) as a successful response, not an error
        // This prevents the error boundary from triggering when conflicts are detected
        validateStatus: (response, result) => {
          // Accept both 200 (success) and 409 (conflict) as valid responses
          return response.status === 200 || response.status === 409;
        },
      }),
      providesTags: () => [{ type: 'PendingChanges', id: 'LIST' }],
      transformResponse: (response: PendingChangesApiResponse) =>
        applyConflictStateFromResponse(response),
    }),
    publishAllPendingChanges: builder.mutation<
      SuccessResponse | ErrorResponse,
      PendingChanges
    >({
      query: (body) => ({
        url: `/canvas/api/v0/auto-saves/publish`,
        method: 'POST',
        body,
      }),
      async onQueryStarted(body, { dispatch, queryFulfilled }) {
        try {
          await queryFulfilled;

          dispatch(
            pendingChangesApi.util.updateQueryData(
              'getAllPendingChanges',
              undefined,
              (draft) => {
                // Remove only the changes that were successfully published
                Object.keys(body).forEach((key) => {
                  delete draft[key];
                });
                return draft;
              },
            ),
          );

          // Invalidate the layout query cache of the current entity to ensure that the autoSaves hash is updated
          // ALSO, For example Drupal has hook_entity_presave which allows altering an entity before it is saved.
          // Canvas will not be aware of any changes made in custom code here, therefore if Canvas doesn't re-request
          // after publishing, the auto-save request could wipe out any changes that were made in
          // any hook_entity_presave code
          dispatch(
            componentAndLayoutApi.util.invalidateTags([{ type: 'Layout' }]),
          );
          dispatch(setPreviousPendingChanges());
          dispatch(setConflicts());
          dispatch(setErrors());
        } catch (error: any) {
          dispatch(setErrors(error.error?.data));

          // Handle conflicts
          // @todo https://www.drupal.org/i/3503404
          if (error.error?.status === STATUS_CODE.CONFLICT) {
            // set previous response
            dispatch(setPreviousPendingChanges(body));
            // set conflicts
            dispatch(setConflicts(error?.error?.data?.errors));
          }
        }
      },
    }),
    discardPendingChange: builder.mutation<
      SuccessResponse | ErrorResponse,
      DiscardPendingChangeArg
    >({
      query: (change: PendingChange) => ({
        url: `/canvas/api/v0/auto-saves/${change.entity_type}/${change.entity_id}`,
        method: 'DELETE',
      }),
      async onQueryStarted(change, { dispatch, queryFulfilled }) {
        try {
          await queryFulfilled;
          if (change.pointer) {
            dispatch(
              pendingChangesApi.util.updateQueryData(
                'getAllPendingChanges',
                undefined,
                (draft) => {
                  delete draft[change.pointer as string];
                },
              ),
            );
          }
          dispatch(
            pendingChangesApi.util.invalidateTags([
              { type: 'PendingChanges', id: 'LIST' },
            ]),
          );

          // Reset errors
          dispatch(setConflicts());
          dispatch(setPreviousPendingChanges());
          dispatch(setErrors());
        } catch (error: any) {
          dispatch(
            setErrors({
              errors: [
                {
                  code: 0,
                  detail:
                    error?.error?.data?.message ??
                    'Failed to discard pending change',
                  source: { pointer: '' },
                  meta: {
                    entity_type: change.entity_type,
                    entity_id: change.entity_id,
                    label: change.label,
                  },
                },
              ],
            }),
          );
        }
      },
    }),
  }),
});

// Export hooks for usage in functional layout, which are
// auto-generated based on the defined endpoints
export const {
  useGetAllPendingChangesQuery,
  usePublishAllPendingChangesMutation,
  useDiscardPendingChangeMutation,
} = pendingChangesApi;
