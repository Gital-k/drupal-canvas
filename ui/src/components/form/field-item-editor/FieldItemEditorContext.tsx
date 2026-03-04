import { createContext, useContext, useState } from 'react';

import type { ReactNode } from 'react';

interface FieldItemEditorContextValue {
  selectedIndex: number | null;
  setSelectedIndex: (index: number | null) => void;
  fieldName: string;
}

const FieldItemEditorContext =
  createContext<FieldItemEditorContextValue | null>(null);

export function useFieldItemEditorContext() {
  return useContext(FieldItemEditorContext);
}

interface FieldItemEditorProviderProps {
  fieldName: string;
  children: ReactNode;
}

export function FieldItemEditorProvider({
  fieldName,
  children,
}: FieldItemEditorProviderProps) {
  const [selectedIndex, setSelectedIndex] = useState<number | null>(null);

  return (
    <FieldItemEditorContext.Provider
      value={{ selectedIndex, setSelectedIndex, fieldName }}
    >
      {children}
    </FieldItemEditorContext.Provider>
  );
}
