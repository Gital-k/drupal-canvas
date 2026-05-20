import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { toast } from 'sonner';
import {
  Badge,
  Box,
  Button,
  Flex,
  Heading,
  Tabs,
  Text,
  TextField,
} from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  loadForm,
  markSaved,
  setLabel,
  setSaving,
} from '@/features/formBuilder/formBuilderSlice';
import {
  selectElements,
  selectFormId,
  selectIsDirty,
  selectIsSaving,
  selectLabel,
  selectSubmitSettings,
} from '@/features/formBuilder/selectors';
import {
  useGetAutoSaveQuery,
  useGetFormQuery,
  useGetSchemasQuery,
  useUpdateFormMutation,
} from '@/services/canvasFormApi';
import { getDrupalSettings } from '@/utils/drupal-globals';

import { FormBuilderDragHandler } from './FormBuilderDragHandler';
import { FormCanvas } from './FormCanvas';
import { FormPropertyPanel } from './FormPropertyPanel';
import { FormSubmissions } from './FormSubmissions';
import { FormElementPalette } from './palette/FormElementPalette';
import { useFormBuilderAutoSave } from './useFormBuilderAutoSave';

import type {
  FormElement,
  FormElementSchema,
  SubmitSettings,
} from '@/features/formBuilder/types';

function extractErrorMessage(err: unknown): string {
  if (err && typeof err === 'object') {
    const e = err as Record<string, unknown>;
    if (typeof e.data === 'object' && e.data !== null) {
      const data = e.data as Record<string, unknown>;
      if (typeof data.message === 'string') return data.message;
    }
    if (typeof e.message === 'string') return e.message;
    if (typeof e.status === 'number') return `HTTP ${e.status}`;
  }
  return 'Unknown error';
}

export function FormBuilderApp() {
  const dispatch = useAppDispatch();
  const { formId: formIdFromUrl } = useParams<{ formId: string }>();
  const label = useAppSelector(selectLabel);
  const isDirty = useAppSelector(selectIsDirty);
  const isSaving = useAppSelector(selectIsSaving);
  const formId = useAppSelector(selectFormId);
  const elements = useAppSelector(selectElements);
  const submitSettings = useAppSelector(selectSubmitSettings);

  const [updateForm] = useUpdateFormMutation();
  const [activeTab, setActiveTab] = useState<'build' | 'submissions'>('build');
  const [editingLabel, setEditingLabel] = useState(false);

  useFormBuilderAutoSave();

  const drupalSettings = getDrupalSettings();
  const formBuilderSettings = drupalSettings?.canvasFormBuilder as
    | {
        formId: string;
        formData: {
          id: string;
          name: string;
          formElements: FormElement[];
          submitSettings: SubmitSettings;
        };
        elementSchemas: Record<string, FormElementSchema>;
      }
    | undefined;

  const activeFormId = formIdFromUrl ?? formBuilderSettings?.formId ?? '';

  const settingsSchemas = formBuilderSettings?.elementSchemas;
  const { data: fetchedSchemas } = useGetSchemasQuery(undefined, {
    skip: !!settingsSchemas,
  });
  const schemas = (settingsSchemas ?? fetchedSchemas ?? {}) as Record<
    string,
    FormElementSchema
  >;

  const { data: liveForm, isSuccess: liveFormLoaded } = useGetFormQuery(
    activeFormId,
    {
      skip: !activeFormId,
    },
  );

  const { data: autoSaveData, isSuccess: autoSaveLoaded } = useGetAutoSaveQuery(
    activeFormId,
    {
      skip: !activeFormId,
    },
  );

  useEffect(() => {
    if (!activeFormId || !liveFormLoaded || !autoSaveLoaded) return;

    const draft = autoSaveData?.data;
    const source = draft ?? liveForm!;

    dispatch(
      loadForm({
        formId: activeFormId,
        label: source.name,
        elements: (source.formElements as FormElement[]) ?? [],
        submitSettings: source.submitSettings as SubmitSettings,
      }),
    );

    if (draft) {
      toast.info('Unsaved draft restored.');
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeFormId, liveFormLoaded, autoSaveLoaded]);

  const handlePublish = async () => {
    if (!formId) return;
    dispatch(setSaving(true));
    const toastId = toast.loading('Publishing…');
    try {
      await updateForm({
        id: formId,
        name: label,
        formElements: elements,
        submitSettings,
      }).unwrap();
      dispatch(markSaved());
      toast.success('Form published', { id: toastId });
    } catch (err: unknown) {
      dispatch(setSaving(false));
      toast.error(`Publish failed: ${extractErrorMessage(err)}`, {
        id: toastId,
        duration: 8000,
      });
    }
  };

  return (
    <Flex
      data-testid="form-builder"
      direction="column"
      style={{ position: 'absolute', inset: 0, overflow: 'hidden' }}
    >
      <Flex
        data-testid="form-builder-toolbar"
        align="center"
        gap="3"
        px="3"
        style={{
          height: 48,
          borderBottom: '1px solid var(--gray-5)',
          background: 'var(--color-background)',
          flexShrink: 0,
        }}
      >
        {editingLabel ? (
          <TextField.Root
            autoFocus
            value={label}
            size="1"
            onChange={(e) => dispatch(setLabel(e.target.value))}
            onBlur={() => setEditingLabel(false)}
            onKeyDown={(e) => e.key === 'Enter' && setEditingLabel(false)}
          />
        ) : (
          <Heading
            size="3"
            style={{ cursor: 'pointer' }}
            onClick={() => setEditingLabel(true)}
          >
            {label || 'Untitled form'}
          </Heading>
        )}

        {isSaving && (
          <Text size="1" color="gray">
            Saving draft…
          </Text>
        )}
        {!isSaving && !isDirty && (
          <Text size="1" color="gray">
            Draft saved
          </Text>
        )}
        {!isSaving && isDirty && (
          <Badge color="orange" size="1">
            Unsaved
          </Badge>
        )}

        <Box ml="auto">
          <Tabs.Root
            value={activeTab}
            onValueChange={(v) => setActiveTab(v as 'build' | 'submissions')}
          >
            <Tabs.List>
              <Tabs.Trigger value="build">Build</Tabs.Trigger>
              <Tabs.Trigger value="submissions">Submissions</Tabs.Trigger>
            </Tabs.List>
          </Tabs.Root>
        </Box>

        <Button
          size="1"
          variant="solid"
          disabled={isSaving}
          onClick={handlePublish}
        >
          Publish
        </Button>
      </Flex>

      <Flex
        data-testid="form-builder-body"
        style={{ flex: 1, overflow: 'hidden' }}
      >
        {activeTab === 'build' ? (
          <>
            <FormBuilderDragHandler schemas={schemas} />
            <FormElementPalette schemas={schemas} />
            <FormCanvas schemas={schemas} />
            <Box
              data-testid="form-builder-properties"
              style={{
                width: 260,
                borderLeft: '1px solid var(--gray-5)',
                flexShrink: 0,
                overflow: 'hidden',
                display: 'flex',
                flexDirection: 'column',
              }}
            >
              <FormPropertyPanel schemas={schemas} />
            </Box>
          </>
        ) : (
          <FormSubmissions formId={activeFormId} formLabel={label} />
        )}
      </Flex>
    </Flex>
  );
}
