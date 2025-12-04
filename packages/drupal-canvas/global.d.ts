import type { DrupalSettings } from '../../ui/src/types/DrupalSettings';

declare global {
  interface Window {
    drupalSettings: DrupalSettings;
  }
}
