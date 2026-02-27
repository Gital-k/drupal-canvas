import { createApi } from '@reduxjs/toolkit/query/react';

import { setAvailableLanguages } from '@/features/language/languageSlice';
import { baseQuery } from '@/services/baseQuery';

import type { Language } from '@/features/language/languageSlice';

type DrupalLanguagesResponse = Record<
  string,
  { label: string; isDefault: boolean }
>;

export const languageApi = createApi({
  reducerPath: 'languageApi',
  baseQuery,
  tagTypes: ['Languages'],
  endpoints: (builder) => ({
    getLanguages: builder.query<Language[], void>({
      query: () => 'canvas/api/v0/languages',
      providesTags: [{ type: 'Languages', id: 'LIST' }],
      transformResponse: (response: DrupalLanguagesResponse): Language[] =>
        Object.entries(response).map(([id, { label, isDefault }]) => ({
          id,
          label,
          isDefault,
        })),
      async onQueryStarted(_arg, { dispatch, queryFulfilled }) {
        try {
          const { data } = await queryFulfilled;
          dispatch(setAvailableLanguages(data));
        } catch {
          // Endpoint unavailable — fail silently.
        }
      },
    }),
  }),
});

export const { useGetLanguagesQuery } = languageApi;
