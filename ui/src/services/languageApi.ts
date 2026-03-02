import { createApi, fetchBaseQuery } from '@reduxjs/toolkit/query/react';
import { setAvailableLanguages } from '@/features/language/languageSlice';
import type { Language } from '@/features/language/languageSlice';

interface TranslationResponseItem {
  langcode: string;
  label: string;
  exists: boolean;
  status: string;
  last_modified: { timestamp: number; formatted: string } | null;
  created: { timestamp: number; formatted: string } | null;
  is_default: boolean;
}

interface GetTranslationsResponse {
  data: TranslationResponseItem[];
}

interface CreateTranslationRequest {
  entityType: string;
  id: string;
  targetLangcode: string;
  sourceLangcode: string;
}

interface CreateTranslationResponse {
  message: string;
  langcode: string;
  source_langcode: string;
  entity_type: string;
  entity_id: string;
}

export const languageApi = createApi({
  reducerPath: 'languageApi',
  baseQuery: fetchBaseQuery({ baseUrl: '/canvas/api/v0/' }),
  tagTypes: ['Languages'],
  endpoints: (builder) => ({
    getLanguages: builder.query<Language[], { entityType: string; id: string }>({
      query: ({ entityType, id }) => `${entityType}/${id}/translations`,
      providesTags: [{ type: 'Languages', id: 'LIST' }],
      transformResponse: (response: GetTranslationsResponse): Language[] =>
        response.data.map(({ langcode, label, is_default }) => ({
          id: langcode,
          label,
          isDefault: is_default,
        })),
      async onQueryStarted(_arg, { dispatch, queryFulfilled }) {
        try {
          const { data } = await queryFulfilled;
          dispatch(setAvailableLanguages(data));
        } catch {
          // fail silently
        }
      },
    }),

    createTranslation: builder.mutation<CreateTranslationResponse, CreateTranslationRequest>({
      query: ({ entityType, id, targetLangcode, sourceLangcode }) => ({
        url: `${entityType}/${id}/translations/${targetLangcode}`,
        method: 'POST',
        body: { source_langcode: sourceLangcode },
      }),
      invalidatesTags: [{ type: 'Languages', id: 'LIST' }],
    }),
  }),
});

export const { useGetLanguagesQuery, useCreateTranslationMutation } = languageApi;