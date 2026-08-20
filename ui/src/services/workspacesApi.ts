// Need to use the React-specific entry point to import createApi
import { createApi } from '@reduxjs/toolkit/query/react';

import { baseQuery } from '@/services/baseQuery';

import type {
  CreateWorkspacePayload,
  PublishActionPayload,
  Workspace,
  WorkspacePendingChange,
} from '@/types/Workspace';

interface WorkspacesResponse {
  data: Workspace[];
}

interface CreateWorkspaceResponse {
  data: Workspace;
}

interface PublishActionResponse {
  message: string;
}

// Define a service using a base URL and expected endpoints
export const workspacesApi = createApi({
  reducerPath: 'workspacesApi',
  baseQuery,
  tagTypes: ['Workspace', 'WorkspaceChanges'],
  endpoints: (builder) => ({
    getWorkspaces: builder.query<Workspace[], void>({
      query: () => '/canvas/api/v0/workspaces-dev',
      transformResponse: (response: WorkspacesResponse) => response.data,
      providesTags: [{ type: 'Workspace', id: 'LIST' }],
    }),
    createWorkspace: builder.mutation<
      CreateWorkspaceResponse,
      CreateWorkspacePayload
    >({
      query: (body) => ({
        url: '/canvas/api/v0/workspaces-dev',
        method: 'POST',
        body,
      }),
      invalidatesTags: [{ type: 'Workspace', id: 'LIST' }],
    }),
    getWorkspaceChanges: builder.query<WorkspacePendingChange[], string>({
      query: (workspaceId) =>
        `/canvas/api/v0/workspaces-dev/${workspaceId}/changes`,
      providesTags: (result, error, workspaceId) => [
        { type: 'WorkspaceChanges', id: workspaceId },
      ],
    }),
    publishWorkspaceAction: builder.mutation<
      PublishActionResponse,
      { workspaceId: string } & PublishActionPayload
    >({
      query: ({ workspaceId, ...payload }) => ({
        url: `/canvas/api/v0/workspaces-dev/${workspaceId}/publish-action`,
        method: 'POST',
        body: payload,
      }),
      invalidatesTags: (result, error, { workspaceId }) => [
        { type: 'Workspace', id: 'LIST' },
        { type: 'WorkspaceChanges', id: workspaceId },
      ],
    }),
  }),
});

// Export hooks for usage in functional layout, which are
// auto-generated based on the defined endpoints
export const {
  useGetWorkspacesQuery,
  useCreateWorkspaceMutation,
  useGetWorkspaceChangesQuery,
  usePublishWorkspaceActionMutation,
} = workspacesApi;
