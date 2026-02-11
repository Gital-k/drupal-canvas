export interface LegacyExtension {
  id: string;
  name: string;
  description: string;
  imgSrc: string;
  component?: any;
}

export interface Placement {
  id: string;
  location: string;
  label: string;
  action: string;
  message_event?: string;
}

export interface Extension {
  id: string;
  name: string;
  description: string;
  icon?: string;
  url: string;
  type?: 'canvas' | 'code-editor';
  api_version: string;
  permissions?: string[];
  placements?: Placement[];
}

export type ActiveExtension = Extension | null;
