import type { CodeComponentSerialized } from '@drupal-canvas/ui/types/CodeComponent';

/**
 * Folder placement synced with Drupal (see Canvas issue: store in component.yml).
 *
 * - **String**: same folder name in the component library (when exposed) and
 *   in the code panel.
 * - **Object**: `library` and/or `code` folder names independently.
 * - **null**: clear both when sent on push (PATCH/POST body).
 */
export type ComponentFolderMetadata =
  | string
  | null
  | {
      library?: string | null;
      code?: string | null;
    };

export interface Metadata extends Pick<
  CodeComponentSerialized,
  'name' | 'machineName' | 'status' | 'required' | 'slots'
> {
  props: {
    properties: CodeComponentSerialized['props'];
  };
  folder?: ComponentFolderMetadata;
}
