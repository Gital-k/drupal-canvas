import { createApi } from '@reduxjs/toolkit/query/react';

import { setUpdatePreview } from '@/features/layout/layoutModelSlice';
import { baseQueryWithAutoSaves } from '@/services/baseQuery';
import { pendingChangesApi } from '@/services/pendingChangesApi';
import { handleAutoSavesHashUpdate } from '@/utils/autoSaves';

import type { AutoSavesHash } from '@/types/AutoSaves';
import type { BrandKit, BrandKitColor } from '@/types/CodeComponent';

const GLOBAL_BRAND_KIT_ID = 'global';

const createTempColorId = () =>
  typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
    ? `temp-${crypto.randomUUID()}`
    : `temp-${Date.now()}-${Math.random().toString(16).slice(2)}`;

const withGlobalBrandKit = (
  brandKits: Record<string, BrandKit>,
  apply: (brandKit: BrandKit) => void,
) => {
  const globalBrandKit =
    brandKits[GLOBAL_BRAND_KIT_ID] ??
    Object.values(brandKits).find(
      (brandKit) => brandKit.id === GLOBAL_BRAND_KIT_ID,
    );
  if (globalBrandKit) {
    apply(globalBrandKit);
  }
};

export interface UploadedArtifact {
  fid: number;
  uri: string;
  url: string;
}

export const createUploadFontRequest = (file: File) => ({
  url: 'canvas/api/v0/artifacts/upload',
  method: 'POST' as const,
  body: file.slice(0, file.size, 'application/octet-stream'),
  headers: {
    'Content-Disposition': `file; filename="${file.name.replaceAll('"', '\\"')}"`,
  },
});

export const brandKitApi = createApi({
  reducerPath: 'brandKitApi',
  baseQuery: baseQueryWithAutoSaves,
  tagTypes: ['BrandKits', 'BrandKitsAutoSave'],
  endpoints: (builder) => ({
    getBrandKits: builder.query<Record<string, BrandKit>, void>({
      query: () => 'canvas/api/v0/config/brand_kit',
      providesTags: () => [{ type: 'BrandKits', id: 'LIST' }],
    }),
    getBrandKit: builder.query<BrandKit, string>({
      query: (id) => `canvas/api/v0/config/brand_kit/${id}`,
      providesTags: (result, error, id) => [{ type: 'BrandKits', id }],
    }),
    getAutoSave: builder.query<
      { data: BrandKit; autoSaves: AutoSavesHash },
      string
    >({
      query: (id) => `canvas/api/v0/config/auto-save/brand_kit/${id}`,
      providesTags: (result, error, id) => [{ type: 'BrandKitsAutoSave', id }],
      async onQueryStarted(id, { dispatch, queryFulfilled }) {
        try {
          const { data, meta } = await queryFulfilled;
          const { autoSaves } = data;
          handleAutoSavesHashUpdate(dispatch, autoSaves, meta);
        } catch (err) {
          console.error(err);
        }
      },
    }),
    updateAutoSave: builder.mutation<
      void,
      {
        id: string;
        data: Partial<BrandKit>;
      }
    >({
      query: ({ id, data }) => ({
        url: `canvas/api/v0/config/auto-save/brand_kit/${id}`,
        method: 'PATCH',
        body: { data },
      }),
      async onQueryStarted(arg, { dispatch, queryFulfilled }) {
        try {
          await queryFulfilled;
          dispatch(
            pendingChangesApi.util.invalidateTags([
              { type: 'PendingChanges', id: 'LIST' },
            ]),
          );
        } catch (err) {
          console.error(err);
        }
      },
      invalidatesTags: (result, error, { id }) => [
        { type: 'BrandKitsAutoSave', id },
      ],
    }),
    uploadFont: builder.mutation<UploadedArtifact, File>({
      query: (file) => createUploadFontRequest(file),
    }),
    createColor: builder.mutation<BrandKitColor, Omit<BrandKitColor, 'id'>>({
      query: (body) => ({
        url: 'canvas/api/v0/config/color',
        method: 'POST',
        body,
      }),
      async onQueryStarted(body, { dispatch, queryFulfilled }) {
        const optimisticColor: BrandKitColor = {
          id: createTempColorId(),
          ...body,
        };
        const patchResults = [
          dispatch(
            brandKitApi.util.updateQueryData(
              'getBrandKit',
              GLOBAL_BRAND_KIT_ID,
              (draft) => {
                draft.colors = draft.colors ?? [];
                draft.colors.push(optimisticColor);
              },
            ),
          ),
          dispatch(
            brandKitApi.util.updateQueryData(
              'getBrandKits',
              undefined,
              (draft) => {
                withGlobalBrandKit(draft, (brandKit) => {
                  brandKit.colors = brandKit.colors ?? [];
                  brandKit.colors.push(optimisticColor);
                });
              },
            ),
          ),
        ];
        try {
          const { data: createdColor } = await queryFulfilled;
          dispatch(
            brandKitApi.util.updateQueryData(
              'getBrandKit',
              GLOBAL_BRAND_KIT_ID,
              (draft) => {
                if (!draft.colors) {
                  return;
                }
                const index = draft.colors.findIndex(
                  (color) => color.id === optimisticColor.id,
                );
                if (index === -1) {
                  return;
                }
                draft.colors[index] = createdColor;
              },
            ),
          );
          dispatch(
            brandKitApi.util.updateQueryData(
              'getBrandKits',
              undefined,
              (draft) => {
                withGlobalBrandKit(draft, (brandKit) => {
                  if (!brandKit.colors) {
                    return;
                  }
                  const index = brandKit.colors.findIndex(
                    (color) => color.id === optimisticColor.id,
                  );
                  if (index === -1) {
                    return;
                  }
                  brandKit.colors[index] = createdColor;
                });
              },
            ),
          );
        } catch (err) {
          patchResults.forEach((patchResult) => patchResult.undo());
          console.error(err);
        }
      },
      invalidatesTags: [
        { type: 'BrandKits', id: 'LIST' },
        { type: 'BrandKits', id: GLOBAL_BRAND_KIT_ID },
      ],
    }),
    updateColor: builder.mutation<
      BrandKitColor,
      { id: string; changes: Partial<BrandKitColor> }
    >({
      query: ({ id, changes }) => ({
        url: `canvas/api/v0/config/color/${id}`,
        method: 'PATCH',
        body: changes,
      }),
      async onQueryStarted({ id, changes }, { dispatch, queryFulfilled }) {
        const patchResults = [
          dispatch(
            brandKitApi.util.updateQueryData(
              'getBrandKit',
              GLOBAL_BRAND_KIT_ID,
              (draft) => {
                const color = draft.colors?.find((entry) => entry.id === id);
                if (color) {
                  Object.assign(color, changes);
                }
              },
            ),
          ),
          dispatch(
            brandKitApi.util.updateQueryData(
              'getBrandKits',
              undefined,
              (draft) => {
                withGlobalBrandKit(draft, (brandKit) => {
                  const color = brandKit.colors?.find(
                    (entry) => entry.id === id,
                  );
                  if (color) {
                    Object.assign(color, changes);
                  }
                });
              },
            ),
          ),
        ];
        try {
          await queryFulfilled;
          dispatch(setUpdatePreview(true));
        } catch (err) {
          patchResults.forEach((patchResult) => patchResult.undo());
          console.error(err);
        }
      },
      invalidatesTags: [
        { type: 'BrandKits', id: 'LIST' },
        { type: 'BrandKits', id: GLOBAL_BRAND_KIT_ID },
      ],
    }),
    deleteColor: builder.mutation<void, string>({
      query: (id) => ({
        url: `canvas/api/v0/config/color/${id}`,
        method: 'DELETE',
      }),
      async onQueryStarted(id, { dispatch, queryFulfilled }) {
        const patchResults = [
          dispatch(
            brandKitApi.util.updateQueryData(
              'getBrandKit',
              GLOBAL_BRAND_KIT_ID,
              (draft) => {
                if (!draft.colors) {
                  return;
                }
                draft.colors = draft.colors.filter((color) => color.id !== id);
              },
            ),
          ),
          dispatch(
            brandKitApi.util.updateQueryData(
              'getBrandKits',
              undefined,
              (draft) => {
                withGlobalBrandKit(draft, (brandKit) => {
                  if (!brandKit.colors) {
                    return;
                  }
                  brandKit.colors = brandKit.colors.filter(
                    (color) => color.id !== id,
                  );
                });
              },
            ),
          ),
        ];
        try {
          await queryFulfilled;
        } catch (err) {
          patchResults.forEach((patchResult) => patchResult.undo());
          console.error(err);
        }
      },
      invalidatesTags: [
        { type: 'BrandKits', id: 'LIST' },
        { type: 'BrandKits', id: GLOBAL_BRAND_KIT_ID },
      ],
    }),
  }),
});

export const {
  useGetBrandKitsQuery,
  useGetBrandKitQuery,
  useGetAutoSaveQuery,
  useUpdateAutoSaveMutation,
  useUploadFontMutation,
  useCreateColorMutation,
  useUpdateColorMutation,
  useDeleteColorMutation,
} = brandKitApi;
