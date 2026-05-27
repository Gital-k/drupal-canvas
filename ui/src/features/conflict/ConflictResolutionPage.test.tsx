import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import ConflictResolutionPage from './ConflictResolutionPage';

import type { ReactNode } from 'react';
import type { PendingChanges } from '@/services/pendingChangesApi';

const mocks = vi.hoisted(() => ({
  dispatch: vi.fn(),
  discardChange: vi.fn(),
  devMode: true,
  invalidateContent: vi.fn(),
  invalidateLayout: vi.fn(),
  layoutRequests: vi.fn(),
  refetch: vi.fn(),
  resolveConflict: vi.fn(),
}));

vi.mock('@/app/hooks', () => ({
  useAppDispatch: () => mocks.dispatch,
}));

vi.mock('@/utils/drupal-globals', () => ({
  getCanvasSettings: () => ({ devMode: mocks.devMode }),
}));

vi.mock('@/services/componentAndLayout', () => ({
  componentAndLayoutApi: {
    util: {
      invalidateTags: mocks.invalidateLayout,
    },
  },
  useGetConflictPageLayoutQuery: (arg: { publishedVersion?: boolean }) => {
    mocks.layoutRequests(arg);
    return {
      data: {
        html: arg.publishedVersion
          ? '<main>Published version content</main>'
          : '<main>New version content</main>',
        entity_form_fields: {},
        layout: [],
        model: {},
      },
      isFetching: false,
      isError: false,
    };
  },
}));

vi.mock('@/services/content', () => ({
  contentApi: {
    util: {
      invalidateTags: mocks.invalidateContent,
    },
  },
  useUpdateContentMutation: () => [
    (change: unknown) => ({
      unwrap: () => mocks.resolveConflict(change),
    }),
    { isLoading: false },
  ],
}));

const pendingChanges: PendingChanges = {
  'canvas_page:1:en': {
    owner: {
      name: 'Builder',
      avatar: null,
      uri: '/user/1',
      id: 1,
    },
    entity_type: 'canvas_page',
    entity_id: '1',
    data_hash: 'draft-hash',
    langcode: 'en',
    label: 'Homepage',
    updated: 100,
    hasConflict: true,
    conflict: {
      conflict_id: 'revision-2',
      timestamp: 1776892400,
    },
  },
};

vi.mock('@/services/pendingChangesApi', () => ({
  useGetAllPendingChangesQuery: () => ({
    data: pendingChanges,
    refetch: mocks.refetch,
  }),
  useDiscardPendingChangeMutation: () => [
    (change: unknown) => ({
      unwrap: () => mocks.discardChange(change),
    }),
    { isLoading: false },
  ],
}));

vi.mock('./ConflictResolutionView', () => ({
  ConflictResolutionView: ({
    comparison,
    onUsePublishedVersion,
    onUseNewVersion,
  }: {
    comparison: ReactNode;
    onUsePublishedVersion: () => void;
    onUseNewVersion: () => void;
  }) => (
    <div>
      {comparison}
      <button type="button" onClick={onUsePublishedVersion}>
        Use published version
      </button>
      <button type="button" onClick={onUseNewVersion}>
        Use new version
      </button>
    </div>
  ),
  PageConflictComparisonView: ({
    publishedVersion,
    newVersion,
  }: {
    publishedVersion: { html: string };
    newVersion: { html: string };
  }) => (
    <div>
      <span>{publishedVersion.html}</span>
      <span>{newVersion.html}</span>
    </div>
  ),
}));

const renderPage = () =>
  render(
    <MemoryRouter
      initialEntries={['/conflict/canvas_page/1']}
      future={{ v7_relativeSplatPath: true, v7_startTransition: true }}
    >
      <Routes>
        <Route
          path="/conflict/:entityType/:entityId"
          element={<ConflictResolutionPage />}
        />
        <Route path="/conflict" element={<div>Conflict queue complete</div>} />
        <Route path="/editor" element={<div>Editor</div>} />
      </Routes>
    </MemoryRouter>,
  );

describe('ConflictResolutionPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.devMode = true;
    mocks.discardChange.mockResolvedValue({ message: 'Discarded' });
    mocks.resolveConflict.mockResolvedValue(undefined);
    mocks.refetch.mockResolvedValue(undefined);
  });

  it('requests the auto-save and published Page previews in parallel view state', () => {
    renderPage();

    expect(mocks.layoutRequests).toHaveBeenCalledWith({
      entityId: '1',
      entityType: 'canvas_page',
    });
    expect(mocks.layoutRequests).toHaveBeenCalledWith({
      entityId: '1',
      entityType: 'canvas_page',
      publishedVersion: true,
    });
    expect(screen.getByText(/Published version content/)).toBeInTheDocument();
    expect(screen.getByText(/New version content/)).toBeInTheDocument();
  });

  it('discards the new Page version when selecting the published version', async () => {
    const user = userEvent.setup();
    renderPage();

    await user.click(
      screen.getByRole('button', { name: 'Use published version' }),
    );

    await waitFor(() => {
      expect(mocks.discardChange).toHaveBeenCalledWith(
        expect.objectContaining({
          pointer: 'canvas_page:1:en',
          entity_type: 'canvas_page',
          entity_id: '1',
        }),
      );
    });
    expect(mocks.invalidateLayout).toHaveBeenCalledWith([{ type: 'Layout' }]);
    expect(mocks.invalidateContent).toHaveBeenCalledWith([
      { type: 'Content', id: 'LIST' },
    ]);
    expect(await screen.findByText('Conflict queue complete')).toBeVisible();
  });

  it('uses conflictToResolve to keep the new Page version', async () => {
    const user = userEvent.setup();
    renderPage();

    await user.click(screen.getByRole('button', { name: 'Use new version' }));

    await waitFor(() => {
      expect(mocks.resolveConflict).toHaveBeenCalledWith({
        entityType: 'canvas_page',
        entityId: '1',
        conflictToResolve: 'revision-2',
      });
    });
    expect(mocks.discardChange).not.toHaveBeenCalled();
    expect(mocks.invalidateLayout).toHaveBeenCalledWith([{ type: 'Layout' }]);
    expect(mocks.invalidateContent).toHaveBeenCalledWith([
      { type: 'Content', id: 'LIST' },
    ]);
    expect(await screen.findByText('Conflict queue complete')).toBeVisible();
  });

  it('does not expose the comparison route outside dev mode', async () => {
    mocks.devMode = false;

    renderPage();

    expect(await screen.findByText('Editor')).toBeVisible();
    expect(mocks.layoutRequests).not.toHaveBeenCalled();
  });
});
