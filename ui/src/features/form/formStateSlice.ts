import { createSelector, createSlice } from '@reduxjs/toolkit';

import { FORM_TYPES } from '@/features/form/constants';

import type { PayloadAction } from '@reduxjs/toolkit';
import type { RootState } from '@/app/store';
import type { PropGroup } from '@/types/Component';
import type { InputMessage } from '@/types/Form';

export interface FormState {
  values: Record<string, any>;
  errors: Record<string, InputMessage>;
  openGroups: Record<string, boolean>;
}

export interface FormStateSliceFormsState {
  [FORM_TYPES.COMPONENT_INSTANCE_FORM]: FormState;
  [FORM_TYPES.ENTITY_FORM]: FormState;
}

export interface FormStateSliceState extends FormStateSliceFormsState {
  currentComponent: string | undefined;
}

const emptyFormState: FormState = {
  values: {},
  errors: {},
  openGroups: {},
};

export const initialState: FormStateSliceState = {
  currentComponent: undefined,
  [FORM_TYPES.COMPONENT_INSTANCE_FORM]: emptyFormState,
  [FORM_TYPES.ENTITY_FORM]: emptyFormState,
};

type ComponentId = string;

export type FormId = keyof FormStateSliceFormsState;

type SetFieldErrorPayload = {
  formId: FormId;
  fieldName: string;
  type: 'error' | 'warning' | 'info';
  message: string;
};

type ClearFieldErrorPayload = {
  formId: FormId;
  fieldName: string;
};

type RemoveFieldValuePayload = {
  formId: FormId;
  fieldName: string | string[];
};

type SetFieldValuePayload = {
  formId: FormId;
  fieldName: string;
  value: any;
  updateSource?: boolean;
};

type SeedOpenGroupsPayload = {
  formId: FormId;
  groups: PropGroup[];
};

type SetGroupOpenPayload = {
  formId: FormId;
  groupId: string;
  open: boolean;
};

type ForceOpenGroupsPayload = {
  formId: FormId;
  groupIds: string[];
};

export const formStateSlice = createSlice({
  name: 'formState',
  initialState,
  reducers: (create) => ({
    setCurrentComponent: create.reducer(
      (state, action: PayloadAction<ComponentId>) => ({
        ...state,
        currentComponent: action.payload,
        [FORM_TYPES.COMPONENT_INSTANCE_FORM]: {
          errors: {},
          values: {},
          openGroups: {},
        },
      }),
    ),
    clearFieldValues: create.reducer(
      (state, action: PayloadAction<FormId>) => ({
        ...state,
        [action.payload]: { errors: {}, values: {}, openGroups: {} },
      }),
    ),
    setFieldError: create.reducer(
      (state, action: PayloadAction<SetFieldErrorPayload>) => ({
        ...state,
        [action.payload.formId]: {
          ...state[action.payload.formId],
          errors: {
            ...state[action.payload.formId].errors,
            [action.payload.fieldName]: {
              message: action.payload.message,
              type: action.payload.type,
            },
          },
        },
      }),
    ),
    clearFieldError: create.reducer(
      (state, action: PayloadAction<ClearFieldErrorPayload>) => {
        delete state[action.payload.formId].errors[action.payload.fieldName];
        return state;
      },
    ),
    setFieldValue: create.reducer(
      (state, action: PayloadAction<SetFieldValuePayload>) => {
        const newState = {
          ...state,
          [action.payload.formId]: {
            ...state[action.payload.formId],
            values: {
              ...state[action.payload.formId].values,
              [action.payload.fieldName]: action.payload.value,
            },
          },
        };
        // If this is flagged to update the source, we need to update the
        // serialized form_canvas_props value to reflect that.
        if (action.payload.updateSource) {
          const propsInfo = { ...state.component_instance_form.values }
            ?.form_canvas_props;
          try {
            const parsedPropsInfo = propsInfo && JSON.parse(propsInfo);
            if (parsedPropsInfo) {
              parsedPropsInfo.source[action.payload.fieldName] =
                action.payload.value.source;
              parsedPropsInfo.resolved[action.payload.fieldName] =
                action.payload.value.resolved;
              newState.component_instance_form.values.form_canvas_props =
                JSON.stringify(parsedPropsInfo);
            }
          } catch (e) {
            console.warn(
              'could not parse serialized canvas props, prop linking might fail',
            );
          }
        }

        return newState;
      },
    ),
    removeFieldValue: create.reducer(
      (state, action: PayloadAction<RemoveFieldValuePayload>) => {
        const formState = state[action.payload.formId];
        if (!formState?.values) {
          return state;
        }

        const fieldNames = Array.isArray(action.payload.fieldName)
          ? action.payload.fieldName
          : [action.payload.fieldName];

        const newValues = { ...formState.values };
        fieldNames.forEach((fieldName) => {
          delete newValues[fieldName];
        });

        return {
          ...state,
          [action.payload.formId]: {
            ...formState,
            values: newValues,
          },
        };
      },
    ),
    seedOpenGroups: create.reducer(
      (state, action: PayloadAction<SeedOpenGroupsPayload>) => {
        const { formId, groups } = action.payload;
        const openGroups: Record<string, boolean> = {};
        groups.forEach((group) => {
          openGroups[group.id] = group.open;
        });
        state[formId].openGroups = openGroups;
      },
    ),
    setGroupOpen: create.reducer(
      (state, action: PayloadAction<SetGroupOpenPayload>) => {
        const { formId, groupId, open } = action.payload;
        if (!state[formId].openGroups) {
          state[formId].openGroups = {};
        }
        state[formId].openGroups[groupId] = open;
      },
    ),
    forceOpenGroups: create.reducer(
      (state, action: PayloadAction<ForceOpenGroupsPayload>) => {
        const { formId, groupIds } = action.payload;
        if (!state[formId].openGroups) {
          state[formId].openGroups = {};
        }
        groupIds.forEach((groupId) => {
          state[formId].openGroups[groupId] = true;
        });
      },
    ),
  }),
  selectors: {
    selectCurrentComponent: (state) => state.currentComponent,
  },
});

export interface FieldIdentifier {
  formId: FormId;
  fieldName: string;
}

const selectFormStateForForm = (state: RootState, formId: FormId) => formId;
const selectFormState = (state: RootState) => state.formState;
const selectFieldIdentifiers = (
  state: RootState,
  fieldIdentifiers: FieldIdentifier,
) => fieldIdentifiers;

export const selectFormValues = createSelector(
  [selectFormState, selectFormStateForForm],
  (formState: FormStateSliceState, formId: FormId) =>
    formState[formId]?.values || {},
);

export const selectFieldValue = createSelector(
  [selectFormState, selectFieldIdentifiers],
  (formState: FormStateSliceState, identifiers: FieldIdentifier) =>
    formState[identifiers.formId]?.values[identifiers.fieldName] || null,
);

export const selectFieldError = createSelector(
  [selectFormState, selectFieldIdentifiers],
  (formState: FormStateSliceState, identifiers: FieldIdentifier) =>
    formState[identifiers.formId]?.errors[identifiers.fieldName] || null,
);

export interface GroupIdentifier {
  formId: FormId;
  groupId: string;
}

export const selectGroupOpen = (identifier: GroupIdentifier) =>
  (state: RootState): boolean | undefined =>
    state.formState[identifier.formId]?.openGroups?.[identifier.groupId];

export interface GroupsWithErrorsArgs {
  formId: FormId;
  propSources: Record<string, { group?: string | null }>;
}

const selectGroupsWithErrorsArgs = (
  state: RootState,
  args: GroupsWithErrorsArgs,
) => args;

export const selectGroupsWithErrors = createSelector(
  [selectFormState, selectGroupsWithErrorsArgs],
  (formState: FormStateSliceState, args: GroupsWithErrorsArgs): string[] => {
    const errors = formState[args.formId]?.errors || {};
    const groupIds = new Set<string>();
    Object.keys(errors).forEach((fieldName) => {
      const propSource = args.propSources[fieldName];
      if (propSource && propSource.group) {
        groupIds.add(propSource.group);
      }
    });
    return Array.from(groupIds);
  },
);

export const { selectCurrentComponent } = formStateSlice.selectors;

export const {
  setCurrentComponent,
  setFieldError,
  setFieldValue,
  clearFieldError,
  clearFieldValues,
  removeFieldValue,
  seedOpenGroups,
  setGroupOpen,
  forceOpenGroups,
} = formStateSlice.actions;
