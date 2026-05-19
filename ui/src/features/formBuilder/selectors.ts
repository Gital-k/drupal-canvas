import type { FormBuilderState } from './types';

type State = { formBuilder: FormBuilderState };

export const selectFormId = (s: State) => s.formBuilder.formId;
export const selectLabel = (s: State) => s.formBuilder.label;
export const selectElements = (s: State) => s.formBuilder.elements;
export const selectSubmitSettings = (s: State) => s.formBuilder.submitSettings;
export const selectSelectedUuid = (s: State) =>
  s.formBuilder.selectedElementUuid;
export const selectIsDirty = (s: State) => s.formBuilder.isDirty;
export const selectIsSaving = (s: State) => s.formBuilder.isSaving;
export const selectActiveElementUuid = (s: State) =>
  s.formBuilder.activeElementUuid;

export const selectSelectedElement = (s: State) => {
  const uuid = s.formBuilder.selectedElementUuid;
  return uuid
    ? (s.formBuilder.elements.find((e) => e.uuid === uuid) ?? null)
    : null;
};

export const selectRootElements = (s: State) =>
  s.formBuilder.elements
    .filter((e) => e.parent === null)
    .sort((a, b) => a.weight - b.weight);

export const selectChildrenOf = (parentUuid: string) => (s: State) =>
  s.formBuilder.elements
    .filter((e) => e.parent === parentUuid)
    .sort((a, b) => a.weight - b.weight);
