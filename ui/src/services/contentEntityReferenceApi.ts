import { createApi } from '@reduxjs/toolkit/query/react';

import { baseQuery } from '@/services/baseQuery';

const TYPED_DATA_BROWSER_LINK_REL =
  'https://drupal.org/project/canvas#link-rel-typed-data-browser';

type Links = Partial<
  Record<typeof TYPED_DATA_BROWSER_LINK_REL, { href: string }>
>;

export type ContentEntityReferenceEntityTypes = Record<
  string,
  {
    label: string;
    bundles: Record<string, { label: string; links?: Links }>;
  }
>;

export interface ContentEntityReferenceFieldProperty {
  name: string;
  label: string;
  expression: string;
  // The typed-data data type of the property's value (e.g. 'string',
  // 'integer', 'uri'), for the entity-prop shape preview skeleton.
  type: string;
}

interface ContentEntityReferenceFieldTargetBundle {
  label: string;
  labelExpression: string;
  links?: Links;
}

export interface ContentEntityReferenceField {
  name: string;
  label: string;
  hasChildren: boolean;
  // The payload key this field actually resolves to (usually `name`, but
  // entity keys like a node's `title` field normalize to their generic name,
  // e.g. `label`). Use this, not `name`, wherever code must match the shape
  // the component actually receives (e.g. the shape-preview skeleton).
  // @see JsComponent::buildReferencePayload()
  developerFacingKey: string;
  targetEntityType?: string;
  targetBundles?: Record<string, ContentEntityReferenceFieldTargetBundle>;
  properties: ContentEntityReferenceFieldProperty[];
}

export function getFieldsHref(
  source: { links?: Links } | undefined,
): string | undefined {
  return source?.links?.[TYPED_DATA_BROWSER_LINK_REL]?.href;
}

export interface ContentEntityReferencePreviewRequest {
  entityTypeId: string;
  entityId: string;
  entityFields: Record<string, string[]>;
}

export const contentEntityReferenceApi = createApi({
  reducerPath: 'contentEntityReferenceApi',
  baseQuery,
  endpoints: (builder) => ({
    listEntityTypes: builder.query<ContentEntityReferenceEntityTypes, void>({
      query: () => ({
        url: `/canvas/api/v0/ui/content-entity-reference`,
      }),
      transformResponse: (response: {
        data: ContentEntityReferenceEntityTypes;
      }) => response.data,
    }),
    listFields: builder.query<ContentEntityReferenceField[], { href: string }>({
      query: ({ href }) => ({ url: href }),
      transformResponse: (response: { data: ContentEntityReferenceField[] }) =>
        response.data,
    }),
    previewContentEntityReference: builder.mutation<
      Record<string, unknown>,
      ContentEntityReferencePreviewRequest
    >({
      query: ({ entityTypeId, entityId, entityFields }) => ({
        url: `/canvas/api/v0/ui/content-entity-reference/preview/${entityTypeId}/${entityId}`,
        method: 'POST',
        body: { entityFields },
      }),
      transformResponse: (response: { data: Record<string, unknown> }) =>
        response.data,
    }),
  }),
});

export const {
  useListEntityTypesQuery,
  useListFieldsQuery,
  usePreviewContentEntityReferenceMutation,
} = contentEntityReferenceApi;
