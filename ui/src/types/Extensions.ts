export interface LegacyExtension {
  id: string;
  name: string;
  description: string;
  imgSrc: string;
  component?: any;
  showNotification?: boolean;
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
  showNotification?: boolean;
}

export type ActiveExtension = Extension | null;
