import { v4 as uuidv4 } from 'uuid';
import { createSlice } from '@reduxjs/toolkit';

import type { PayloadAction } from '@reduxjs/toolkit';
import type { FormBuilderState, FormElement, SubmitSettings } from './types';

const initialState: FormBuilderState = {
  formId: null,
  label: '',
  elements: [],
  submitSettings: {
    button_text: 'Submit',
    confirmation_message: 'Thank you for your submission.',
    redirect_url: '',
  },
  selectedElementUuid: null,
  isDirty: false,
  isSaving: false,
  activeElementUuid: null,
};

export function generateMachineName(
  title: string,
  existingNames: string[],
): string {
  let name = title
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_|_$/g, '')
    .substring(0, 32);

  if (!name) {
    name = 'field';
  }

  let finalName = name;
  let counter = 1;
  while (existingNames.includes(finalName)) {
    finalName = `${name}_${counter}`;
    counter++;
  }

  return finalName;
}

function getExistingMachineNames(
  elements: FormElement[],
  excludeUuid?: string,
): string[] {
  return elements
    .filter((e) => e.uuid !== excludeUuid)
    .map((e) => e.machineName);
}

export const formBuilderSlice = createSlice({
  name: 'formBuilder',
  initialState,
  reducers: {
    loadForm(
      state,
      action: PayloadAction<{
        formId: string;
        label: string;
        elements: FormElement[];
        submitSettings: SubmitSettings;
      }>,
    ) {
      state.formId = action.payload.formId;
      state.label = action.payload.label;
      state.elements = action.payload.elements;
      state.submitSettings = action.payload.submitSettings;
      state.isDirty = false;
    },

    addElement(
      state,
      action: PayloadAction<{
        type: string;
        parentUuid: string | null;
        afterUuid: string | null;
        defaultProperties?: Record<string, unknown>;
      }>,
    ) {
      const {
        type,
        parentUuid,
        afterUuid,
        defaultProperties = {},
      } = action.payload;
      const existingNames = getExistingMachineNames(state.elements);
      const title = (defaultProperties.title as string) || type;
      const machineName = generateMachineName(title, existingNames);

      const siblings = state.elements.filter((e) => e.parent === parentUuid);

      let weight = 0;
      if (afterUuid) {
        const afterEl = state.elements.find((e) => e.uuid === afterUuid);
        if (afterEl) {
          weight = afterEl.weight + 1;
          siblings.forEach((s) => {
            if (s.weight > afterEl.weight) {
              s.weight++;
            }
          });
        }
      } else {
        weight =
          siblings.length > 0
            ? Math.max(...siblings.map((s) => s.weight)) + 1
            : 0;
      }

      const newElement: FormElement = {
        uuid: uuidv4(),
        type,
        machineName,
        properties: defaultProperties,
        weight,
        parent: parentUuid,
      };

      state.elements.push(newElement);
      state.selectedElementUuid = newElement.uuid;
      state.isDirty = true;
    },

    updateElement(
      state,
      action: PayloadAction<{
        uuid: string;
        updates: Partial<Pick<FormElement, 'machineName' | 'properties'>>;
      }>,
    ) {
      const element = state.elements.find(
        (e) => e.uuid === action.payload.uuid,
      );
      if (!element) return;

      if (action.payload.updates.machineName !== undefined) {
        element.machineName = action.payload.updates.machineName;
      }
      if (action.payload.updates.properties !== undefined) {
        element.properties = action.payload.updates.properties;
      }

      state.isDirty = true;
    },

    updateProperty(
      state,
      action: PayloadAction<{ uuid: string; key: string; value: unknown }>,
    ) {
      const element = state.elements.find(
        (e) => e.uuid === action.payload.uuid,
      );
      if (!element) return;

      if (
        action.payload.key === 'title' &&
        typeof action.payload.value === 'string'
      ) {
        const currentTitle = element.properties.title as string | undefined;
        const existingNames = getExistingMachineNames(
          state.elements,
          element.uuid,
        );
        const currentAutoName = currentTitle
          ? generateMachineName(currentTitle, [])
          : '';
        if (element.machineName === currentAutoName || !element.machineName) {
          element.machineName = generateMachineName(
            action.payload.value,
            existingNames,
          );
        }
      }

      element.properties = {
        ...element.properties,
        [action.payload.key]: action.payload.value,
      };
      state.isDirty = true;
    },

    updateMachineName(
      state,
      action: PayloadAction<{ uuid: string; machineName: string }>,
    ) {
      const element = state.elements.find(
        (e) => e.uuid === action.payload.uuid,
      );
      if (!element) return;
      element.machineName = action.payload.machineName;
      state.isDirty = true;
    },

    removeElement(state, action: PayloadAction<string>) {
      const toRemove = new Set<string>();
      const collectDescendants = (uuid: string) => {
        toRemove.add(uuid);
        state.elements
          .filter((e) => e.parent === uuid)
          .forEach((child) => collectDescendants(child.uuid));
      };
      collectDescendants(action.payload);

      state.elements = state.elements.filter((e) => !toRemove.has(e.uuid));
      if (
        state.selectedElementUuid &&
        toRemove.has(state.selectedElementUuid)
      ) {
        state.selectedElementUuid = null;
      }
      state.isDirty = true;
    },

    moveElement(
      state,
      action: PayloadAction<{
        uuid: string;
        newParent: string | null;
        afterUuid: string | null;
      }>,
    ) {
      const { uuid, newParent, afterUuid } = action.payload;
      const element = state.elements.find((e) => e.uuid === uuid);
      if (!element) return;

      element.parent = newParent;

      const siblings = state.elements.filter(
        (e) => e.parent === newParent && e.uuid !== uuid,
      );
      siblings.sort((a, b) => a.weight - b.weight);

      if (afterUuid) {
        const afterIndex = siblings.findIndex((s) => s.uuid === afterUuid);
        const insertAt = afterIndex >= 0 ? afterIndex + 1 : siblings.length;
        siblings.splice(insertAt, 0, element);
      } else {
        siblings.unshift(element);
      }

      siblings.forEach((sibling, index) => {
        const el = state.elements.find((e) => e.uuid === sibling.uuid);
        if (el) el.weight = index;
      });

      state.isDirty = true;
    },

    selectElement(state, action: PayloadAction<string | null>) {
      state.selectedElementUuid = action.payload;
    },

    setActiveElement(state, action: PayloadAction<string | null>) {
      state.activeElementUuid = action.payload;
    },

    setLabel(state, action: PayloadAction<string>) {
      state.label = action.payload;
      state.isDirty = true;
    },

    updateSubmitSettings(
      state,
      action: PayloadAction<Partial<SubmitSettings>>,
    ) {
      state.submitSettings = { ...state.submitSettings, ...action.payload };
      state.isDirty = true;
    },

    setSaving(state, action: PayloadAction<boolean>) {
      state.isSaving = action.payload;
    },

    markSaved(state) {
      state.isDirty = false;
      state.isSaving = false;
    },
  },
});

export const {
  loadForm,
  addElement,
  updateElement,
  updateProperty,
  updateMachineName,
  removeElement,
  moveElement,
  selectElement,
  setActiveElement,
  setLabel,
  updateSubmitSettings,
  setSaving,
  markSaved,
} = formBuilderSlice.actions;

export default formBuilderSlice;
