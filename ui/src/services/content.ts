import { createApi } from '@reduxjs/toolkit/query/react';

import { HOMEPAGE_CONFIG_ID } from '@/components/pageInfo/PageInfo';
import { baseQueryWithAutoSaves } from '@/services/baseQuery';
import { pendingChangesApi } from '@/services/pendingChangesApi';

import type { StagedConfig } from '@/types/Config';
import type { ContentStub } from '@/types/Content';

export interface ContentListResponse {
  [key: string]: ContentStub;
}

export interface DeleteContentRequest {
  entityType: string;
  entityId: string;
}

export interface CreateContentResponse {
  entity_id: string;
  entity_type: string;
}

export interface CreateContentRequest {
  entity_id?: string;
  entity_type: string;
}

export interface UpdateContentRequest {
  entityType: string;
  entityId: string;
  status?: boolean;
}

export interface ContentListParams {
  entityType: string;
  search?: string;
}

export const contentApi = createApi({
  reducerPath: 'contentApi',
  baseQuery: baseQueryWithAutoSaves,
  tagTypes: ['Content', 'StagedConfig', 'PendingChanges'],
  endpoints: (builder) => ({
    getContentList: builder.query<ContentStub[], ContentListParams>({
      query: ({ entityType, search }) => {
        const params = new URLSearchParams();
        if (search) {
          const normalizedSearch = search.toLowerCase().trim();
          params.append('search', normalizedSearch);
        }
        return {
          url: `/canvas/api/v0/content/${entityType}`,
          params: search ? params : undefined,
        };
      },
      transformResponse: (response: ContentListResponse) => {
        return Object.values(response);
      },
      providesTags: [{ type: 'Content', id: 'LIST' }],
    }),
    deleteContent: builder.mutation<void, DeleteContentRequest>({
      query: ({ entityType, entityId }) => ({
        url: `/canvas/api/v0/content/${entityType}/${entityId}`,
        method: 'DELETE',
      }),
      invalidatesTags: [{ type: 'Content', id: 'LIST' }],
    }),
    createContent: builder.mutation<
      CreateContentResponse,
      CreateContentRequest
    >({
      query: ({ entity_type, entity_id }) => ({
        url: `/canvas/api/v0/content/${entity_type}`,
        method: 'POST',
        body: entity_id ? { entity_id } : {},
      }),
      invalidatesTags: [{ type: 'Content', id: 'LIST' }],
    }),
    updateContent: builder.mutation<void, UpdateContentRequest>({
      query: ({ entityType, entityId, status }) => ({
        url: `/canvas/api/v0/content/${entityType}/${entityId}`,
        method: 'PATCH',
        body: status !== undefined ? { status } : {},
      }),
      invalidatesTags: [
        { type: 'Content', id: 'LIST' },
        { type: 'PendingChanges', id: 'LIST' },
      ],
      async onQueryStarted(arg, { dispatch, queryFulfilled, getState }) {
        const { entityType, entityId, status } = arg;
        const archiveLinkRel =
          'https://drupal.org/project/canvas#link-rel-archive';
        const publishLinkRel =
          'https://drupal.org/project/canvas#link-rel-publish';

        // Update function to apply to each matching query
        const updatePageData = (draft: ContentStub[]) => {
          const page = draft.find(
            (item) => String(item.id) === String(entityId),
          );
          if (page && status !== undefined) {
            // Update the status to reflect the autosaved state
            page.status = status;

            // Swap the links: archive and publish both use the same endpoint,
            // so we can reuse the existing URL structure
            if (status === false) {
              // Archiving: remove archive link, add publish link
              // Both use the same PATCH endpoint, so reuse the archive URL if it exists
              const existingUrl = page.links[archiveLinkRel];
              delete page.links[archiveLinkRel];
              if (existingUrl) {
                page.links[publishLinkRel] = existingUrl;
              }
            } else if (status === true) {
              // Publishing: remove publish link, add archive link
              // Both use the same PATCH endpoint, so reuse the publish URL if it exists
              const existingUrl = page.links[publishLinkRel];
              delete page.links[publishLinkRel];
              if (existingUrl) {
                page.links[archiveLinkRel] = existingUrl;
              }
            }
          }
        };

        // Optimistically update all content list queries for this entity type
        // (regardless of search parameter) to immediately change menu options and badge
        const patchResults: Array<{ undo: () => void }> = [];

        // Update queries with different search parameter combinations
        // RTK Query caches queries by exact parameters, so we need to update all variants
        const queryVariants = [
          { entityType }, // No search
          { entityType, search: undefined }, // Explicit undefined
          { entityType, search: '' }, // Empty string
        ];

        // Try to update each variant
        queryVariants.forEach((args) => {
          try {
            const patchResult = dispatch(
              contentApi.util.updateQueryData(
                'getContentList',
                args,
                updatePageData,
              ),
            );
            patchResults.push(patchResult);
          } catch (error) {
            // Query might not exist in cache, which is fine
          }
        });

        // Also try to find and update all other queries for this entity type
        // by accessing the query cache to catch any search terms we might have missed
        try {
          const state = getState() as any;
          const queryCache = state[contentApi.reducerPath]?.queries;

          if (queryCache) {
            // Find all queries for getContentList with matching entityType
            Object.keys(queryCache).forEach((queryKey) => {
              const query = queryCache[queryKey];
              if (
                query?.endpointName === 'getContentList' &&
                query?.originalArgs?.entityType === entityType
              ) {
                const args = query.originalArgs;
                // Check if we've already updated this query variant
                const alreadyUpdated = queryVariants.some(
                  (variant) =>
                    variant.entityType === args?.entityType &&
                    variant.search === args?.search,
                );

                if (args && !alreadyUpdated) {
                  try {
                    const patchResult = dispatch(
                      contentApi.util.updateQueryData(
                        'getContentList',
                        args,
                        updatePageData,
                      ),
                    );
                    patchResults.push(patchResult);
                  } catch (error) {
                    // Query might not exist or already updated, which is fine
                  }
                }
              }
            });
          }
        } catch (error) {
          // If query cache access fails, the variant updates above should still work
        }

        try {
          await queryFulfilled;
          // Immediately refetch pending changes to update the "Review {} change" text
          dispatch(
            pendingChangesApi.util.invalidateTags([
              { type: 'PendingChanges', id: 'LIST' },
            ]),
          );
        } catch {
          // On error, revert all optimistic updates
          patchResults.forEach((result) => result.undo());
        }
      },
    }),
    getStagedConfig: builder.query<StagedConfig, string>({
      query: (entityId) => ({
        url: `/canvas/api/v0/config/auto-save/staged_config_update/${entityId}`,
        method: 'GET',
      }),
      providesTags: (_result, _error, entityId) => [
        { type: 'StagedConfig', id: entityId },
      ],
    }),
    setStagedConfig: builder.mutation<void, StagedConfig>({
      query: (body) => ({
        url: `/canvas/api/v0/staged-update/auto-save`,
        method: 'POST',
        body,
      }),
      // Hardcode HOMEPAGE_CONFIG_ID for now, as it is the only config we handle right now.
      // In the future we can generalize this.
      invalidatesTags: [
        { type: 'StagedConfig', id: HOMEPAGE_CONFIG_ID },
        { type: 'Content', id: 'LIST' },
      ],
    }),
  }),
});

export const {
  useGetContentListQuery,
  useDeleteContentMutation,
  useCreateContentMutation,
  useUpdateContentMutation,
  useGetStagedConfigQuery,
  useSetStagedConfigMutation,
} = contentApi;
