import { describe, expect, it } from 'vitest';
import { Theme } from '@radix-ui/themes';
import { render, screen } from '@testing-library/react';

import DrupalInputMultivalueForm from '@/components/form/components/drupal/DrupalInputMultivalueForm';


const renderInWrapper = (
  rowCount: number,
  value: string,
  fieldLabel = 'Number',
) =>
  render(
    <Theme>
      <div data-canvas-row-count={String(rowCount)}>
        <DrupalInputMultivalueForm
          attributes={{ value, 'data-field-label': fieldLabel }}
        />
      </div>
    </Theme>,
  );

describe('DrupalInputMultivalueForm – reorder scenario', () => {
  /**
   * Regression test for https://www.drupal.org/i/3582219
   *
   * When a user reorders items in a multivalue field via tabledrag, Drupal may
   * send a stale AJAX response where `attributes.value` no longer matches the
   * displayed value. Because the row count is unchanged the component should
   * trust the *live* hidden-input value and NOT adopt the stale attribute value.
   *
   * Concretely: a field with three items [321, 322, 323] is reordered so that
   * the row formerly showing "321" receives `attributes.value = "323"` from the
   * server. The display must stay "321", not flip to "323".
   */
  it('preserves the live input value when attributes.value changes but row count is unchanged (reorder)', async () => {
    const { rerender } = renderInWrapper(3, '321');

    // Initial render: trigger button shows "321".
    const initialButton = screen.getByRole('button', { name: 'Edit Number: 321' });
    expect(initialButton).toBeInTheDocument();

    // Drupal AJAX reorder response: attributes.value shifts to "323",
    // but the row count stays at 3 (no rows added or removed).
    // InputBehaviors should retain the original value since row count is unchanged.
    rerender(
      <Theme>
        <div data-canvas-row-count="3">
          <DrupalInputMultivalueForm
            attributes={{ value: '323', 'data-field-label': 'Number' }}
          />
        </div>
      </Theme>,
    );

    // The button should still show "321" since the row count didn't change.
    expect(
      screen.getByRole('button', { name: 'Edit Number: 321' }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: 'Edit Number: 323' }),
    ).not.toBeInTheDocument();
  });

  /**
   * When the row count changes (an item was added or removed via AJAX), the
   * component must trust the fresh attribute values coming from the rebuilt
   * form, because all rows are re-assigned new values.
   */
  it('adopts the new attribute value when the row count changes (item added or removed)', async () => {
    const { rerender } = renderInWrapper(3, '321');

    expect(
      screen.getByRole('button', { name: 'Edit Number: 321' }),
    ).toBeInTheDocument();

    // Drupal AJAX response after adding a new row: row count grows from 3→4
    // and this row now holds "400" from the rebuilt form.
    rerender(
      <Theme>
        <div data-canvas-row-count="4">
          <DrupalInputMultivalueForm
            attributes={{ value: '400', 'data-field-label': 'Number' }}
          />
        </div>
      </Theme>,
    );

    // Row count changed → trust the new attribute value.
    expect(
      screen.getByRole('button', { name: 'Edit Number: 400' }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: 'Edit Number: 321' }),
    ).not.toBeInTheDocument();
  });

  /**
   * On initial mount the component should display the value supplied via
   * attributes.value.
   */
  it('displays the initial value on first render', () => {
    renderInWrapper(2, '42');

    expect(
      screen.getByRole('button', { name: 'Edit Number: 42' }),
    ).toBeInTheDocument();
  });

  /**
   * When no value is provided the trigger should fall back to the placeholder
   * text "Empty".
   */
  it('shows "Empty" when no value is provided', () => {
    renderInWrapper(1, '');

    expect(
      screen.getByRole('button', { name: 'Edit Number: Empty' }),
    ).toBeInTheDocument();
  });
});

describe('DrupalInputMultivalueForm – multi-row reorder regression', () => {
  /**
   * Because the row count is unchanged (3→3), each row must trust its live
   * hidden input and keep displaying its original value — not the shifted
   * attribute from the AJAX response. Before the fix, rows would flip to the
   * incoming attribute values, so row 1 would show "323" instead of "321".
   */
  it('all rows keep their live values after a full three-row reorder — no value bleeding across rows', async () => {
    type RowValues = [string, string, string];

    const RowSet = ({ values }: { values: RowValues }) => (
      <Theme>
        <div data-canvas-row-count="3">
          {values.map((v, i) => (
            <DrupalInputMultivalueForm
              key={i}
              attributes={{ value: v, 'data-field-label': 'Number' }}
            />
          ))}
        </div>
      </Theme>
    );

    const { rerender } = render(<RowSet values={['321', '322', '323']} />);

    // Verify initial display — each row shows its own value.
    const initialButtons = screen.getAllByRole('button', {
      name: /^Edit Number:/,
    });
    expect(initialButtons[0]).toHaveAccessibleName('Edit Number: 321');
    expect(initialButtons[1]).toHaveAccessibleName('Edit Number: 322');
    expect(initialButtons[2]).toHaveAccessibleName('Edit Number: 323');

    // Simulate the Drupal AJAX reorder response: attribute values rotate
    // across all three rows. InputBehaviors should retain the original values
    // since the row count is unchanged.
    rerender(<RowSet values={['323', '321', '322']} />);

    // Each row must still show its live value, not the shifted attribute.
    // Before the fix: row 1 (live=321, attr=323) would incorrectly show "323".
    const reorderedButtons = screen.getAllByRole('button', {
      name: /^Edit Number:/,
    });
    expect(reorderedButtons[0]).toHaveAccessibleName('Edit Number: 321');
    expect(reorderedButtons[1]).toHaveAccessibleName('Edit Number: 322');
    expect(reorderedButtons[2]).toHaveAccessibleName('Edit Number: 323');
  });
});
