import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import ConflictResolutionPage from './ConflictResolutionPage';

import type { ReactNode } from 'react';
import type { PendingChanges } from '@/services/pendingChangesApi';

const mocks = vi.hoisted(() => ({
  dispatch: vi.fn(),
  devConflictDetectionMode: true,
  exportError: false,
  exportLoading: false,
  exportRequests: vi.fn(),
  invalidateContent: vi.fn(),
  invalidateLayout: vi.fn(),
  layoutRequests: vi.fn(),
  pendingChanges: undefined as PendingChanges | undefined,
  pendingChangesFetching: false,
  publishedLayoutUpdated: Math.floor(
    new Date(2026, 7, 19, 12, 1).getTime() / 1000,
  ),
  draftLayoutUpdated: Math.floor(
    new Date(2026, 7, 20, 20, 10).getTime() / 1000,
  ),
  publishedExportUpdated: Math.floor(
    new Date(2026, 7, 19, 12, 5).getTime() / 1000,
  ),
  draftExportUpdated: Math.floor(
    new Date(2026, 7, 20, 20, 15).getTime() / 1000,
  ),
  refetch: vi.fn(),
  discardChange: vi.fn(),
  resolveConflict: vi.fn(),
  toastError: vi.fn(
    (_message?: unknown, _options?: unknown) => 'resolution-error-toast',
  ),
  toastDismiss: vi.fn(),
}));

vi.mock('sonner', () => ({
  toast: {
    dismiss: mocks.toastDismiss,
    error: mocks.toastError,
  },
}));

vi.mock('@/app/hooks', () => ({
  useAppDispatch: () => mocks.dispatch,
}));

vi.mock('@/utils/drupal-globals', () => ({
  getCanvasSettings: () => ({
    devConflictDetectionMode: mocks.devConflictDetectionMode,
  }),
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
        updated: arg.publishedVersion
          ? mocks.publishedLayoutUpdated
          : mocks.draftLayoutUpdated,
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
  useGetContentExportQuery: (arg: {
    entityId: string;
    entityType: string;
    autoSaved?: boolean;
  }) => {
    mocks.exportRequests(arg);
    const isPublished = arg.autoSaved === false;
    return {
      data:
        mocks.exportLoading || mocks.exportError
          ? undefined
          : {
              entity: isPublished
                ? `published yaml ${arg.entityId}`
                : `new yaml ${arg.entityId}`,
              updated: isPublished
                ? mocks.publishedExportUpdated
                : mocks.draftExportUpdated,
              language: 'en',
            },
      isFetching: mocks.exportLoading,
      isError: mocks.exportError,
    };
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
    conflict_id: 'revision-2',
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
    conflict_id: 'revision-3',
  },
};

const formatExpectedVersionUpdated = (timestamp: number) => {
  const date = new Date(timestamp * 1000);
  const formattedDate = new Intl.DateTimeFormat(undefined, {
    month: 'numeric',
    day: 'numeric',
    year: '2-digit',
  }).format(date);
  const formattedTime = new Intl.DateTimeFormat(undefined, {
    hour: 'numeric',
    minute: '2-digit',
  }).format(date);

  return `Updated ${formattedDate} at ${formattedTime}`;
};

vi.mock('@/services/pendingChangesApi', () => ({
  useGetAllPendingChangesQuery: () => ({
    data: mocks.pendingChanges,
    refetch: mocks.refetch,
    isFetching: mocks.pendingChangesFetching,
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
    reviewIndex,
    reviewTotal,
  }: {
    comparison: ReactNode;
    canResolveConflict: boolean;
    onNext: () => void;
    onResolveConflict: () => void;
    reviewIndex: number;
    reviewTotal: number;
  }) => (
    <div>
      {comparison}
      <span>
        Review {reviewIndex + 1} of {reviewTotal}
      </span>
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
    publishedVersion: {
      html: string;
      text?: string;
      textLoading?: boolean;
      textError?: boolean;
      updated?: string;
    };
    newVersion: {
      html: string;
      text?: string;
      textLoading?: boolean;
      textError?: boolean;
      updated?: string;
    };
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
      <span>{publishedVersion.updated}</span>
      <span>{publishedVersion.text}</span>
      <span>{String(!!publishedVersion.textLoading)}</span>
      <span>{String(!!publishedVersion.textError)}</span>
      <span>{newVersion.html}</span>
      <span>{newVersion.updated}</span>
      <span>{newVersion.text}</span>
      <span>{String(!!newVersion.textLoading)}</span>
      <span>{String(!!newVersion.textError)}</span>
    </div>
  ),
}));

const getPage = () => (
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
  </MemoryRouter>
);

const renderPage = () => render(getPage());

describe('ConflictResolutionPage', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    mocks.devConflictDetectionMode = true;
    mocks.exportError = false;
    mocks.exportLoading = false;
    mocks.publishedLayoutUpdated = Math.floor(
      new Date(2026, 7, 19, 12, 1).getTime() / 1000,
    );
    mocks.draftLayoutUpdated = Math.floor(
      new Date(2026, 7, 20, 20, 10).getTime() / 1000,
    );
    mocks.publishedExportUpdated = Math.floor(
      new Date(2026, 7, 19, 12, 5).getTime() / 1000,
    );
    mocks.draftExportUpdated = Math.floor(
      new Date(2026, 7, 20, 20, 15).getTime() / 1000,
    );
    mocks.pendingChanges = pendingChanges;
    mocks.pendingChangesFetching = false;
    mocks.discardChange.mockResolvedValue(undefined);
    mocks.resolveConflict.mockResolvedValue(undefined);
    mocks.refetch.mockResolvedValue(undefined);
  });

  it('shows a loading state while pending changes are fetched', () => {
    mocks.pendingChanges = undefined;
    mocks.pendingChangesFetching = true;

    renderPage();

    expect(
      screen.getByTestId('conflict-resolution-loading-state'),
    ).toBeInTheDocument();
    expect(
      screen.queryByTestId('conflict-resolved-state'),
    ).not.toBeInTheDocument();
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

  it('uses layout updated timestamps while text exports are loading', () => {
    mocks.exportLoading = true;

    renderPage();

    expect(
      screen.getByText(
        formatExpectedVersionUpdated(mocks.publishedLayoutUpdated),
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByText(formatExpectedVersionUpdated(mocks.draftLayoutUpdated)),
    ).toBeInTheDocument();
  });

  it('requests both YAML exports for the text comparison', () => {
    renderPage();

    expect(mocks.exportRequests).toHaveBeenCalledWith({
      entityId: '1',
      entityType: 'canvas_page',
    });
    expect(mocks.exportRequests).toHaveBeenCalledWith({
      entityId: '1',
      entityType: 'canvas_page',
      autoSaved: false,
    });
    expect(screen.getByText('published yaml 1')).toBeInTheDocument();
    expect(screen.getByText('new yaml 1')).toBeInTheDocument();
  });

  it('passes formatted export updated timestamps to the comparison view', () => {
    renderPage();

    expect(
      screen.getByText(
        formatExpectedVersionUpdated(mocks.publishedExportUpdated),
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByText(formatExpectedVersionUpdated(mocks.draftExportUpdated)),
    ).toBeInTheDocument();
  });

  it('passes text export loading and error states to the comparison view', () => {
    mocks.exportLoading = true;
    mocks.exportError = true;

    renderPage();

    expect(screen.getAllByText('true')).toHaveLength(4);
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

  it('keeps the original review position after resolving a queued conflict', async () => {
    const user = userEvent.setup();
    renderPage();

    expect(screen.getByText('Review 1 of 2')).toBeInTheDocument();

    await user.click(
      screen.getByRole('button', { name: 'Select new version' }),
    );
    await user.click(screen.getByRole('button', { name: 'Resolve conflict' }));

    await waitFor(() => {
      expect(screen.getByText('Review 2 of 2')).toBeInTheDocument();
    });
    expect(screen.getByText(/Published version 2 content/)).toBeInTheDocument();
    expect(screen.getByText(/New version 2 content/)).toBeInTheDocument();
  });

  it('keeps the opened conflict snapshot when pending changes refresh', async () => {
    const user = userEvent.setup();
    const originalDraftUpdated = formatExpectedVersionUpdated(
      mocks.draftExportUpdated,
    );
    const { rerender } = renderPage();

    expect(screen.getByText(originalDraftUpdated)).toBeInTheDocument();

    mocks.pendingChanges = {
      ...pendingChanges,
      'canvas_page:1:en': {
        ...pendingChanges['canvas_page:1:en'],
        updated: Math.floor(new Date(2026, 7, 21, 10, 30).getTime() / 1000),
        conflict_id: 'revision-99',
      },
      'canvas_page:3:en': {
        ...pendingChanges['canvas_page:2:en'],
        entity_id: '3',
        label: 'Contact',
        updated: 200,
        conflict_id: 'revision-4',
      },
    };
    mocks.draftLayoutUpdated = Math.floor(
      new Date(2026, 7, 21, 10, 30).getTime() / 1000,
    );
    mocks.draftExportUpdated = Math.floor(
      new Date(2026, 7, 21, 10, 35).getTime() / 1000,
    );

    rerender(getPage());

    expect(screen.getByText(originalDraftUpdated)).toBeInTheDocument();
    expect(
      screen.queryByText(
        formatExpectedVersionUpdated(mocks.draftLayoutUpdated),
      ),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByText(
        formatExpectedVersionUpdated(mocks.draftExportUpdated),
      ),
    ).not.toBeInTheDocument();

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
  });

  it('shows a dismissible toast without changing the comparison layout when resolution fails', async () => {
    const user = userEvent.setup();
    mocks.resolveConflict.mockRejectedValueOnce(new Error('Request failed'));
    renderPage();

    await user.click(
      screen.getByRole('button', { name: 'Select new version' }),
    );
    await user.click(screen.getByRole('button', { name: 'Resolve conflict' }));

    await waitFor(() => {
      expect(mocks.toastError).toHaveBeenCalledWith(
        'Unable to resolve this conflict. Please try again.',
        expect.objectContaining({
          action: expect.objectContaining({
            label: expect.anything(),
            onClick: expect.any(Function),
          }),
          actionButtonStyle: expect.objectContaining({
            alignItems: 'center',
            justifyContent: 'center',
            width: '20px',
            height: '20px',
            marginLeft: 'auto',
            marginRight: 0,
            marginBottom: 'auto',
            padding: 0,
            color: 'var(--gray-11)',
            background: 'transparent',
          }),
        }),
      );
    });

    const toastOptions = mocks.toastError.mock.calls[0]?.[1] as {
      action: { onClick: () => void };
    };
    toastOptions.action.onClick();
    expect(mocks.toastDismiss).toHaveBeenCalledWith('resolution-error-toast');

    expect(screen.getByText(/Published version 1 content/)).toBeInTheDocument();
    expect(screen.getByText(/New version 1 content/)).toBeInTheDocument();
    expect(
      screen.queryByTestId('conflict-resolution-error'),
    ).not.toBeInTheDocument();
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
    mocks.devConflictDetectionMode = false;

    renderPage();

    expect(await screen.findByText('Editor')).toBeVisible();
    expect(mocks.layoutRequests).not.toHaveBeenCalled();
    expect(mocks.exportRequests).not.toHaveBeenCalled();
  });

  it('renders the resolved state with a review changes action', async () => {
    const user = userEvent.setup();
    mocks.pendingChanges = {
      'canvas_page:1:en': {
        ...pendingChanges['canvas_page:1:en'],
        hasConflict: false,
        conflict_id: undefined,
      },
    };

    renderPage();

    expect(screen.getByText('All files resolved')).toBeInTheDocument();
    expect(screen.getByText('Everything is up to date')).toBeInTheDocument();
    expect(
      screen.getByText(
        'All conflicts have been resolved. Your changes are ready to be published.',
      ),
    ).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Review 1 change' }));

    expect(await screen.findByText('Editor')).toBeVisible();
  });
});
