export interface FormElement {
  uuid: string;
  type: string;
  machineName: string;
  properties: Record<string, unknown>;
  weight: number;
  parent: string | null;
}

export interface SubmitSettings {
  button_text: string;
  confirmation_message: string;
  redirect_url: string;
}

export interface FormBuilderState {
  formId: string | null;
  label: string;
  elements: FormElement[];
  submitSettings: SubmitSettings;
  selectedElementUuid: string | null;
  isDirty: boolean;
  isSaving: boolean;
  activeElementUuid: string | null;
}

export interface PropertySchema {
  type:
    | 'string'
    | 'text'
    | 'boolean'
    | 'number'
    | 'select'
    | 'options'
    | 'attributes';
  label: string;
  description?: string;
  default?: unknown;
  required?: boolean;
  group: 'basic' | 'validation' | 'advanced';
  options?: Record<string, string>;
}

export interface FormElementSchema {
  type: string;
  label: string;
  description?: string;
  category: 'text' | 'selection' | 'structural' | 'action';
  icon: string;
  canContainChildren: boolean;
  properties: Record<string, PropertySchema>;
}
