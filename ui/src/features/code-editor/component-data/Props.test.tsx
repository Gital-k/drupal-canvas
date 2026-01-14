import { describe, expect, it } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
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
  it('renders empty', async () => {
    render(<Wrapper />);
    expect(
      screen.queryByRole('textbox', { name: 'Prop name' }),
    ).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Add' })).toBeInTheDocument();
  });

  it('add new prop form', async () => {
    render(<Wrapper />);

    await userEvent.click(screen.getByRole('button', { name: 'Add' }));
    expect(
      screen.getByRole('textbox', { name: 'Prop name' }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('textbox', { name: 'Example value' }),
    ).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Type' })).toBeInTheDocument();
    expect(
      screen.getByRole('switch', { name: 'Required' }),
    ).toBeInTheDocument();

    expect(
      selectCodeComponentProperty('props')(store.getState()).length,
    ).toEqual(1);
  });

  it('remove prop form', async () => {
    render(<Wrapper />);

    await userEvent.click(screen.getByRole('button', { name: /Remove prop/ }));
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

    expect(
      selectCodeComponentProperty('props')(store.getState()).length,
    ).toEqual(0);
  });

  it('saves prop data', async () => {
    render(<Wrapper />);
    await userEvent.click(screen.getByRole('button', { name: 'Add' }));

    expect(
      screen.getByRole('textbox', { name: 'Prop name' }),
    ).toBeInTheDocument();

    await userEvent.type(
      screen.getByRole('textbox', { name: 'Prop name' }),
      'Alpha',
    );
    expect(
      selectCodeComponentProperty('props')(store.getState())[0].name,
    ).toEqual('Alpha');

    await userEvent.type(
      screen.getByRole('textbox', { name: 'Example value' }),
      'Alpha value',
    );
    expect(
      selectCodeComponentProperty('props')(store.getState())[0].example,
    ).toEqual('Alpha value');
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

    await userEvent.click(screen.getByRole('switch', { name: 'Required' }));
    expect(screen.getByRole('switch', { name: 'Required' })).toBeChecked();
    expect(
      selectCodeComponentProperty('required')(store.getState())[0],
    ).toEqual(getPropMachineName(propName));
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

      await userEvent.click(screen.getByRole('combobox', { name: 'Type' }));
      expect(screen.getByRole('option', { name })).toBeInTheDocument();

      await userEvent.click(screen.getByRole('option', { name }));
      expect(
        selectCodeComponentProperty('props')(store.getState())[0].type,
      ).toEqual(type);
    });
  });

  it('reorders props', async () => {
    render(<Wrapper />);
    await userEvent.click(screen.getByRole('button', { name: 'Add' }));
    expect(screen.getByTestId('prop-0')).toBeInTheDocument();
    expect(screen.getByTestId('prop-1')).toBeInTheDocument();

    const prop2 = screen.getByTestId('prop-1');

    await userEvent.type(
      within(prop2).getByRole('textbox', { name: 'Prop name' }),
      'Beta',
    );

    expect(
      selectCodeComponentProperty('props')(store.getState())[0].name,
    ).toEqual('Alpha');
    expect(
      selectCodeComponentProperty('props')(store.getState())[1].name,
    ).toEqual('Beta');

    await userEvent.click(
      within(prop2).getByRole('button', { name: /Move prop/ }),
    );
    await userEvent.keyboard('[Space]');
    await userEvent.keyboard('[ArrowUp]');

    expect(
      selectCodeComponentProperty('props')(store.getState())[0].name,
    ).toEqual('Beta');
    expect(
      selectCodeComponentProperty('props')(store.getState())[1].name,
    ).toEqual('Alpha');
  });
});
