import { useCallback, useEffect, useId, useMemo, useState } from 'react';
import { JSONTree } from 'react-json-tree';
import ReactSelect from 'react-select';
import {
  ChevronDownIcon,
  ChevronUpIcon,
  CopyIcon,
  EyeOpenIcon,
  Pencil1Icon,
  PlusIcon,
} from '@radix-ui/react-icons';
import {
  Badge,
  Box,
  Button,
  Callout,
  Code,
  Flex,
  IconButton,
  Spinner,
  Text,
} from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  selectCodeComponentProperty,
  setEntityFieldExpressions,
  setPreviewEntity,
  setPreviewResolvedValue,
} from '@/features/code-editor/codeEditorSlice';
import { Divider } from '@/features/code-editor/component-data/FormElement';
import { jsonTreeThemeProp } from '@/features/code-editor/component-data/jsonTreeTheme';
import ContentRelationshipDialog from '@/features/code-editor/dialogs/ContentRelationshipDialog';
import { useResolvedExpressionFields } from '@/features/code-editor/utils/contentEntityReferenceLabelResolver';
import useDebounce from '@/hooks/useDebounce';
import { useGetPreviewContentEntitiesQuery } from '@/services/componentAndLayout';
import {
  useListEntityTypesQuery,
  usePreviewContentEntityReferenceMutation,
} from '@/services/contentEntityReferenceApi';

import type { ReactNode } from 'react';
import type { SingleValue, StylesConfig } from 'react-select';
import type { ContentEntityReferencePropState } from '@/features/code-editor/dialogs/ContentRelationshipDialog';

interface FormPropTypeContentEntityReferenceProps {
  id: string;
  propName: string;
  expressions: string[];
  targetEntityType: string | null;
  targetBundle: string | null;
  isDisabled: boolean;
}

export default function FormPropTypeContentEntityReference({
  id,
  propName,
  expressions,
  targetEntityType,
  targetBundle,
  isDisabled,
}: FormPropTypeContentEntityReferenceProps) {
  const dispatch = useAppDispatch();
  const { data: entityTypes, error } = useListEntityTypesQuery();

  const target = useMemo(() => {
    if (!targetEntityType) return null;
    return {
      entityType: targetEntityType,
      bundle: targetBundle ?? targetEntityType,
    };
  }, [targetEntityType, targetBundle]);

  // Resolve labels for initial expressions from the typed-data browser API.
  const {
    fields: resolvedExpressionFields,
    isResolved: isFieldsResolved,
    isError: isFieldsError,
    retry: retryFields,
  } = useResolvedExpressionFields(expressions, target);

  const [sessionState, setSessionState] =
    useState<ContentEntityReferencePropState | null>(null);
  const resolvedState = useMemo(
    () =>
      sessionState ??
      (target ? { ...target, fields: resolvedExpressionFields } : null),
    [resolvedExpressionFields, sessionState, target],
  );
  // Session state is only ever seeded with fully resolved entries (see
  // handleSave), so its presence means the shape is known; otherwise only
  // the async resolver can settle it.
  const isShapeResolved = Boolean(sessionState) || isFieldsResolved;
  const isShapeError = !sessionState && isFieldsError;

  const [dialogOpen, setDialogOpen] = useState(false);

  const entityTypeLabel = resolvedState
    ? (entityTypes?.[resolvedState.entityType]?.label ??
      resolvedState.entityType)
    : '';
  const bundleLabel =
    resolvedState &&
    entityTypes?.[resolvedState.entityType]?.bundles[resolvedState.bundle]
      ? entityTypes[resolvedState.entityType].bundles[resolvedState.bundle]
          .label
      : (resolvedState?.bundle ?? '');

  const previewEntity = useAppSelector(
    (state) =>
      selectCodeComponentProperty('props')(state).find((p) => p.id === id)
        ?.previewEntity ?? null,
  );

  const [triggerPreview] = usePreviewContentEntityReferenceMutation();
  const expressionsKey = useMemo(
    () => JSON.stringify(expressions),
    [expressions],
  );

  // The Redux value stays null on failure (a component must never receive
  // undefined), so a failed preview request needs its own flag to render as
  // an error notice instead of as a legitimately-null reference.
  const [previewError, setPreviewError] = useState(false);

  // Resolve the selected preview entity's payload whenever the entity or the
  // field selection changes, and feed it into Redux for the preview iframe.
  // @see parsePropValueForPreview
  useEffect(() => {
    if (!previewEntity) {
      return;
    }
    let cancelled = false;
    setPreviewError(false);
    triggerPreview({
      entityTypeId: previewEntity.entityTypeId,
      entityId: previewEntity.entityId,
      entityFields: { value: expressions },
    })
      .unwrap()
      .then((resolved) => {
        if (cancelled) return;
        dispatch(
          setPreviewResolvedValue({
            propId: id,
            value: (resolved.value as Record<string, unknown> | null) ?? null,
          }),
        );
      })
      .catch(() => {
        if (cancelled) return;
        setPreviewError(true);
        dispatch(setPreviewResolvedValue({ propId: id, value: null }));
      });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [
    previewEntity?.entityTypeId,
    previewEntity?.entityId,
    expressionsKey,
    id,
  ]);

  const handleSave = (result: ContentEntityReferencePropState) => {
    // Fallback (unresolved) entries carry no keyChain; presenting them as a
    // resolved shape would render raw expression strings as payload keys.
    // Only seed session state when every entry is genuinely resolved, and
    // let the async resolver settle the rest.
    setSessionState(
      result.fields.every((field) => field.keyChain) ? result : null,
    );
    dispatch(
      setEntityFieldExpressions({
        propId: id,
        expressions: result.fields.flatMap((f) => f.expressions),
      }),
    );
  };

  const previewResolvedValue = useAppSelector(
    (state) =>
      selectCodeComponentProperty('props')(state).find((p) => p.id === id)
        ?.previewResolvedValue,
  );

  const showPreview = Boolean(resolvedState && resolvedState.fields.length > 0);

  return (
    <Flex direction="column" gap="3" flexGrow="1">
      <Divider />
      {error && (
        <Callout.Root color="red" size="1">
          <Callout.Text>Failed to load entity types.</Callout.Text>
        </Callout.Root>
      )}

      {!resolvedState ? (
        <NoTargetSelected onPick={() => setDialogOpen(true)} />
      ) : (
        <Flex direction="column" gap="3">
          <TargetSummary
            entityTypeLabel={entityTypeLabel}
            bundleLabel={bundleLabel}
          />
          <FieldList
            fields={resolvedState.fields}
            onEdit={() => setDialogOpen(true)}
          />
        </Flex>
      )}

      {showPreview && resolvedState && (
        <PreviewPanel
          propName={propName}
          entityTypeId={resolvedState.entityType}
          bundle={resolvedState.bundle}
          fields={resolvedState.fields}
          isShapeResolved={isShapeResolved}
          isShapeError={isShapeError}
          onRetryShape={retryFields}
          previewEntity={previewEntity}
          previewError={previewError}
          previewResolvedValue={previewResolvedValue}
          onEntityChange={(nextEntityId) =>
            dispatch(
              setPreviewEntity({
                propId: id,
                previewEntity: nextEntityId
                  ? {
                      entityTypeId: resolvedState.entityType,
                      entityId: nextEntityId,
                    }
                  : null,
              }),
            )
          }
        />
      )}

      {dialogOpen && (
        <ContentRelationshipDialog
          open={dialogOpen}
          onOpenChange={setDialogOpen}
          initialState={resolvedState}
          onSave={handleSave}
          lockTarget={isDisabled}
        />
      )}
    </Flex>
  );
}

function NoTargetSelected({ onPick }: { onPick: () => void }) {
  return (
    <Flex direction="column" align="center" gap="2" py="4">
      <Text size="2" weight="bold">
        No type selected
      </Text>
      <Flex direction="column" align="center">
        <Text size="1" color="gray" align="center">
          Select the type editors can link to.
        </Text>
        <Text size="1" color="gray" align="center">
          Then, choose which fields to return in the component props.
        </Text>
      </Flex>
      <Button size="1" variant="outline" onClick={onPick} mt="2">
        <PlusIcon />
        Add type
      </Button>
    </Flex>
  );
}

function TargetSummary({
  entityTypeLabel,
  bundleLabel,
}: {
  entityTypeLabel: string;
  bundleLabel: string;
}) {
  return (
    <Box
      px="3"
      py="2"
      style={{
        background: 'var(--gray-2)',
        borderRadius: 'var(--radius-2)',
      }}
    >
      <Flex align="center" gap="2">
        <Text size="1">
          Entity Type: <Text weight="medium">{entityTypeLabel}</Text>
        </Text>
        <Text size="1" color="gray">
          |
        </Text>
        <Text size="1">
          Bundle: <Text weight="medium">{bundleLabel}</Text>
        </Text>
      </Flex>
    </Box>
  );
}

function FieldList({
  fields,
  onEdit,
}: {
  fields: ContentEntityReferencePropState['fields'];
  onEdit: () => void;
}) {
  const [expanded, setExpanded] = useState(false);
  const summaryId = useId();
  const toggleExpanded = () => setExpanded((v) => !v);
  return (
    <Flex direction="column">
      <Flex align="center" justify="between" gap="2" py="1">
        <button
          type="button"
          aria-expanded={expanded}
          aria-controls={summaryId}
          onClick={toggleExpanded}
          style={{
            appearance: 'none',
            background: 'none',
            border: 0,
            color: 'inherit',
            cursor: 'pointer',
            padding: 0,
            textAlign: 'left',
          }}
        >
          <Text size="1" weight="medium">
            Entity fields ({fields.length})
          </Text>
        </button>
        <Flex align="center" gap="2">
          <Button
            size="1"
            variant="ghost"
            color="blue"
            onClick={onEdit}
            style={{ color: 'var(--blue-9)', fontWeight: 500 }}
          >
            <Pencil1Icon />
            Edit
          </Button>
          <IconButton
            size="1"
            variant="ghost"
            color="gray"
            aria-label={expanded ? 'Collapse fields' : 'Expand fields'}
            aria-controls={summaryId}
            onClick={toggleExpanded}
          >
            {expanded ? <ChevronUpIcon /> : <ChevronDownIcon />}
          </IconButton>
        </Flex>
      </Flex>
      {expanded && (
        <Flex id={summaryId} direction="column">
          {fields.map((field) => (
            <Flex
              key={field.expressions[0]}
              align="center"
              justify="between"
              gap="2"
              py="2"
              style={{
                borderBottom: '1px solid var(--gray-a4)',
                minWidth: 0,
              }}
            >
              <Text
                size="1"
                style={{
                  minWidth: 0,
                  overflowWrap: 'anywhere',
                  whiteSpace: 'normal',
                  wordBreak: 'break-word',
                }}
              >
                {field.label}
              </Text>
            </Flex>
          ))}
        </Flex>
      )}
    </Flex>
  );
}

interface EntityOption {
  value: string;
  label: string;
}

// Canvas's own controls (e.g. `Select.Root`/`TextField.Root` at
// `size="1"`, used elsewhere in this same props panel) render at 24px tall
// with a 12px font. react-select ships its own, much larger defaults, so
// they're overridden here to match instead of looking like a foreign widget.
const compactSelectStyles: StylesConfig<EntityOption, false> = {
  control: (base, state) => ({
    ...base,
    minHeight: 24,
    border: 'none',
    borderRadius: 'var(--radius-2)',
    boxShadow: state.isFocused
      ? '0 0 0 1px var(--blue-8)'
      : 'inset 0 0 0 1px var(--gray-a7)',
    backgroundColor: 'var(--color-surface)',
    fontSize: 'var(--font-size-1)',
    cursor: 'pointer',
  }),
  valueContainer: (base) => ({
    ...base,
    padding: '0 6px',
  }),
  input: (base) => ({
    ...base,
    margin: 0,
    padding: 0,
    color: 'var(--gray-12)',
  }),
  placeholder: (base) => ({
    ...base,
    margin: 0,
    color: 'var(--gray-a9)',
  }),
  singleValue: (base) => ({
    ...base,
    margin: 0,
    color: 'var(--gray-12)',
  }),
  indicatorsContainer: (base) => ({
    ...base,
    // No fixed height: let the container fill the 24px control so its
    // centered alignment actually centers the indicator icons.
    alignItems: 'center',
  }),
  dropdownIndicator: (base) => ({
    ...base,
    padding: 2,
    color: 'var(--gray-a9)',
    '& svg': { width: 14, height: 14 },
  }),
  clearIndicator: (base) => ({
    ...base,
    padding: 2,
    color: 'var(--gray-a9)',
    '& svg': { width: 14, height: 14 },
  }),
  indicatorSeparator: (base) => ({
    ...base,
    display: 'none',
  }),
  menu: (base) => ({
    ...base,
    zIndex: 9999,
    fontSize: 'var(--font-size-1)',
    borderRadius: 'var(--radius-2)',
  }),
  option: (base, state) => ({
    ...base,
    padding: '6px 8px',
    fontSize: 'var(--font-size-1)',
    cursor: 'pointer',
    color: 'var(--gray-12)',
    backgroundColor: state.isFocused ? 'var(--gray-a3)' : 'transparent',
  }),
};

interface ShapeSkeletonNode {
  key: string;
  type: string;
  // Present (and non-empty) only when `type` is 'object': the sub-keys the
  // real payload nests under this key. Either a referenced entity's payload
  // object (always including its `__type`), or, for a field with two or
  // more picked properties, an object keyed by property names.
  children?: ShapeSkeletonNode[];
}

// One level of the payload shape while it is being assembled: reference
// hops descend into `refs`; picked leaf properties collect per leaf field
// key in `leaves` (property name -> property type).
interface ShapeTreeLevel {
  refs: Map<string, ShapeTreeLevel>;
  leaves: Map<string, Map<string, string>>;
}

function shapeLevelToSkeleton(level: ShapeTreeLevel): ShapeSkeletonNode[] {
  // Leaves before references, matching the key order of the real payload.
  // @see JsComponent::buildReferencePayload()
  const nodes: ShapeSkeletonNode[] = [];
  for (const [key, properties] of level.leaves) {
    // A field picked both as a scalar (e.g. a reference's target_id) and
    // followed into: the payload keeps the nested object, so the skeleton
    // does too.
    if (level.refs.has(key)) {
      continue;
    }
    if (properties.size === 1) {
      const [type] = properties.values();
      nodes.push({ key, type });
    } else {
      // Two or more properties picked on one field coalesce into an object
      // keyed by property names, regardless of their types.
      nodes.push({
        key,
        type: 'object',
        children: Array.from(properties, ([propertyName, type]) => ({
          key: propertyName,
          type,
        })),
      });
    }
  }
  for (const [key, child] of level.refs) {
    nodes.push({
      key,
      type: 'object',
      children: [
        // Every entity object in the payload carries a `__type` set to the
        // resolved entity's bundle.
        { key: '__type', type: 'string' },
        ...shapeLevelToSkeleton(child),
      ],
    });
  }
  return nodes;
}

// Derives the payload shape by inserting every picked property along its
// `keyChain` (one nested object per reference hop, then the leaf field's
// key). Fallback (unresolved) entries have no keyChain and are skipped;
// callers gate on the resolved state before presenting the skeleton.
// @see JsComponent::buildReferencePayload()
function buildShapeSkeleton(
  fields: ContentEntityReferencePropState['fields'],
): ShapeSkeletonNode[] {
  const root: ShapeTreeLevel = { refs: new Map(), leaves: new Map() };
  for (const field of fields) {
    if (!field.keyChain || field.keyChain.length === 0) {
      continue;
    }
    let level = root;
    for (const hopKey of field.keyChain.slice(0, -1)) {
      let next = level.refs.get(hopKey);
      if (!next) {
        next = { refs: new Map(), leaves: new Map() };
        level.refs.set(hopKey, next);
      }
      level = next;
    }
    const leafKey = field.keyChain[field.keyChain.length - 1];
    const properties = level.leaves.get(leafKey) ?? new Map<string, string>();
    properties.set(field.propertyName ?? 'value', field.type ?? 'unknown');
    level.leaves.set(leafKey, properties);
  }
  return shapeLevelToSkeleton(root);
}

function destructureNode(node: ShapeSkeletonNode): string {
  if (!node.children || node.children.length === 0) {
    return node.key;
  }
  const children = node.children
    // `__type` is always present in the payload but is not part of the
    // user's selection; keep the snippet to what they picked.
    .filter((child) => child.key !== '__type')
    .map(destructureNode);
  return `${node.key}: { ${children.join(', ')} }`;
}

// Renders a skeleton as a `const { ... } = propName;` destructuring snippet
// mirroring the payload's nesting exactly.
function buildDestructureSnippet(
  propName: string,
  skeleton: ShapeSkeletonNode[],
): string {
  return `const { ${skeleton.map(destructureNode).join(', ')} } = ${propName};`;
}

// For a shape preview, the point of a value is to show roughly what's
// there, not to be a full-text viewer: a long value (e.g. a rich text
// body) is truncated for display. The full value is still what gets
// copied via `CopyableText`.
const MAX_DISPLAYED_VALUE_LENGTH = 60;

function truncateForDisplay(text: string): string {
  return text.length > MAX_DISPLAYED_VALUE_LENGTH
    ? `${text.slice(0, MAX_DISPLAYED_VALUE_LENGTH)}…`
    : text;
}

// Returns a transient "copied" flag plus a setter to raise it; the flag
// clears itself after `durationMs`.
function useCopiedFlag(durationMs: number): [boolean, () => void] {
  const [copied, setCopied] = useState(false);
  useEffect(() => {
    if (!copied) return undefined;
    const timeoutId = window.setTimeout(() => setCopied(false), durationMs);
    return () => window.clearTimeout(timeoutId);
  }, [copied, durationMs]);
  const markCopied = useCallback(() => setCopied(true), []);
  return [copied, markCopied];
}

function PreviewPanel({
  propName,
  entityTypeId,
  bundle,
  fields,
  isShapeResolved,
  isShapeError,
  onRetryShape,
  previewEntity,
  previewError,
  previewResolvedValue,
  onEntityChange,
}: {
  propName: string;
  entityTypeId: string;
  bundle: string;
  fields: ContentEntityReferencePropState['fields'];
  isShapeResolved: boolean;
  isShapeError: boolean;
  onRetryShape: () => void;
  previewEntity: { entityTypeId: string; entityId: string } | null;
  previewError: boolean;
  previewResolvedValue: Record<string, unknown> | null | undefined;
  onEntityChange: (entityId: string | null) => void;
}) {
  const [expanded, setExpanded] = useState(true);
  const panelId = useId();
  const skeleton = useMemo(() => buildShapeSkeleton(fields), [fields]);
  const hasPreviewEntity = Boolean(previewEntity);
  // `previewResolvedValue` is `undefined` only while resolution is in
  // flight (cleared on selection, set to a value or `null` once settled);
  // without distinguishing this, a still-loading entity briefly renders as
  // an outright `null` value instead of a loading state.
  const isLoadingResolvedValue =
    hasPreviewEntity && previewResolvedValue === undefined;
  const isLoadingSkeleton =
    !hasPreviewEntity && !isShapeResolved && !isShapeError;

  const [copied, markCopied] = useCopiedFlag(1500);
  const handleCopy = () => {
    void navigator.clipboard
      .writeText(buildDestructureSnippet(propName, skeleton))
      .then(markCopied);
  };

  return (
    <Box
      style={{
        border: '1px solid var(--gray-a5)',
        borderRadius: 'var(--radius-3)',
        padding: '8px 12px 12px',
        minWidth: 0,
      }}
    >
      <Flex align="center" justify="between" gap="2">
        <Flex align="center" gap="2">
          <EyeOpenIcon color="var(--blue-9)" />
          <Text size="1" weight="bold">
            Preview
          </Text>
        </Flex>
        <IconButton
          size="1"
          variant="ghost"
          color="gray"
          aria-label={expanded ? 'Collapse preview' : 'Expand preview'}
          aria-controls={panelId}
          onClick={() => setExpanded((v) => !v)}
        >
          {expanded ? <ChevronUpIcon /> : <ChevronDownIcon />}
        </IconButton>
      </Flex>

      {expanded && (
        <Flex id={panelId} direction="column" gap="3" mt="2">
          <EntityPreviewPicker
            entityTypeId={entityTypeId}
            bundle={bundle}
            previewEntity={previewEntity}
            onChange={onEntityChange}
          />

          <Flex direction="column" gap="1" style={{ minWidth: 0 }}>
            <Flex align="center" justify="between" gap="2">
              <Text size="1" color="gray" weight="medium">
                Shape
              </Text>
              <Button
                size="1"
                variant="ghost"
                color="gray"
                disabled={!isShapeResolved}
                onClick={handleCopy}
              >
                <CopyIcon />
                {copied ? 'Copied' : 'Copy snippet'}
              </Button>
            </Flex>
            {isShapeError && !hasPreviewEntity ? (
              <ShapeError onRetry={onRetryShape} />
            ) : isLoadingResolvedValue || isLoadingSkeleton ? (
              <ShapeLoading />
            ) : hasPreviewEntity ? (
              <Box
                data-testid="cer-shape-preview"
                style={{
                  padding: '8px',
                  background: 'var(--gray-2)',
                  borderRadius: 'var(--radius-2)',
                  minWidth: 0,
                  maxWidth: '100%',
                  overflowWrap: 'anywhere',
                }}
              >
                {previewError ? (
                  <Text size="1" color="gray">
                    Could not load preview values.
                  </Text>
                ) : previewResolvedValue === null ||
                  previewResolvedValue === undefined ? (
                  // With `hideRoot`, a bare `null` renders as an empty tree
                  // (a lone ":" row), so render it explicitly instead.
                  <Code size="1" variant="ghost" color="gray">
                    null
                  </Code>
                ) : (
                  <JSONTree
                    data={previewResolvedValue}
                    theme={jsonTreeThemeProp}
                    invertTheme={false}
                    hideRoot
                    // The payload only contains the fields the developer
                    // explicitly picked, so show every nested level upfront
                    // instead of hiding picked values behind collapsed nodes.
                    shouldExpandNodeInitially={() => true}
                    labelRenderer={([key]) => (
                      <CopyableText text={String(key)}>{key}:</CopyableText>
                    )}
                    valueRenderer={(valueAsString, value) => (
                      <CopyableText text={String(value)}>
                        {typeof valueAsString === 'string'
                          ? truncateForDisplay(valueAsString)
                          : String(valueAsString)}
                      </CopyableText>
                    )}
                  />
                )}
              </Box>
            ) : (
              <ShapeSkeleton bundle={bundle} skeleton={skeleton} />
            )}
            {!hasPreviewEntity && !isLoadingSkeleton && !isShapeError && (
              <Text size="1" color="gray" style={{ fontStyle: 'italic' }}>
                Approximate: select an entity above for real values. This prop
                is null when no entity is referenced.
              </Text>
            )}
          </Flex>
        </Flex>
      )}
    </Box>
  );
}

// Wraps a key or value so clicking it copies just that piece (e.g. the
// property name `alt`, ready to reference in code) rather than requiring a
// separate button or a longer path the developer doesn't need.
function CopyableText({
  text,
  children,
}: {
  text: string;
  children: ReactNode;
}) {
  const [copied, markCopied] = useCopiedFlag(1200);

  return (
    <span
      onClick={(event) => {
        event.stopPropagation();
        void navigator.clipboard.writeText(text).then(markCopied);
      }}
      title={copied ? 'Copied' : text}
      style={{
        cursor: 'pointer',
        // Copy feedback is a brief highlight on the copied text itself: it
        // occupies no extra space, so (unlike an adjacent icon) it cannot
        // overlap neighboring keys/values or shift layout in the JSON tree.
        backgroundColor: copied ? 'var(--green-a4)' : 'transparent',
        borderRadius: 'var(--radius-1)',
        transition: 'background-color 150ms ease-out',
      }}
    >
      {children}
    </span>
  );
}

function ShapeLoading() {
  return (
    <Flex
      align="center"
      gap="2"
      p="2"
      style={{
        background: 'var(--gray-2)',
        borderRadius: 'var(--radius-2)',
      }}
    >
      <Spinner size="1" />
      <Text size="1" color="gray">
        Loading…
      </Text>
    </Flex>
  );
}

function ShapeError({ onRetry }: { onRetry: () => void }) {
  return (
    <Flex
      align="center"
      justify="between"
      gap="2"
      p="2"
      style={{
        background: 'var(--gray-2)',
        borderRadius: 'var(--radius-2)',
      }}
    >
      <Text size="1" color="gray">
        Could not load the shape.
      </Text>
      <Button size="1" variant="ghost" color="gray" onClick={onRetry}>
        Retry
      </Button>
    </Flex>
  );
}

function ShapeSkeletonRow({
  fieldKey,
  type,
  depth,
}: {
  fieldKey: string;
  type: string;
  depth: number;
}) {
  return (
    <Flex
      align="center"
      justify="between"
      gap="2"
      style={{ paddingLeft: depth * 16 }}
    >
      <Code
        size="1"
        variant="ghost"
        color="gray"
        style={{
          minWidth: 0,
          overflow: 'hidden',
          textOverflow: 'ellipsis',
          whiteSpace: 'nowrap',
        }}
      >
        <CopyableText text={fieldKey}>{fieldKey}</CopyableText>
      </Code>
      <Badge color="blue" variant="soft" size="1" style={{ flexShrink: 0 }}>
        {type}
      </Badge>
    </Flex>
  );
}

function ShapeSkeleton({
  bundle,
  skeleton,
}: {
  bundle: string;
  skeleton: ShapeSkeletonNode[];
}) {
  return (
    <Flex
      data-testid="cer-shape-preview"
      direction="column"
      gap="1"
      p="2"
      style={{
        background: 'var(--gray-2)',
        borderRadius: 'var(--radius-2)',
        minWidth: 0,
      }}
    >
      <Flex align="center" gap="2">
        <Text size="1" color="gray">
          Type
        </Text>
        <Badge color="gray" variant="soft" size="1">
          {bundle}
        </Badge>
      </Flex>
      <ShapeSkeletonRows nodes={skeleton} depth={0} />
    </Flex>
  );
}

function ShapeSkeletonRows({
  nodes,
  depth,
}: {
  nodes: ShapeSkeletonNode[];
  depth: number;
}) {
  return (
    <>
      {nodes.map((node) => (
        <Flex key={node.key} direction="column" gap="1">
          <ShapeSkeletonRow
            fieldKey={node.key}
            type={node.type}
            depth={depth}
          />
          {node.children && (
            <ShapeSkeletonRows nodes={node.children} depth={depth + 1} />
          )}
        </Flex>
      ))}
    </>
  );
}

function EntityPreviewPicker({
  entityTypeId,
  bundle,
  previewEntity,
  onChange,
}: {
  entityTypeId: string;
  bundle: string;
  previewEntity: { entityTypeId: string; entityId: string } | null;
  onChange: (entityId: string | null) => void;
}) {
  const [searchInput, setSearchInput] = useState('');
  const debouncedSearch = useDebounce(searchInput, 300);
  // The server trims the search value and treats a whitespace-only one as "no
  // search", so trim here too: it keeps `foo` and `foo ` on a single cache key
  // and stops whitespace alone from being sent as a search at all.
  // @see \Drupal\canvas\Controller\ApiUiContentTemplateControllers::suggestPreviewContentEntities()
  const search = debouncedSearch.trim();
  const {
    data: suggestions,
    isFetching,
    isUninitialized,
    isError,
  } = useGetPreviewContentEntitiesQuery({
    entityTypeId,
    bundle,
    search: search || undefined,
  });
  // `isUninitialized` covers the brief window before the query dispatches on
  // mount, where `isFetching` is still false: without it, the picker flashes
  // "No entities available" before the loading state ever appears.
  const isPending = isFetching || isUninitialized;

  const options: EntityOption[] = useMemo(
    () =>
      Object.values(suggestions ?? {}).map((entity) => ({
        value: entity.id,
        label: entity.label,
      })),
    [suggestions],
  );

  // The suggestions list is only a top-10 sample, so the currently selected
  // entity may not be present in it. Remember its label locally once picked
  // so the field doesn't fall back to a bare ID after a search narrows or
  // clears the option list.
  const [selectedLabel, setSelectedLabel] = useState<string | null>(null);

  const selectedOption: EntityOption | null = previewEntity
    ? {
        value: previewEntity.entityId,
        label:
          options.find((option) => option.value === previewEntity.entityId)
            ?.label ??
          selectedLabel ??
          `#${previewEntity.entityId}`,
      }
    : null;

  const handleChange = (option: SingleValue<EntityOption>) => {
    setSelectedLabel(option?.label ?? null);
    // Clear the typed search text so react-select displays the selected
    // option's label (its single-value display only renders while the
    // controlled `inputValue` is empty) instead of leftover search input.
    setSearchInput('');
    onChange(option?.value ?? null);
  };

  return (
    <ReactSelect<EntityOption, false>
      styles={compactSelectStyles}
      isClearable
      isLoading={isPending}
      options={options}
      value={selectedOption}
      inputValue={searchInput}
      onInputChange={(nextValue, meta) => {
        if (meta.action === 'input-change') {
          setSearchInput(nextValue);
        } else if (
          meta.action === 'input-blur' ||
          meta.action === 'menu-close'
        ) {
          // react-select emits these with an empty value; a leftover
          // non-empty controlled `inputValue` would suppress the selected
          // option's single-value label indefinitely.
          setSearchInput('');
        }
      }}
      onChange={handleChange}
      placeholder="Search for an entity…"
      aria-label="Preview entity"
      noOptionsMessage={() =>
        isPending
          ? 'Loading…'
          : isError
            ? 'Could not load entities.'
            : search
              ? 'No matches'
              : 'No entities available'
      }
    />
  );
}
