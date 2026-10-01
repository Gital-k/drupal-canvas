/**
 * @file
 * Isomorphic rendered-entity contracts and helpers.
 */

import type {
  CanvasComponentTreeElement,
  DrupalRouteEntity,
  DrupalRouteTranslation,
} from './page';

/** Drupal's rendered answer for one explicit entity target. */
export interface EntityResult {
  content: CanvasComponentTreeElement | null;
  managedByCanvas: boolean;
  entity: DrupalRouteEntity;
  /** The language actually rendered: the `language` option when given and
   * available, otherwise Drupal's ambient negotiated content language. */
  negotiatedLanguage: string;
  /** Empty on monolingual sites, or without a canonical link template. */
  translations: DrupalRouteTranslation[];
}
