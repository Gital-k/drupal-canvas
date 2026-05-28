import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import ConflictResolutionPage from './ConflictResolutionPage';

import type { ReactNode } from 'react';
import type { PendingChanges } from '@/services/pendingChangesApi';

const mocks = vi.hoisted(() => ({
  dispatch: vi.fn(),
  devMode: true,
  invalidateContent: vi.fn(),
  invalidateLayout: vi.fn(),
  layoutRequests: vi.fn(),
  pendingChanges: undefined as PendingChanges | undefined,
  refetch: vi.fn(),
  discardChange: vi.fn(),
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
  useGetConflictPageLayoutQuery: (arg: {
    entityId: string;
    publishedVersion?: boolean;
  }) => {
    mocks.layoutRequests(arg);
    return {
      data: {
        html: arg.publishedVersion
          ? `<main>Published version ${arg.entityId} content</main>`
          : `<main>New version ${arg.entityId} content</main>`,
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
  'canvas_page:2:en': {
    owner: {
      name: 'Builder',
      avatar: null,
      uri: '/user/1',
      id: 1,
    },
    entity_type: 'canvas_page',
    entity_id: '2',
    data_hash: 'second-draft-hash',
    langcode: 'en',
    label: 'About',
    updated: 50,
    hasConflict: true,
    conflict: {
      conflict_id: 'revision-3',
      timestamp: 1776892500,
    },
  },
};

vi.mock('@/services/pendingChangesApi', () => ({
  useGetAllPendingChangesQuery: () => ({
    data: mocks.pendingChanges,
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
    canResolveConflict,
    onNext,
    onResolveConflict,
  }: {
    comparison: ReactNode;
    canResolveConflict: boolean;
    onNext: () => void;
    onResolveConflict: () => void;
  }) => (
    <div>
      {comparison}
      <button type="button" onClick={onNext}>
        Next
      </button>
      <button
        type="button"
        onClick={onResolveConflict}
        disabled={!canResolveConflict}
      >
        Resolve conflict
      </button>
    </div>
  ),
  PageConflictComparisonView: ({
    publishedVersion,
    newVersion,
    selectedVersion,
    onSelectVersion,
  }: {
    publishedVersion: { html: string };
    newVersion: { html: string };
    selectedVersion?: 'published' | 'new';
    onSelectVersion?: (version: 'published' | 'new') => void;
  }) => (
    <div>
      <button
        type="button"
        aria-pressed={selectedVersion === 'published'}
        onClick={() => onSelectVersion?.('published')}
      >
        Select published version
      </button>
      <button
        type="button"
        aria-pressed={selectedVersion === 'new'}
        onClick={() => onSelectVersion?.('new')}
      >
        Select new version
      </button>
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
    mocks.pendingChanges = pendingChanges;
    mocks.discardChange.mockResolvedValue(undefined);
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
    expect(screen.getByText(/Published version 1 content/)).toBeInTheDocument();
    expect(screen.getByText(/New version 1 content/)).toBeInTheDocument();
  });

  it('starts with no selected version and disables resolution', () => {
    renderPage();

    expect(
      screen.getByRole('button', { name: 'Resolve conflict' }),
    ).toBeDisabled();
    expect(
      screen.getByRole('button', { name: 'Select published version' }),
    ).toHaveAttribute('aria-pressed', 'false');
    expect(
      screen.getByRole('button', { name: 'Select new version' }),
    ).toHaveAttribute('aria-pressed', 'false');
  });

  it('keeps a single Resolve conflict action in the footer', () => {
    renderPage();

    expect(
      screen.getByRole('button', { name: 'Resolve conflict' }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: 'Use published version' }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: 'Use new version' }),
    ).not.toBeInTheDocument();
  });

  it('uses conflictToResolve when resolving with the new version selected', async () => {
    const user = userEvent.setup();
    renderPage();

    await user.click(
      screen.getByRole('button', { name: 'Select new version' }),
    );
    await user.click(screen.getByRole('button', { name: 'Resolve conflict' }));

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
    expect(mocks.refetch).toHaveBeenCalled();
  });

  it('discards the auto-save when resolving with the published version selected', async () => {
    const user = userEvent.setup();
    renderPage();

    await user.click(
      screen.getByRole('button', { name: 'Select published version' }),
    );
    await user.click(screen.getByRole('button', { name: 'Resolve conflict' }));

    await waitFor(() => {
      expect(mocks.discardChange).toHaveBeenCalledWith({
        ...pendingChanges['canvas_page:1:en'],
        pointer: 'canvas_page:1:en',
      });
    });
    expect(mocks.resolveConflict).not.toHaveBeenCalled();
    expect(mocks.invalidateLayout).toHaveBeenCalledWith([{ type: 'Layout' }]);
    expect(mocks.invalidateContent).toHaveBeenCalledWith([
      { type: 'Content', id: 'LIST' },
    ]);
    expect(mocks.refetch).toHaveBeenCalled();
  });

  it('deselects the selected version when it is clicked again', async () => {
    const user = userEvent.setup();
    renderPage();

    const selectNew = screen.getByRole('button', {
      name: 'Select new version',
    });
    const resolve = screen.getByRole('button', { name: 'Resolve conflict' });

    await user.click(selectNew);

    expect(selectNew).toHaveAttribute('aria-pressed', 'true');
    expect(resolve).toBeEnabled();

    await user.click(selectNew);

    expect(selectNew).toHaveAttribute('aria-pressed', 'false');
    expect(resolve).toBeDisabled();
  });

  it('resets selected version after moving to another conflict', async () => {
    const user = userEvent.setup();
    renderPage();

    await user.click(
      screen.getByRole('button', { name: 'Select new version' }),
    );
    expect(
      screen.getByRole('button', { name: 'Resolve conflict' }),
    ).toBeEnabled();

    await user.click(screen.getByRole('button', { name: 'Next' }));

    await waitFor(() => {
      expect(
        screen.getByRole('button', { name: 'Resolve conflict' }),
      ).toBeDisabled();
    });
    expect(screen.getByText(/Published version 2 content/)).toBeInTheDocument();
  });

  it('does not expose the comparison route outside dev mode', async () => {
    mocks.devMode = false;

    renderPage();

    expect(await screen.findByText('Editor')).toBeVisible();
    expect(mocks.layoutRequests).not.toHaveBeenCalled();
  });
});
