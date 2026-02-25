export type DiscoveryWarningCode =
  | 'missing_js_entry'
  | 'duplicate_definition'
  | 'conflicting_metadata';

export interface DiscoveryOptions {
  scanRoot?: string;
}

export interface DiscoveryWarning {
  code: DiscoveryWarningCode;
  message: string;
  path?: string;
}

export interface DiscoveredComponent {
  id: string;
  kind: 'named' | 'index';
  name: string;
  directory: string;
  relativeDirectory: string;
  metadataPath: string;
  jsEntryPath: string | null;
  cssEntryPath: string | null;
}

export interface DiscoveryResult {
  scanRoot: string;
  components: DiscoveredComponent[];
  warnings: DiscoveryWarning[];
  stats: {
    scannedFiles: number;
    ignoredFiles: number;
  };
}

/**
 * Parsed component metadata from a component.yml file.
 */
export interface ComponentMetadata {
  name: string;
  machineName: string;
  status: boolean;
  description: string;
  props: Record<string, unknown>;
  required: string[];
  slots: Record<string, { title: string; description?: string }>;
}
