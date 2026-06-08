import { describe, expect, it } from 'vitest';
import { act, fireEvent, render, screen } from '@testing-library/react';
import AppWrapper from '@tests/vitest/components/AppWrapper';

import { makeStore } from '@/app/store';
import DrupalDetails from '@/components/form/components/drupal/DrupalDetails';
import { FORM_TYPES } from '@/features/form/constants';
import {
  forceOpenGroups,
  seedOpenGroups,
  setGroupOpen,
} from '@/features/form/formStateSlice';

const renderDrupalDetails = (
  store: ReturnType<typeof makeStore>,
  attributes: Record<string, any>,
  extra: { title?: string } = {},
) =>
  render(
    <AppWrapper store={store} location="/" path="/">
      <DrupalDetails
        attributes={attributes}
        errors={null}
        title={extra.title || 'Test group'}
        description={null}
        value={null}
        required={false}
        element={{}}
      >
        <div data-testid="children">Hello child</div>
      </DrupalDetails>
    </AppWrapper>,
  );

describe('DrupalDetails default branch', () => {
  it('without data-canvas-prop-group: works as a local-state collapsible', () => {
    const store = makeStore();
    renderDrupalDetails(store, { open: true });

    const trigger = screen.getByRole('button', { name: 'Test group' });
    expect(trigger).toBeInTheDocument();
    // It should not be reading from the redux openGroups map.
    expect(
      store.getState().formState[FORM_TYPES.COMPONENT_INSTANCE_FORM]
        .openGroups,
    ).toEqual({});

    // Toggling closes it (we cannot easily inspect the data-state of
    // Collapsible.Root from outside, but it should not throw and should not
    // dispatch any setGroupOpen).
    fireEvent.click(trigger);
    expect(
      store.getState().formState[FORM_TYPES.COMPONENT_INSTANCE_FORM]
        .openGroups,
    ).toEqual({});
  });

  it('with data-canvas-prop-group: open state comes from Redux openGroups', () => {
    const store = makeStore();
    // Seed groups: "advanced" is initially closed.
    store.dispatch(
      seedOpenGroups({
        formId: FORM_TYPES.COMPONENT_INSTANCE_FORM,
        groups: [
          { id: 'advanced', title: 'Advanced', weight: 0, open: false },
        ],
      }),
    );

    renderDrupalDetails(
      store,
      // The DOM `open` attribute would default to true here, but Redux says
      // the group is closed — Redux must win.
      { open: true, 'data-canvas-prop-group': 'advanced' },
      { title: 'Advanced' },
    );

    const trigger = screen.getByRole('button', { name: 'Advanced' });
    expect(trigger.getAttribute('data-state')).toBe('closed');
  });

  it('with data-canvas-prop-group: dispatches setGroupOpen on toggle', () => {
    const store = makeStore();
    store.dispatch(
      seedOpenGroups({
        formId: FORM_TYPES.COMPONENT_INSTANCE_FORM,
        groups: [
          { id: 'styling', title: 'Styling', weight: 0, open: false },
        ],
      }),
    );

    renderDrupalDetails(
      store,
      { 'data-canvas-prop-group': 'styling' },
      { title: 'Styling' },
    );
    const trigger = screen.getByRole('button', { name: 'Styling' });

    // Initially closed (seeded false).
    expect(
      store.getState().formState[FORM_TYPES.COMPONENT_INSTANCE_FORM]
        .openGroups.styling,
    ).toBe(false);

    fireEvent.click(trigger);

    expect(
      store.getState().formState[FORM_TYPES.COMPONENT_INSTANCE_FORM]
        .openGroups.styling,
    ).toBe(true);
  });

  it('forceOpenGroups re-opens a previously closed group', () => {
    const store = makeStore();
    store.dispatch(
      seedOpenGroups({
        formId: FORM_TYPES.COMPONENT_INSTANCE_FORM,
        groups: [
          { id: 'advanced', title: 'Advanced', weight: 0, open: true },
        ],
      }),
    );
    // User collapses it manually.
    store.dispatch(
      setGroupOpen({
        formId: FORM_TYPES.COMPONENT_INSTANCE_FORM,
        groupId: 'advanced',
        open: false,
      }),
    );

    const { rerender } = renderDrupalDetails(
      store,
      { 'data-canvas-prop-group': 'advanced' },
      { title: 'Advanced' },
    );

    let trigger = screen.getByRole('button', { name: 'Advanced' });
    expect(trigger.getAttribute('data-state')).toBe('closed');

    // Force open via the auto-expand reducer.
    act(() => {
      store.dispatch(
        forceOpenGroups({
          formId: FORM_TYPES.COMPONENT_INSTANCE_FORM,
          groupIds: ['advanced'],
        }),
      );
    });

    rerender(
      <AppWrapper store={store} location="/" path="/">
        <DrupalDetails
          attributes={{ 'data-canvas-prop-group': 'advanced' }}
          errors={null}
          title="Advanced"
          description={null}
          value={null}
          required={false}
          element={{}}
        >
          <div>Hello</div>
        </DrupalDetails>
      </AppWrapper>,
    );

    trigger = screen.getByRole('button', { name: 'Advanced' });
    expect(trigger.getAttribute('data-state')).toBe('open');
  });
});
