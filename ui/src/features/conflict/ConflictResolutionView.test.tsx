import { useMemo, useState } from 'react';
import { Provider } from 'react-redux';
import { describe, expect, it, vi } from 'vitest';
import { Provider as TooltipProvider } from '@radix-ui/react-tooltip';
import { Theme } from '@radix-ui/themes';
import { configureStore } from '@reduxjs/toolkit';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { uiSliceReducer } from '@/features/ui/uiSlice';

import { PageConflictComparisonView } from './ConflictResolutionView';

import type { ConflictVersionSelection } from './ConflictResolutionView';

import styles from './ConflictResolutionPage.module.css';

vi.mock('@assets/icons/justify-stretch.svg?react', () => ({
  default: () => <svg aria-hidden="true" />,
}));

const publishedVersion = {
  html: '<main><h1>Published headline</h1></main>',
  text: 'Published headline',
  props: {
    title: 'Published headline',
  },
};

const newVersion = {
  html: '<main><h1>New headline</h1></main>',
  text: 'New headline',
  props: {
    title: 'New headline',
  },
  changed: true,
};

const createStore = () =>
  configureStore({
    reducer: {
      ui: uiSliceReducer,
    },
  });

const ComparisonHarness = () => {
  const store = useMemo(() => createStore(), []);
  const [selectedVersion, setSelectedVersion] =
    useState<ConflictVersionSelection>();

  return (
    <Theme accentColor="blue" hasBackground={false}>
      <TooltipProvider>
        <Provider store={store}>
          <PageConflictComparisonView
            publishedVersion={publishedVersion}
            newVersion={newVersion}
            selectedVersion={selectedVersion}
            onSelectVersion={(version) =>
              setSelectedVersion((currentVersion) =>
                currentVersion === version ? undefined : version,
              )
            }
          />
        </Provider>
      </TooltipProvider>
    </Theme>
  );
};

describe('PageConflictComparisonView', () => {
  it('applies the selected card style when a version is selected', async () => {
    const user = userEvent.setup();
    render(<ComparisonHarness />);

    const publishedSelect = screen.getByRole('button', {
      name: 'Select Published version',
    });
    const publishedCard = screen.getByTestId('conflict-published-version-card');
    const newCard = screen.getByTestId('conflict-new-version-card');

    expect(publishedCard).not.toHaveClass(styles.cardSelected);
    expect(newCard).not.toHaveClass(styles.cardSelected);

    await user.click(publishedSelect);

    expect(publishedCard).toHaveClass(styles.cardSelected);
    expect(newCard).not.toHaveClass(styles.cardSelected);
    expect(publishedSelect).toHaveAttribute('aria-pressed', 'true');
  });

  it('keeps the selected version when switching tabs', async () => {
    const user = userEvent.setup();
    render(<ComparisonHarness />);

    await user.click(
      screen.getByRole('button', {
        name: 'Select New version',
      }),
    );

    expect(screen.getByTestId('conflict-new-version-card')).toHaveClass(
      styles.cardSelected,
    );

    await user.click(screen.getByRole('tab', { name: /Text/ }));

    expect(screen.getByTestId('conflict-new-version-card')).toHaveClass(
      styles.cardSelected,
    );

    await user.click(screen.getByRole('tab', { name: /Props/ }));

    expect(screen.getByTestId('conflict-new-version-card')).toHaveClass(
      styles.cardSelected,
    );
  });
});
