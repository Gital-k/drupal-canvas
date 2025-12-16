import { describe, expect, it } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import { selectCodeComponentProperty } from '@/features/code-editor/codeEditorSlice';
import { getPropMachineName } from '@/features/code-editor/utils/utils';

import Props from './Props';

const store = makeStore({});

const Wrapper = () => (
  <AppWrapper
    store={store}
    location="/code-editor/code/test_component"
    path="/code-editor/code/:codeComponentId"
  >
    <Props />
  </AppWrapper>
);

describe('Props', () => {
  it('renders empty', () => {
    render(<Wrapper />);
    expect(
      screen.queryByRole('textbox', { name: 'Prop name' }),
    ).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Add' })).toBeInTheDocument();
  });

  it('add new prop form', async () => {
    render(<Wrapper />);
    userEvent.click(screen.getByRole('button', { name: 'Add' }));
    await waitFor(() => {
      expect(
        screen.getByRole('textbox', { name: 'Prop name' }),
      ).toBeInTheDocument();
      expect(
        screen.getByRole('textbox', { name: 'Example value' }),
      ).toBeInTheDocument();
      expect(
        screen.getByRole('combobox', { name: 'Type' }),
      ).toBeInTheDocument();
      expect(
        screen.getByRole('switch', { name: 'Required' }),
      ).toBeInTheDocument();
    });
    await waitFor(() =>
      expect(
        selectCodeComponentProperty('props')(store.getState()).length,
      ).toEqual(1),
    );
  });

  it('remove prop form', async () => {
    render(<Wrapper />);
    userEvent.click(screen.getByRole('button', { name: /Remove prop/ }));
    await waitFor(() => {
      expect(
        screen.queryByRole('textbox', { name: 'Prop name' }),
      ).not.toBeInTheDocument();
      expect(
        screen.queryByRole('textbox', { name: 'Example value' }),
      ).not.toBeInTheDocument();
      expect(
        screen.queryByRole('combobox', { name: 'Type' }),
      ).not.toBeInTheDocument();
      expect(
        screen.queryByRole('switch', { name: 'Required' }),
      ).not.toBeInTheDocument();
    });
    await waitFor(() =>
      expect(
        selectCodeComponentProperty('slots')(store.getState()).length,
      ).toEqual(0),
    );
  });

  it('saves prop data', async () => {
    render(<Wrapper />);
    userEvent.click(screen.getByRole('button', { name: 'Add' }));
    await waitFor(() => {
      expect(
        screen.getByRole('textbox', { name: 'Prop name' }),
      ).toBeInTheDocument();
    });
    userEvent.type(screen.getByRole('textbox', { name: 'Prop name' }), 'Alpha');
    await waitFor(() => {
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].name,
      ).toEqual('Alpha');
    });
    userEvent.type(
      screen.getByRole('textbox', { name: 'Example value' }),
      'Alpha value',
    );
    await waitFor(() => {
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].example,
      ).toEqual('Alpha value');
    });
  });

  it('sets required', async () => {
    render(<Wrapper />);
    await waitFor(() => {
      expect(
        screen.getByRole('textbox', { name: 'Prop name' }),
      ).toBeInTheDocument();
    });
    const propName = selectCodeComponentProperty('props')(store.getState())[0]
      .name;
    userEvent.click(screen.getByRole('switch', { name: 'Required' }));
    await waitFor(() => {
      expect(screen.getByRole('switch', { name: 'Required' })).toBeChecked();
      expect(
        selectCodeComponentProperty('required')(store.getState())[0],
      ).toEqual(getPropMachineName(propName));
    });
  });

  describe.each([
    ['string', 'Text'],
    ['integer', 'Integer'],
    ['number', 'Number'],
    ['string', 'Formatted text'],
    ['string', 'Link'],
    ['object', 'Image'],
    ['object', 'Video'],
    ['boolean', 'Boolean'],
    ['string', 'List: text'],
    ['integer', 'List: integer'],
  ])('selects type', (type, name) => {
    it(`${name}`, async () => {
      render(<Wrapper />);
      await waitFor(() => {
        expect(
          screen.getByRole('textbox', { name: 'Prop name' }),
        ).toBeInTheDocument();
      });
      userEvent.click(screen.getByRole('combobox', { name: 'Type' }));
      await waitFor(() =>
        expect(screen.getByRole('option', { name })).toBeInTheDocument(),
      );
      userEvent.click(screen.getByRole('option', { name }));
      await waitFor(() => {
        expect(
          selectCodeComponentProperty('props')(store.getState())[0].type,
        ).toEqual(type);
      });
    });
  });
});
