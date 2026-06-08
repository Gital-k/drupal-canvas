import { describe, expect, it } from 'vitest';

import { FORM_TYPES } from '@/features/form/constants';
import {
  forceOpenGroups,
  formStateSlice,
  initialState,
  seedOpenGroups,
  selectGroupsWithErrors,
  setCurrentComponent,
  setFieldError,
  setGroupOpen,
} from '@/features/form/formStateSlice';

import type { PropGroup } from '@/types/Component';

const FORM_ID = FORM_TYPES.COMPONENT_INSTANCE_FORM;

const sampleGroups: PropGroup[] = [
  { id: 'content', title: 'Content', weight: 0, open: true },
  { id: 'styling', title: 'Styling', weight: 10, open: true },
  { id: 'advanced', title: 'Advanced', weight: 20, open: false },
];

describe('formStateSlice openGroups behavior', () => {
  it('seedOpenGroups populates openGroups from a PropGroup array', () => {
    const next = formStateSlice.reducer(
      initialState,
      seedOpenGroups({ formId: FORM_ID, groups: sampleGroups }),
    );
    expect(next[FORM_ID].openGroups).toEqual({
      content: true,
      styling: true,
      advanced: false,
    });
  });

  it('seedOpenGroups replaces any prior map', () => {
    const seeded = formStateSlice.reducer(
      initialState,
      seedOpenGroups({ formId: FORM_ID, groups: sampleGroups }),
    );
    const reseeded = formStateSlice.reducer(
      seeded,
      seedOpenGroups({
        formId: FORM_ID,
        groups: [{ id: 'content', title: 'Content', weight: 0, open: false }],
      }),
    );
    expect(reseeded[FORM_ID].openGroups).toEqual({ content: false });
  });

  it('setGroupOpen updates a single group open state without affecting others', () => {
    const seeded = formStateSlice.reducer(
      initialState,
      seedOpenGroups({ formId: FORM_ID, groups: sampleGroups }),
    );
    const next = formStateSlice.reducer(
      seeded,
      setGroupOpen({ formId: FORM_ID, groupId: 'advanced', open: true }),
    );
    expect(next[FORM_ID].openGroups).toEqual({
      content: true,
      styling: true,
      advanced: true,
    });

    const closed = formStateSlice.reducer(
      next,
      setGroupOpen({ formId: FORM_ID, groupId: 'content', open: false }),
    );
    expect(closed[FORM_ID].openGroups).toEqual({
      content: false,
      styling: true,
      advanced: true,
    });
  });

  it('forceOpenGroups only sets to true and never closes a group', () => {
    const seeded = formStateSlice.reducer(
      initialState,
      seedOpenGroups({ formId: FORM_ID, groups: sampleGroups }),
    );
    // advanced is currently false; forceOpenGroups should open it.
    const opened = formStateSlice.reducer(
      seeded,
      forceOpenGroups({ formId: FORM_ID, groupIds: ['advanced'] }),
    );
    expect(opened[FORM_ID].openGroups.advanced).toBe(true);

    // Manually close advanced again.
    const closed = formStateSlice.reducer(
      opened,
      setGroupOpen({ formId: FORM_ID, groupId: 'advanced', open: false }),
    );
    expect(closed[FORM_ID].openGroups.advanced).toBe(false);

    // forceOpenGroups should reopen it (set to true) and not be capable of
    // setting to false: passing `groupIds` always sets to true.
    const forced = formStateSlice.reducer(
      closed,
      forceOpenGroups({ formId: FORM_ID, groupIds: ['advanced'] }),
    );
    expect(forced[FORM_ID].openGroups.advanced).toBe(true);

    // Groups not listed are untouched.
    expect(forced[FORM_ID].openGroups.content).toBe(true);
    expect(forced[FORM_ID].openGroups.styling).toBe(true);
  });

  it('setCurrentComponent clears openGroups for the component instance form', () => {
    const seeded = formStateSlice.reducer(
      initialState,
      seedOpenGroups({ formId: FORM_ID, groups: sampleGroups }),
    );
    expect(Object.keys(seeded[FORM_ID].openGroups).length).toBeGreaterThan(0);

    const cleared = formStateSlice.reducer(
      seeded,
      setCurrentComponent('some-uuid'),
    );
    expect(cleared[FORM_ID].openGroups).toEqual({});
    expect(cleared.currentComponent).toBe('some-uuid');
  });
});

describe('selectGroupsWithErrors', () => {
  const propSources = {
    text: { group: 'content' },
    type: { group: 'styling' },
    cssClass: { group: 'advanced' },
    legacy: { group: null },
  };

  const buildRootState = (errors: Record<string, { message: string; type: 'error' | 'warning' | 'info' }>) =>
    ({
      formState: {
        ...initialState,
        [FORM_ID]: {
          ...initialState[FORM_ID],
          errors,
        },
      },
    }) as any;

  it('returns [] when there are no errors', () => {
    const state = buildRootState({});
    expect(
      selectGroupsWithErrors(state, { formId: FORM_ID, propSources }),
    ).toEqual([]);
  });

  it('returns the group ids of errored fields', () => {
    let state = formStateSlice.reducer(
      initialState,
      setFieldError({
        formId: FORM_ID,
        fieldName: 'cssClass',
        type: 'error',
        message: 'bad',
      }),
    );
    state = formStateSlice.reducer(
      state,
      setFieldError({
        formId: FORM_ID,
        fieldName: 'text',
        type: 'error',
        message: 'bad',
      }),
    );
    const root = { formState: state } as any;
    const result = selectGroupsWithErrors(root, {
      formId: FORM_ID,
      propSources,
    });
    expect(new Set(result)).toEqual(new Set(['advanced', 'content']));
  });

  it('deduplicates when multiple errored fields share a group', () => {
    const propSources2 = {
      a: { group: 'g1' },
      b: { group: 'g1' },
      c: { group: 'g2' },
    };
    let state = formStateSlice.reducer(
      initialState,
      setFieldError({
        formId: FORM_ID,
        fieldName: 'a',
        type: 'error',
        message: 'bad',
      }),
    );
    state = formStateSlice.reducer(
      state,
      setFieldError({
        formId: FORM_ID,
        fieldName: 'b',
        type: 'error',
        message: 'bad',
      }),
    );
    state = formStateSlice.reducer(
      state,
      setFieldError({
        formId: FORM_ID,
        fieldName: 'c',
        type: 'error',
        message: 'bad',
      }),
    );
    const root = { formState: state } as any;
    const result = selectGroupsWithErrors(root, {
      formId: FORM_ID,
      propSources: propSources2,
    });
    expect(result.length).toBe(2);
    expect(new Set(result)).toEqual(new Set(['g1', 'g2']));
  });

  it('skips errored fields with no group or null group', () => {
    let state = formStateSlice.reducer(
      initialState,
      setFieldError({
        formId: FORM_ID,
        fieldName: 'legacy',
        type: 'error',
        message: 'bad',
      }),
    );
    const root = { formState: state } as any;
    expect(
      selectGroupsWithErrors(root, { formId: FORM_ID, propSources }),
    ).toEqual([]);
  });
});
