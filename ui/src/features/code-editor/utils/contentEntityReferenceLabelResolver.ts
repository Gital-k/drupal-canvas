import { useCallback, useEffect, useMemo, useState } from 'react';

import { useAppDispatch } from '@/app/hooks';
import {
  contentEntityReferenceApi,
  getFieldsHref,
} from '@/services/contentEntityReferenceApi';

import type { AppDispatch } from '@/app/store';
import type { ContentEntityReferencePropState } from '@/features/code-editor/dialogs/ContentRelationshipDialog';
import type { ContentEntityReferenceField } from '@/services/contentEntityReferenceApi';

const LABEL_SEPARATOR = ' → ';
const MAX_RESOLUTION_DEPTH = 8;

type ContentRelationshipField =
  ContentEntityReferencePropState['fields'][number];

export function rowReferenceKey(
  ancestorKeys: string[],
  fieldName: string,
): string {
  const parent = ancestorKeys[ancestorKeys.length - 1];
  return parent ? `${parent}/${fieldName}` : fieldName;
}

export function joinLabelChain(segments: string[]): string {
  return segments.join(LABEL_SEPARATOR);
}

function getExpressionFallbackFields(
  expressions: string[],
): ContentRelationshipField[] {
  return expressions.map((expression) => ({
    expressions: [expression],
    label: expression,
    referenceChain: [],
  }));
}

async function fetchFields(
  dispatch: AppDispatch,
  href: string,
  forceRefetch: boolean,
): Promise<ContentEntityReferenceField[] | undefined> {
  // A previously failed listFields() query stays cached as an error, so a
  // retry must force a fresh network attempt to be able to recover.
  const promise = dispatch(
    contentEntityReferenceApi.endpoints.listFields.initiate(
      { href },
      { forceRefetch },
    ),
  );
  try {
    const { data } = await promise;
    return data;
  } finally {
    promise.unsubscribe();
  }
}

async function resolveExpressionFieldsFromHref({
  href,
  ancestorLabels,
  ancestorKeys,
  ancestorDeveloperKeys,
  pending,
  resolved,
  dispatch,
  isCancelled,
  depth,
  forceRefetch,
}: {
  href: string;
  ancestorLabels: string[];
  ancestorKeys: string[];
  // Developer-facing key of each reference hop leading to this level, in
  // payload nesting order. A resolved leaf's `keyChain` is this list plus
  // the leaf field's own developer-facing key.
  ancestorDeveloperKeys: string[];
  pending: Set<string>;
  resolved: Map<string, ContentRelationshipField>;
  dispatch: AppDispatch;
  isCancelled: () => boolean;
  depth: number;
  forceRefetch: boolean;
}): Promise<void> {
  if (isCancelled() || pending.size === 0 || depth > MAX_RESOLUTION_DEPTH) {
    return;
  }

  const fields = await fetchFields(dispatch, href, forceRefetch);
  if (isCancelled() || !fields) return;

  // First pass: resolve every flat (non-reference) leaf match across ALL
  // fields at this level before touching any reference chain. Most picks
  // are flat, so this finds them with no cost beyond the one fetch above,
  // instead of paying for unrelated reference sub-trees (e.g. `uid`,
  // `revision_uid`) whenever they happen to iterate before the field that
  // was actually picked.
  for (const field of fields) {
    const fieldLabels = [...ancestorLabels, field.label];
    for (const property of field.properties) {
      if (!pending.has(property.expression)) continue;
      resolved.set(property.expression, {
        expressions: [property.expression],
        label: joinLabelChain([...fieldLabels, property.label]),
        referenceChain: ancestorKeys,
        keyChain: [...ancestorDeveloperKeys, field.developerFacingKey],
        type: property.type,
        propertyName: property.name,
      });
      pending.delete(property.expression);
    }
  }
  if (pending.size === 0 || isCancelled()) return;

  // Second pass: remaining pending expressions must be inside a reference
  // chain. Explore every reference field at this level concurrently instead
  // of sequentially, so N sibling chains cost as much as the slowest one,
  // not the sum of all of them.
  await Promise.all(
    fields.flatMap((field) => {
      const rowKey = rowReferenceKey(ancestorKeys, field.name);
      const fieldLabels = [...ancestorLabels, field.label];
      return Object.entries(field.targetBundles ?? {}).map(
        async ([bundleId, targetBundle]) => {
          if (pending.size === 0 || isCancelled()) return;
          const nestedHref = getFieldsHref(targetBundle);
          if (!nestedHref) return;
          await resolveExpressionFieldsFromHref({
            href: nestedHref,
            ancestorLabels: fieldLabels,
            ancestorKeys: [...ancestorKeys, rowKey, `${rowKey}:${bundleId}`],
            ancestorDeveloperKeys: [
              ...ancestorDeveloperKeys,
              field.developerFacingKey,
            ],
            pending,
            resolved,
            dispatch,
            isCancelled,
            depth: depth + 1,
            forceRefetch,
          });
        },
      );
    }),
  );
}

export interface ResolvedExpressionFields {
  fields: ContentRelationshipField[];
  // False while the labels/types for `fields` are still being fetched (or
  // haven't started, e.g. no target yet): `fields` is the raw
  // expression-string fallback until this flips to true.
  isResolved: boolean;
  // True when resolution has terminally failed: a listFields() fetch errored,
  // or the walk completed with expressions that match no field (e.g. a
  // deleted field). Mutually exclusive with `isResolved`; while both are
  // false, resolution is still in flight.
  isError: boolean;
  // Re-runs the resolution from scratch (forcing fresh fetches), returning
  // the hook to its loading state.
  retry: () => void;
}

export function useResolvedExpressionFields(
  expressions: string[],
  target: { entityType: string; bundle: string } | null,
): ResolvedExpressionFields {
  const dispatch = useAppDispatch();
  const [resolution, setResolution] = useState<{
    key: string;
    fields?: ContentRelationshipField[];
    isError: boolean;
  } | null>(null);
  const [retryNonce, setRetryNonce] = useState(0);
  const expressionKey = useMemo(
    () => JSON.stringify(expressions),
    [expressions],
  );
  // Keyed by attempt, not just by expressions: bumping the nonce invalidates
  // a previous terminal error so a retry renders as loading again.
  const resolutionKey = `${retryNonce}:${expressionKey}`;
  const fallbackFields = useMemo(
    () => getExpressionFallbackFields(expressions),
    [expressions],
  );

  useEffect(() => {
    if (expressions.length === 0) {
      setResolution({ key: resolutionKey, fields: [], isError: false });
      return;
    }
    if (!target) {
      setResolution(null);
      return;
    }

    // Built directly from the already-known target, same as the preview
    // entity suggestions query: resolving this prop's shape only ever needs
    // listFields() for this one (entityType, bundle), not the full
    // entity-types-and-bundles catalog (and its per-bundle access checks)
    // that `useListEntityTypesQuery` fetches for the type/bundle *picker*.
    const rootHref = `/canvas/api/v0/ui/content-entity-reference/${target.entityType}/${target.bundle}`;

    let cancelled = false;
    const pending = new Set(expressions);
    const resolved = new Map<string, ContentRelationshipField>();

    void resolveExpressionFieldsFromHref({
      href: rootHref,
      ancestorLabels: [],
      ancestorKeys: [],
      ancestorDeveloperKeys: [],
      pending,
      resolved,
      dispatch,
      isCancelled: () => cancelled,
      depth: 0,
      forceRefetch: retryNonce > 0,
    }).then(() => {
      if (cancelled) return;
      // `pending` only shrinks as expressions actually resolve: if a
      // listFields() request failed partway (network hiccup, stale cache),
      // or an expression matches no field, it stays non-empty. That is a
      // terminal error state (with retry), never a success presenting
      // unresolved raw expressions as final.
      if (pending.size > 0) {
        setResolution({ key: resolutionKey, isError: true });
        return;
      }
      setResolution({
        key: resolutionKey,
        isError: false,
        fields: fallbackFields.map(
          (field) => resolved.get(field.expressions[0]) ?? field,
        ),
      });
    });

    return () => {
      cancelled = true;
    };
  }, [
    dispatch,
    expressions,
    fallbackFields,
    resolutionKey,
    retryNonce,
    target,
  ]);

  const current = resolution?.key === resolutionKey ? resolution : null;
  const isResolved = current !== null && !current.isError;
  const retry = useCallback(() => setRetryNonce((nonce) => nonce + 1), []);
  return {
    fields: isResolved && current.fields ? current.fields : fallbackFields,
    isResolved,
    isError: current !== null && current.isError,
    retry,
  };
}
