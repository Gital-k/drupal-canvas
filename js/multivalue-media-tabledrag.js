/**
 * @file
 * Overrides the media library widget's drag-to-reorder on multivalue fields
 * to use the Canvas six-dot drag handle instead of the thumbnail preview.
 *
 * For non-Canvas widgets the original core behavior is preserved verbatim.
 *
 * Keyboard reordering: when focus is on a .canvas-media-drag-handle inside a
 * Canvas multivalue media widget, ArrowUp/ArrowDown move the item and update
 * the hidden weight inputs so the server-side order matches.
 */

(function (Drupal, Sortable, once) {
  'use strict';

  /**
   * Synchronizes the hidden weight inputs after a DOM reorder.
   *
   * @param {HTMLElement} container
   *   The .js-media-library-selection element.
   */
  function updateWeights(container) {
    Array.from(container.children).forEach((child, index) => {
      // In canvas_stark, input.html.twig renders all inputs as
      // <drupal-canvas-input>. After HyperscriptifyAdditional processes it,
      // the original element is *removed* from the DOM and replaced by a
      // <drupal-html-fragment> containing the React-rendered <input>. The
      // class js-media-library-item-weight is lost in this process.
      // The weight field is the only type="number" input per item, so we
      // locate it that way.
      const w =
        child.querySelector('input[type="number"]') ||
        child.querySelector('.js-media-library-item-weight');
      if (w) w.value = index;
    });
  }

  /**
   * Attaches arrow-key reorder support to every .canvas-media-drag-handle
   * inside the given Canvas media selection container.
   *
   * @param {HTMLElement} container
   *   The .js-media-library-selection element.
   */
  function attachKeyboardReorder(container) {
    once('canvas-media-keyboard', container).forEach((el) => {
      el.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') {
          return;
        }

        const handle = event.target.closest('.canvas-media-drag-handle');
        if (!handle) {
          return;
        }

        event.preventDefault();

        const item = handle.closest('.js-media-library-item');
        if (!item) {
          return;
        }

        if (event.key === 'ArrowUp') {
          const prev = item.previousElementSibling;
          if (prev) {
            el.insertBefore(item, prev);
          }
        } else {
          const next = item.nextElementSibling;
          if (next) {
            el.insertBefore(next, item);
          }
        }

        updateWeights(el);
        handle.focus();
      });
    });
  }

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
            forceFallback: true,
            onEnd() {
              updateWeights(el);
            },
          });
        });

        if (isCanvas) {
          attachKeyboardReorder(widget);
        }
      });
    },
  };

})(Drupal, Sortable, once);
