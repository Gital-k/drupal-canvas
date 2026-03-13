/**
 * @file
 * Overrides Drupal's media library widget sortable behavior for Canvas.
 *
 * Canvas uses a different field structure for multi-cardinality fields where
 * weight fields are named with patterns like `field_name[delta][weight]` or
 * `field_name[delta][_weight]` instead of having the
 * `.js-media-library-item-weight` class. This override ensures that
 * drag-and-drop reordering works correctly with Canvas's Redux-integrated
 * field widgets.
 */

(function (Drupal, $, Sortable, once) {
  'use strict';

  const oldBehavior = Drupal.behaviors.MediaLibraryWidgetSortable;
  /**
   * Allows users to re-order their selection with drag+drop.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches behavior to re-order selected media items.
   */
  Drupal.behaviors.MediaLibraryWidgetSortable = {
    attach: function (context, settings) {
      // If we're inside a Canvas form, skip the original behavior
      if (context.closest('[data-form-id]')) {
        return;
      }

      // Otherwise, run the original Drupal behavior
      if (oldBehavior && oldBehavior.attach) {
        oldBehavior.attach.call(this, context, settings);
      }
    },
  };

})(Drupal, jQuery, Sortable, once);
