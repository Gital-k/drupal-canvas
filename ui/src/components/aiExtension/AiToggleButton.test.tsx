import { beforeEach, describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import AiToggleButton from '@/components/aiExtension/AiToggleButton';
import { AI_PANEL_STATE_KEY } from '@/components/aiExtension/AiPanelState';

describe('AiToggleButton', () => {
  beforeEach(() => {
    window.localStorage.clear();
  });

  it('saves open state to localStorage when panel is opened', async () => {
    const user = userEvent.setup();
    const store = makeStore({
      primaryPanel: {
        activePanel: '',
        isHidden: false,
        aiPanelOpen: false,
        manageLibraryTab: null,
      },
    });

    render(
      <AppWrapper store={store} location="/" path="/">
        <AiToggleButton />
      </AppWrapper>,
    );

    await user.click(screen.getByRole('button', { name: 'Open AI Panel' }));

    const saved = JSON.parse(
      localStorage.getItem(AI_PANEL_STATE_KEY) as string,
    );
    expect(saved).toEqual({ isOpen: true, shouldClearHistory: true });
  });

  it('saves closed state to localStorage when panel is closed', async () => {
    const user = userEvent.setup();
    const store = makeStore({
      primaryPanel: {
        activePanel: '',
        isHidden: false,
        aiPanelOpen: true,
        manageLibraryTab: null,
      },
    });

    render(
      <AppWrapper store={store} location="/" path="/">
        <AiToggleButton />
      </AppWrapper>,
    );

    await user.click(screen.getByRole('button', { name: 'Close AI Panel' }));

    const saved = JSON.parse(
      localStorage.getItem(AI_PANEL_STATE_KEY) as string,
    );
    expect(saved).toEqual({ isOpen: false, shouldClearHistory: true });
  });

  it('sets shouldClearHistory to true on explicit toggle', async () => {
    const user = userEvent.setup();
    const store = makeStore({
      primaryPanel: {
        activePanel: '',
        isHidden: false,
        aiPanelOpen: false,
        manageLibraryTab: null,
      },
    });

    render(
      <AppWrapper store={store} location="/" path="/">
        <AiToggleButton />
      </AppWrapper>,
    );

    // Open the panel.
    await user.click(screen.getByRole('button', { name: 'Open AI Panel' }));
    let saved = JSON.parse(
      localStorage.getItem(AI_PANEL_STATE_KEY) as string,
    );
    expect(saved.shouldClearHistory).toBe(true);

    // Close the panel.
    await user.click(screen.getByRole('button', { name: 'Close AI Panel' }));
    saved = JSON.parse(localStorage.getItem(AI_PANEL_STATE_KEY) as string);
    expect(saved.shouldClearHistory).toBe(true);
  });
});
