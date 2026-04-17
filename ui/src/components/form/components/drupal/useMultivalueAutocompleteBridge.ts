import { useEffect } from 'react';

import {
  dispatchSyntheticChange,
  toAutocompleteEntityUri,
} from './multivalueFormUtils';

import type { NumericInputAttributes } from '@/types/DrupalAttribute';

export type AutocompleteBridgeRefs = {
  popoverInput: HTMLInputElement | null;
  popoverContainer: HTMLDivElement | null;
  shouldRevertOnClose: boolean;
  pendingSyntheticCommit: string | null;
  closeAfterSyntheticCommit: boolean;
  autocompleteCleanup: (() => void) | null;
  autocompleteJustSelected: boolean;
};

type UseMultivalueAutocompleteBridgeParams = {
  attributes: NumericInputAttributes & {
    onChange?: (e: React.ChangeEvent<HTMLInputElement>) => void;
    value?: string;
    name?: string;
    id?: string;
    'data-field-label'?: string;
  };
  refs: React.MutableRefObject<AutocompleteBridgeRefs>;
  updatePreview: boolean;
  setDisplayValue: React.Dispatch<React.SetStateAction<string>>;
  setPopoverOpenAndRefocus: (open: boolean) => void;
  resumePreviewUpdates: () => void;
};

export function useMultivalueAutocompleteBridge({
  attributes,
  refs,
  updatePreview,
  setDisplayValue,
  setPopoverOpenAndRefocus,
  resumePreviewUpdates,
}: UseMultivalueAutocompleteBridgeParams): {
  attachSelectionListener: () => void;
} {
  const triggerValidationOnChange = (input: HTMLInputElement) => {
    if (typeof attributes.onChange !== 'function') {
      return;
    }
    const changeEvent = new Event('change');
    Object.defineProperty(changeEvent, 'target', {
      writable: false,
      value: input,
    });
    attributes.onChange(
      changeEvent as unknown as React.ChangeEvent<HTMLInputElement>,
    );
  };

  const hasInvalidValueInPopover = (): boolean =>
    !!refs.current.popoverContainer?.querySelector(
      '[data-invalid-prop-value="true"], [data-has-field-error="true"]',
    );

  const retryValidationIfNeeded = (
    committedValue: string,
    attemptsLeft = 4,
  ): void => {
    if (!refs.current.popoverInput || attemptsLeft <= 0) {
      return;
    }
    if (!hasInvalidValueInPopover()) {
      return;
    }
    setTimeout(() => {
      if (!refs.current.popoverInput || !hasInvalidValueInPopover()) {
        return;
      }
      refs.current.popoverInput.setAttribute(
        'data-canvas-force-update',
        'true',
      );
      dispatchSyntheticChange(refs.current.popoverInput, committedValue);
      triggerValidationOnChange(refs.current.popoverInput);
      retryValidationIfNeeded(committedValue, attemptsLeft - 1);
    }, 120);
  };

  useEffect(() => {
    if (!updatePreview) return;
    const pendingValue = refs.current.pendingSyntheticCommit;
    if (!pendingValue || !refs.current.popoverInput) return;
    refs.current.pendingSyntheticCommit = null;
    const closeAfterCommit = refs.current.closeAfterSyntheticCommit;
    refs.current.closeAfterSyntheticCommit = false;

    // Wait one tick so wrapped input handlers observe latest preview flag.
    setTimeout(() => {
      if (refs.current.popoverInput) {
        refs.current.popoverInput.setAttribute(
          'data-canvas-force-update',
          'true',
        );
        dispatchSyntheticChange(refs.current.popoverInput, pendingValue);
        triggerValidationOnChange(refs.current.popoverInput);

        // Autocomplete can mutate the input value shortly after selection
        // without firing change; trigger one follow-up change for validators.
        setTimeout(() => {
          if (!refs.current.popoverInput) {
            return;
          }
          const latestValue = refs.current.popoverInput.value;
          const latestCommittedValue = toAutocompleteEntityUri(latestValue);
          if (latestValue !== pendingValue || hasInvalidValueInPopover()) {
            refs.current.popoverInput.setAttribute(
              'data-canvas-force-update',
              'true',
            );
            dispatchSyntheticChange(
              refs.current.popoverInput,
              latestCommittedValue,
            );
            triggerValidationOnChange(refs.current.popoverInput);
            retryValidationIfNeeded(latestCommittedValue);
          }
          if (closeAfterCommit) {
            if (hasInvalidValueInPopover()) return;
            setPopoverOpenAndRefocus(false);
          }
        }, 80);
      }
    }, 0);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [updatePreview]);

  const attachSelectionListener = () => {
    if (!refs.current.popoverContainer) {
      return;
    }
    const handleSelected = (e: Event) => {
      if (!refs.current.popoverContainer?.contains(e.target as Node)) return;
      // Use the custom event detail as the source of truth for the
      // chosen suggestion text; relying on input.value here can race.
      const selectedLabelValue =
        (e as CustomEvent<{ value: string }>).detail?.value ||
        (e.target instanceof HTMLInputElement ? e.target.value : '');
      const committedValue = toAutocompleteEntityUri(selectedLabelValue);

      refs.current.autocompleteJustSelected = true;
      // Keep the compact row text user-friendly while committing
      // transform-ready value to form state.
      setDisplayValue(selectedLabelValue);
      refs.current.shouldRevertOnClose = false;
      refs.current.pendingSyntheticCommit = committedValue;
      refs.current.closeAfterSyntheticCommit = true;

      // Commit immediately so the transformed value persists to form state
      // and preview, then keep deferred flow as a safety net for delayed
      // autocomplete mutations.
      if (refs.current.popoverInput) {
        refs.current.popoverInput.setAttribute(
          'data-canvas-selected-committed-value',
          committedValue,
        );
        refs.current.popoverInput.setAttribute(
          'data-canvas-force-update',
          'true',
        );
        dispatchSyntheticChange(refs.current.popoverInput, committedValue);
        triggerValidationOnChange(refs.current.popoverInput);
      }

      resumePreviewUpdates();
    };

    document.addEventListener(
      'data-canvas-autocomplete-selected',
      handleSelected,
    );
    refs.current.autocompleteCleanup = () =>
      document.removeEventListener(
        'data-canvas-autocomplete-selected',
        handleSelected,
      );
  };

  return { attachSelectionListener };
}
