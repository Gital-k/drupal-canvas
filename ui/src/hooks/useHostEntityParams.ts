import { useMemo } from 'react';
import { useLocation } from 'react-router-dom';

import { getHostEntityParams } from '@/services/baseQuery';

import type { HostEntityParams } from '@/services/baseQuery';

/**
 * The host entity currently being edited, derived reactively from the route.
 *
 * Used as the argument to `useGetComponentsQuery` so the component list is
 * cache-keyed by its host: the list is fetched again — and returns to a prior
 * host's cached result — as the editor navigates between hosts.
 *
 * Reactivity comes from `useLocation()`, not `useParams()`. The library panel
 * (`PrimaryPanel` → `Library` → `ComponentList`) lives in `UiShell`, which each
 * route renders at the same position, so React preserves those instances across
 * a page ↔ template navigation instead of remounting them. `useParams()` reads
 * the nested route-match context, which is not refreshed for such a preserved
 * subtree — leaving an open "Add" tray showing the previous host's list until it
 * is closed and reopened. `useLocation()` reads the single top-level location
 * context at the router root, which updates on every navigation and re-renders
 * all consumers regardless of instance preservation.
 *
 * Returns an empty object when the route has no content-entity host (the
 * pattern editor, or region editing), so the caller receives the global list.
 */
export const useHostEntityParams = (): HostEntityParams => {
  const { pathname } = useLocation();
  return useMemo(() => getHostEntityParams(pathname), [pathname]);
};
