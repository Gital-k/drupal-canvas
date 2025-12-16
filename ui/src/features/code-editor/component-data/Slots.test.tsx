import { describe, expect, it } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import { selectCodeComponentProperty } from '@/features/code-editor/codeEditorSlice';

import Slots from './Slots';

const store = makeStore({});

const Wrapper = () => (
  <AppWrapper
    store={store}
    location="/code-editor/code/test_component"
    path="/code-editor/code/:codeComponentId"
  >
    <Slots />
  </AppWrapper>
);

describe('Slots', () => {
  it('renders empty', () => {
    render(<Wrapper />);
    expect(
      screen.queryByRole('textbox', { name: 'Slot name' }),
    ).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Add' })).toBeInTheDocument();
  });

  it('add new slot form', async () => {
    render(<Wrapper />);
    userEvent.click(screen.getByRole('button', { name: 'Add' }));
    await waitFor(() => {
      expect(
        screen.getByRole('textbox', { name: 'Slot name' }),
      ).toBeInTheDocument();
      expect(
        screen.getByRole('textbox', { name: /Example/ }),
      ).toBeInTheDocument();
    });
    await waitFor(() =>
      expect(
        selectCodeComponentProperty('slots')(store.getState()).length,
      ).toEqual(1),
    );
  });

  it('remove slot form', async () => {
    render(<Wrapper />);
    userEvent.click(screen.getByRole('button', { name: /Remove slot/ }));
    await waitFor(() => {
      expect(
        screen.queryByRole('textbox', { name: 'Slot name' }),
      ).not.toBeInTheDocument();
      expect(
        screen.queryByRole('textbox', { name: /Example/ }),
      ).not.toBeInTheDocument();
    });
    await waitFor(() =>
      expect(
        selectCodeComponentProperty('slots')(store.getState()).length,
      ).toEqual(0),
    );
  });

  it('saves slot data', async () => {
    render(<Wrapper />);
    userEvent.click(screen.getByRole('button', { name: 'Add' }));
    await waitFor(() => {
      expect(
        screen.getByRole('textbox', { name: 'Slot name' }),
      ).toBeInTheDocument();
    });
    userEvent.type(screen.getByRole('textbox', { name: 'Slot name' }), 'Alpha');
    await waitFor(() => {
      const values = selectCodeComponentProperty('slots')(store.getState());
      expect(values[0].name).toEqual('Alpha');
    });
  });
});
