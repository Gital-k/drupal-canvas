import { createContext, useContext, useState } from 'react';

import type { ReactNode } from 'react';

interface AncestorHighlightContextValue {
  highlightedAncestorUuid: string | undefined;
  setHighlightedAncestor: (uuid: string) => void;
  unsetHighlightedAncestor: () => void;
}

const AncestorHighlightContext = createContext<AncestorHighlightContextValue>({
  highlightedAncestorUuid: undefined,
  setHighlightedAncestor: () => {},
  unsetHighlightedAncestor: () => {},
});

export function AncestorHighlightProvider({
  children,
}: {
  children: ReactNode;
}) {
  const [highlightedAncestorUuid, setHighlightedAncestorUuid] = useState<
    string | undefined
  >(undefined);
  return (
    <AncestorHighlightContext.Provider
      value={{
        highlightedAncestorUuid,
        setHighlightedAncestor: setHighlightedAncestorUuid,
        unsetHighlightedAncestor: () => setHighlightedAncestorUuid(undefined),
      }}
    >
      {children}
    </AncestorHighlightContext.Provider>
  );
}

export function useAncestorHighlight() {
  return useContext(AncestorHighlightContext);
}
