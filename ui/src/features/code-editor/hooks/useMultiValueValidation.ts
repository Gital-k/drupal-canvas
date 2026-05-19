import { useEffect, useState } from 'react';

import { VALUE_MODE_LIMITED } from '@/types/CodeComponent';

import type { ValueMode } from '@/types/CodeComponent';

export function useMultiValueValidation<T>(
  displayArray: T[],
  validateFn: (value: T) => boolean,
  valueMode: ValueMode,
  limitedCount: number,
) {
  const [validityStates, setValidityStates] = useState<boolean[]>(() =>
    displayArray.map((v) => validateFn(v)),
  );

  useEffect(() => {
    setValidityStates((prev) => {
      const newLength = displayArray.length;
      if (prev.length === newLength) return prev;
      if (newLength > prev.length) {
        return [...prev, ...Array(newLength - prev.length).fill(true)];
      }
      return prev.slice(0, newLength);
    });
  }, [displayArray.length]);

  useEffect(() => {
    if (
      valueMode === VALUE_MODE_LIMITED &&
      limitedCount > validityStates.length
    ) {
      setValidityStates((prev) => {
        const next = [...prev];
        while (next.length < limitedCount) {
          next.push(true);
        }
        return next;
      });
    }
  }, [limitedCount, valueMode, validityStates.length]);

  const setValidity = (index: number, valid: boolean) => {
    setValidityStates((prev) => {
      const next = [...prev];
      next[index] = valid;
      return next;
    });
  };

  const resetValidityStates = (states?: boolean[]) => {
    setValidityStates(states ?? [true]);
  };

  return {
    validityStates,
    setValidity,
    resetValidityStates,
  };
}
