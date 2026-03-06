/**
 * @file
 * Overrides the media library widget's drag-to-reorder on multivalue fields
 * to use the Canvas six-dot drag handle instead of the thumbnail preview.
 *
 * For non-Canvas widgets the original core behaviour is preserved verbatim.
 */

(function (Drupal, Sortable, once) {
  'use strict';

  Drupal.behaviors.MediaLibraryWidgetSortable = {
    attach(context) {
      const selections = context.querySelectorAll
        ? Array.from(context.querySelectorAll('.js-media-library-selection'))
        : [];

      selections.forEach((widget) => {
        const isCanvas = widget.closest('.canvas-multivalue-media') !== null;

        once(
          isCanvas ? 'canvas-media-sortable' : 'media-library-sortable',
          widget,
        ).forEach((el) => {
          Sortable.create(el, {
            draggable: '.js-media-library-item',
            handle: isCanvas
              ? '.canvas-media-drag-handle'
              : '.js-media-library-item-preview',
            onEnd() {
              Array.from(el.children).forEach((child, index) => {
                const w = child.querySelector('.js-media-library-item-weight');
                if (w) w.value = index;
              });
            },
          });
        });
      });
    },
  };

})(Drupal, Sortable, once);
