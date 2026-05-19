import { createApi } from '@reduxjs/toolkit/query/react';

import { baseQueryWithAutoSaves } from '@/services/baseQuery';
import { componentAndLayoutApi } from '@/services/componentAndLayout';
import { handleAutoSavesHashUpdate } from '@/utils/autoSaves';

import type { FormElement, SubmitSettings } from '@/features/formBuilder/types';
import type { AutoSavesHash } from '@/types/AutoSaves';

interface CanvasFormData {
  id: string;
  name: string;
  formElements: FormElement[];
  submitSettings: SubmitSettings;
}

type CanvasFormList = Record<string, CanvasFormData>;

interface SaveFormPayload {
  id?: string;
  name: string;
  formElements: FormElement[];
  submitSettings: SubmitSettings;
}

interface AutoSaveResponse {
  data: CanvasFormData | null;
  autoSaves: Record<string, unknown>;
}

export interface Submission {
  id: string;
  form_id: string;
  submitted_at: number;
  ip: string;
  values: Record<string, unknown>;
}

const SUBMISSIONS_BASE = '/canvas/api/v0/form-submissions';
const BASE = '/canvas/api/v0/config/canvas_form';
const AUTO_SAVE_BASE = '/canvas/api/v0/config/auto-save/canvas_form';

export const canvasFormApi = createApi({
  reducerPath: 'canvasFormApi',
  baseQuery: baseQueryWithAutoSaves,
  tagTypes: ['CanvasForm', 'CanvasFormAutoSave', 'CanvasFormSubmissions'],
  endpoints: (builder) => ({
    getSchemas: builder.query<Record<string, unknown>, void>({
      query: () => '/canvas/api/v0/form-builder/schemas',
    }),

    getForms: builder.query<CanvasFormList, void>({
      query: () => BASE,
      providesTags: [{ type: 'CanvasForm', id: 'LIST' }],
    }),

    getForm: builder.query<CanvasFormData, string>({
      query: (id) => `${BASE}/${id}`,
      providesTags: (result, error, id) => [{ type: 'CanvasForm', id }],
    }),

    getAutoSave: builder.query<AutoSaveResponse, string>({
      query: (id) => `${AUTO_SAVE_BASE}/${id}`,
      providesTags: (result, error, id) => [{ type: 'CanvasFormAutoSave', id }],
      async onQueryStarted(id, { dispatch, queryFulfilled }) {
        try {
          const { data, meta } = await queryFulfilled;
          handleAutoSavesHashUpdate(
            dispatch,
            data.autoSaves as AutoSavesHash,
            meta,
          );
        } catch {
          // no-op
        }
      },
    }),

    getSubmissions: builder.query<Submission[], string>({
      query: (formId) => `${SUBMISSIONS_BASE}/${formId}`,
      providesTags: (result, error, formId) => [
        { type: 'CanvasFormSubmissions', id: formId },
      ],
    }),

    createForm: builder.mutation<CanvasFormData, SaveFormPayload>({
      query: (body) => ({ url: BASE, method: 'POST', body }),
      invalidatesTags: [{ type: 'CanvasForm', id: 'LIST' }],
    }),

    updateForm: builder.mutation<
      CanvasFormData,
      SaveFormPayload & { id: string }
    >({
      query: ({ id, ...body }) => ({
        url: `${BASE}/${id}`,
        method: 'PATCH',
        body,
      }),
      invalidatesTags: (result, error, { id }) => [
        { type: 'CanvasForm', id },
        { type: 'CanvasForm', id: 'LIST' },
        { type: 'CanvasFormAutoSave', id },
      ],
      async onQueryStarted(arg, { dispatch, queryFulfilled }) {
        await queryFulfilled;
        dispatch(
          componentAndLayoutApi.util.invalidateTags([
            { type: 'Components', id: 'LIST' },
          ]),
        );
      },
    }),

    deleteForm: builder.mutation<void, string>({
      query: (id) => ({ url: `${BASE}/${id}`, method: 'DELETE' }),
      invalidatesTags: [{ type: 'CanvasForm', id: 'LIST' }],
    }),

    deleteSubmission: builder.mutation<
      void,
      { formId: string; submissionId: string }
    >({
      query: ({ formId, submissionId }) => ({
        url: `${SUBMISSIONS_BASE}/${formId}/${submissionId}`,
        method: 'DELETE',
      }),
      invalidatesTags: (result, error, { formId }) => [
        { type: 'CanvasFormSubmissions', id: formId },
      ],
    }),
  }),
});

export const {
  useGetSchemasQuery,
  useGetFormsQuery,
  useGetFormQuery,
  useGetAutoSaveQuery,
  useGetSubmissionsQuery,
  useCreateFormMutation,
  useUpdateFormMutation,
  useDeleteFormMutation,
  useDeleteSubmissionMutation,
} = canvasFormApi;
