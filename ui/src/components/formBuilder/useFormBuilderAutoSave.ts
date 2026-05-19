import { useEffect, useRef } from 'react';
import { toast } from 'sonner';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  addOrUpdateAutoSavesHash,
  selectAutoSavesHash,
  selectClientInstanceId,
} from '@/components/review/PublishReview.slice';
import { markSaved, setSaving } from '@/features/formBuilder/formBuilderSlice';
import {
  selectElements,
  selectFormId,
  selectIsDirty,
  selectLabel,
  selectSubmitSettings,
} from '@/features/formBuilder/selectors';
import { useGetAutoSaveQuery } from '@/services/canvasFormApi';
import { getBaseUrl } from '@/utils/drupal-globals';

import type { AppDispatch } from '@/app/store';
import type { AutoSavesHash } from '@/types/AutoSaves';

const AUTOSAVE_DELAY_MS = 2000;
const AUTO_SAVE_BASE = 'canvas/api/v0/config/auto-save/canvas_form';

async function patchAutoSave(
  formId: string,
  body: Record<string, unknown>,
  dispatch: AppDispatch,
) {
  const baseUrl = getBaseUrl() ?? '/';
  const csrfResponse = await fetch(`${baseUrl}session/token`);
  const csrfToken = await csrfResponse.text();

  const url = `${baseUrl}${AUTO_SAVE_BASE}/${formId}`;
  const response = await fetch(url, {
    method: 'PATCH',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrfToken,
    },
    body: JSON.stringify(body),
  });

  if (!response.ok) {
    const text = await response.text();
    throw new Error(`HTTP ${response.status}: ${text}`);
  }

  const data = (await response.json()) as { autoSaves?: AutoSavesHash };
  if (data.autoSaves) {
    const relativeUrl = `${AUTO_SAVE_BASE}/${formId}`;
    dispatch(addOrUpdateAutoSavesHash({ [relativeUrl]: data.autoSaves }));
  }
}

export function useFormBuilderAutoSave() {
  const dispatch = useAppDispatch();
  const formId = useAppSelector(selectFormId);
  const isDirty = useAppSelector(selectIsDirty);
  const label = useAppSelector(selectLabel);
  const elements = useAppSelector(selectElements);
  const submitSettings = useAppSelector(selectSubmitSettings);
  const autoSavesHash = useAppSelector(selectAutoSavesHash);
  const clientInstanceId = useAppSelector(selectClientInstanceId);

  // Only allow autosave once the GET has resolved and seeded the hash.
  const { isSuccess: hashReady } = useGetAutoSaveQuery(formId ?? '', {
    skip: !formId,
  });

  const labelRef = useRef(label);
  const elementsRef = useRef(elements);
  const submitSettingsRef = useRef(submitSettings);
  const formIdRef = useRef(formId);
  const isDirtyRef = useRef(isDirty);
  const hashReadyRef = useRef(hashReady);
  const autoSavesHashRef = useRef(autoSavesHash);
  const clientInstanceIdRef = useRef(clientInstanceId);

  useEffect(() => {
    labelRef.current = label;
  }, [label]);
  useEffect(() => {
    elementsRef.current = elements;
  }, [elements]);
  useEffect(() => {
    submitSettingsRef.current = submitSettings;
  }, [submitSettings]);
  useEffect(() => {
    formIdRef.current = formId;
  }, [formId]);
  useEffect(() => {
    isDirtyRef.current = isDirty;
  }, [isDirty]);
  useEffect(() => {
    hashReadyRef.current = hashReady;
  }, [hashReady]);
  useEffect(() => {
    autoSavesHashRef.current = autoSavesHash;
  }, [autoSavesHash]);
  useEffect(() => {
    clientInstanceIdRef.current = clientInstanceId;
  }, [clientInstanceId]);

  const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    if (!isDirty) return;

    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current);
    }

    timeoutRef.current = setTimeout(async () => {
      if (!isDirtyRef.current || !formIdRef.current || !hashReadyRef.current)
        return;

      const id = formIdRef.current;
      const hashKey = `${AUTO_SAVE_BASE}/${id}`;
      const autoSaves = autoSavesHashRef.current?.[hashKey] ?? {};

      dispatch(setSaving(true));
      try {
        await patchAutoSave(
          id,
          {
            data: {
              name: labelRef.current,
              formElements: elementsRef.current,
              submitSettings: submitSettingsRef.current,
            },
            autoSaves,
            clientInstanceId: clientInstanceIdRef.current,
          },
          dispatch,
        );
        dispatch(markSaved());
      } catch (err: unknown) {
        dispatch(setSaving(false));
        toast.error(`Draft save failed: ${extractErrorMessage(err)}`, {
          duration: 8000,
        });
      }
    }, AUTOSAVE_DELAY_MS);

    return () => {
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current);
      }
    };
  }, [isDirty, elements, label, submitSettings, dispatch]);

  useEffect(() => {
    const handler = (e: BeforeUnloadEvent) => {
      if (isDirtyRef.current) {
        e.preventDefault();
      }
    };
    window.addEventListener('beforeunload', handler);
    return () => window.removeEventListener('beforeunload', handler);
  }, []);
}

function extractErrorMessage(err: unknown): string {
  if (err && typeof err === 'object') {
    const e = err as Record<string, unknown>;
    if (typeof e.message === 'string') return e.message;
  }
  return 'Unknown error';
}
