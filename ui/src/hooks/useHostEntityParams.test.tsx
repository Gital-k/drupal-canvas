import { MemoryRouter, useNavigate } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { act, renderHook } from '@testing-library/react';

import { useHostEntityParams } from './useHostEntityParams';

/**
 * Renders `useHostEntityParams` alongside `useNavigate` under a single
 * `MemoryRouter`, so navigation happens on the SAME mounted hook instance — no
 * remount. This reproduces the real bug: the "Add" component tray stays mounted
 * (it lives in `UiShell`, rendered at a stable position by every route) while
 * the editor navigates between hosts, so the host must update without a remount.
 */
const renderHostHook = (initialPath: string) =>
  renderHook(() => ({ host: useHostEntityParams(), navigate: useNavigate() }), {
    wrapper: ({ children }) => (
      <MemoryRouter initialEntries={[initialPath]}>{children}</MemoryRouter>
    ),
  });

describe('useHostEntityParams', () => {
  it('derives the page host from the route', () => {
    const { result } = renderHostHook('/editor/canvas_page/1');
    expect(result.current.host).toEqual({ entityType: 'canvas_page' });
  });

  it('derives the template host (type + bundle) from the route', () => {
    const { result } = renderHostHook('/template/node/article/full/2');
    expect(result.current.host).toEqual({
      entityType: 'node',
      entityBundle: 'article',
    });
  });

  it('returns an empty host for the pattern editor', () => {
    const { result } = renderHostHook('/pattern/123');
    expect(result.current.host).toEqual({});
  });

  // The regression guard: the host must re-derive on navigation for a persistent
  // hook instance (the open tray). This fails if the host is sourced from
  // `useParams()` (stale for a preserved subtree) instead of `useLocation()`.
  it('updates the host when navigating between hosts without remounting', () => {
    const { result } = renderHostHook('/editor/canvas_page/1');
    expect(result.current.host).toEqual({ entityType: 'canvas_page' });

    act(() => {
      result.current.navigate('/template/node/article/full/2');
    });
    expect(result.current.host).toEqual({
      entityType: 'node',
      entityBundle: 'article',
    });

    // Navigating back to the page must return to the page host, not stay on the
    // template host — this is the exact "still shows the node list" bug.
    act(() => {
      result.current.navigate('/editor/canvas_page/1');
    });
    expect(result.current.host).toEqual({ entityType: 'canvas_page' });
  });
});
