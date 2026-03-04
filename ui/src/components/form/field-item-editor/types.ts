export interface FieldItem {
  id: string;
  delta: number;
  displayValue: string;
  value: any;
  weight: number;
}

export interface FieldMetadata {
  cardinality: number;
  required: boolean;
  fieldName: string;
  fieldType: string;
}
