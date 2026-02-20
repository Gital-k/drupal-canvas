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

  /**
   * Allows users to re-order their selection with drag+drop.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches behavior to re-order selected media items.
   */
  Drupal.behaviors.MediaLibraryWidgetSortable = {
    attach: function (context) {
      const selection = once('media-library-widget-sortable', '.js-media-library-selection', context);
      selection.forEach((widget) => {
        let preReorderData = [];

        Sortable.create(widget, {
          draggable: '.js-media-library-item',
          handle: '.js-media-library-item-preview',
          onStart: () => {
            try {
              drupalSettings?.canvas?.store?.dispatch({ type: 'mediaLibrary/setReorderingStart' });
              preReorderData = [];
              $(widget).children('.js-media-library-item').each((_, el) => {
                const $el = $(el);
                const delta = $el.attr('data-media-library-item-delta');
                const targetIdField = $el.find('input[name*="[target_id]"]')[0];
                const targetId = targetIdField ? targetIdField.value : null;
                preReorderData.push({ delta, targetId, element: el });
              });
            } catch (error) {
              console.error('Error starting media reorder:', error);
            }
          },
          onEnd: () => {
            try {
              const elementsInNewOrder = $(widget).children('.js-media-library-item').get();

              // Restore the correct target_id values after Sortable moves elements
              elementsInNewOrder.forEach((el) => {
                const $el = $(el);
                const delta = $el.attr('data-media-library-item-delta');
                const originalData = preReorderData.find(d => d.delta === delta);
                if (originalData && originalData.targetId !== null) {
                  const targetIdField = $el.find('input[name*="[target_id]"]')[0];
                  if (targetIdField && targetIdField.value !== originalData.targetId) {
                    targetIdField.value = originalData.targetId;
                    targetIdField.setAttribute('value', originalData.targetId);
                  }
                }
              });
            } catch (error) {
              console.error('Error restoring target_id values:', error);
            }

            try {
              // Rename all fields to temporary names to avoid collisions
              $(widget)
                .children('.js-media-library-item')
                .each((index, child) => {
                  const $child = $(child);
                  const oldDelta = $child.attr('data-media-library-item-delta');

                  $child.find('input, select, textarea').each((_, field) => {
                    const $field = $(field);
                    const oldName = $field.attr('name');
                    if (oldName && oldName.includes('[selection][' + oldDelta + ']')) {
                      const tempName = oldName.replace(
                        '[selection][' + oldDelta + ']',
                        '[selection][TEMP_' + oldDelta + ']'
                      );
                      $field.attr('name', tempName);

                      const oldSelector = $field.attr('data-drupal-selector');
                      if (oldSelector) {
                        const tempSelector = oldSelector.replace(
                          '-selection-' + oldDelta + '-',
                          '-selection-TEMP_' + oldDelta + '-'
                        );
                        $field.attr('data-drupal-selector', tempSelector);
                      }
                    }
                  });

                  $child.find('[data-canvas-media-remove-button]').each((_, button) => {
                    const $button = $(button);
                    const oldName = $button.attr('name');
                    if (oldName && oldName.includes('-' + oldDelta + '-')) {
                      const tempName = oldName.replace('-' + oldDelta + '-', '-TEMP_' + oldDelta + '-');
                      $button.attr('name', tempName);
                    }
                  });
                });

              // Rename from temporary names to final names
              $(widget)
                .children('.js-media-library-item')
                .each((index, child) => {
                  const $child = $(child);
                  const oldDelta = $child.attr('data-media-library-item-delta');

                  $child.attr('data-media-library-item-delta', index);

                  $child.find('input, select, textarea').each((_, field) => {
                    const $field = $(field);
                    const tempName = $field.attr('name');
                    if (tempName && tempName.includes('[selection][TEMP_' + oldDelta + ']')) {
                      const newName = tempName.replace(
                        '[selection][TEMP_' + oldDelta + ']',
                        '[selection][' + index + ']'
                      );
                      $field.attr('name', newName);

                      const tempSelector = $field.attr('data-drupal-selector');
                      if (tempSelector) {
                        const newSelector = tempSelector.replace(
                          '-selection-TEMP_' + oldDelta + '-',
                          '-selection-' + index + '-'
                        );
                        $field.attr('data-drupal-selector', newSelector);
                      }
                    }
                  });

                  $child.find('[data-canvas-media-remove-button]').each((_, button) => {
                    const $button = $(button);
                    const tempName = $button.attr('name');
                    if (tempName && tempName.includes('-TEMP_' + oldDelta + '-')) {
                      const newName = tempName.replace('-TEMP_' + oldDelta + '-', '-' + index + '-');
                      $button.attr('name', newName);
                    }
                  });

                  let weightField = $child.find('.js-media-library-item-weight')[0];
                  if (!weightField) {
                    weightField = $child.find('input[name*="[weight]"]')[0];
                  }
                  if (weightField) {
                    weightField.setAttribute('data-pending-weight', index);
                  }
                });

              $(widget).children().each((_, child) => {
                const $child = $(child);
                const weightField = $child.find('input[name*="[weight]"]')[0];
                if (weightField) {
                  const pendingWeight = weightField.getAttribute('data-pending-weight');
                  if (pendingWeight !== null) {
                    weightField.value = pendingWeight;
                    weightField.setAttribute('value', pendingWeight);
                    weightField.removeAttribute('data-pending-weight');
                  }
                }
              });
            } catch (error) {
              console.error('Error renaming fields during reorder:', error);
            }

            // Handle post-reorder updates asynchronously
            (async () => {
              try {
                await new Promise(resolve => setTimeout(resolve, 10));

                // Dispatch weight field events
                const weightFields = $(widget).find('input[name*="[weight]"]').get();
                weightFields.forEach((weightField) => {
                  const inputEvent = new Event('input', { bubbles: true, composed: true });
                  const changeEvent = new Event('change', { bubbles: true, composed: true });
                  weightField.dispatchEvent(inputEvent);
                  weightField.dispatchEvent(changeEvent);
                });

                await new Promise(resolve => setTimeout(resolve, 50));

                // Dispatch target_id field events
                const targetIdFields = $(widget).find('input[name*="[target_id]"]').get();
                targetIdFields.forEach((targetIdField) => {
                  const inputEvent = new Event('input', { bubbles: true, composed: true });
                  const changeEvent = new Event('change', { bubbles: true, composed: true });
                  targetIdField.dispatchEvent(inputEvent);
                  targetIdField.dispatchEvent(changeEvent);
                });

                await new Promise(resolve => setTimeout(resolve, 100));

                // Re-sort items by weight
                const items = $(widget).children('.js-media-library-item').get();
                items.sort((a, b) => {
                  const weightA = parseInt($(a).find('input[name*="[weight]"]').val() || '0', 10);
                  const weightB = parseInt($(b).find('input[name*="[weight]"]').val() || '0', 10);
                  return weightA - weightB;
                });
                items.forEach((item) => {
                  $(widget).append(item);
                });

                await new Promise(resolve => setTimeout(resolve, 10000));

                // Clean up reordering flag
                drupalSettings?.canvas?.store?.dispatch({ type: 'mediaLibrary/setReorderingEnd' });
                $(widget).attr('data-last-reorder-time', Date.now().toString());

              } catch (error) {
                console.error('Error dispatching events during reorder:', error);
                // Clean up reordering flag even if there's an error
                drupalSettings?.canvas?.store?.dispatch({ type: 'mediaLibrary/setReorderingEnd' });
                $(widget).attr('data-last-reorder-time', Date.now().toString());
              }
            })();

          },
        });
      });
    },
  };

  /**
   * Allows selection order to be set without drag+drop for accessibility.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches behavior to toggle the weight field for media items.
   */
  Drupal.behaviors.MediaLibraryWidgetToggleWeight = {
    attach: function (context) {
      const strings = {
        show: Drupal.t('Show media item weights'),
        hide: Drupal.t('Hide media item weights'),
      };
      const mediaLibraryToggle = once(
        'media-library-toggle',
        '.js-media-library-widget-toggle-weight',
        context,
      );
      $(mediaLibraryToggle).on('click', (e) => {
        e.preventDefault();
        const $target = $(e.currentTarget);
        e.currentTarget.textContent = $target.hasClass('active')
          ? strings.show
          : strings.hide;
        $target
          .toggleClass('active')
          .closest('.js-media-library-widget')
          .find('.js-media-library-item-weight')
          .parent()
          .toggle();
      });
      mediaLibraryToggle.forEach((item) => {
        item.textContent = strings.show;
      });

      $(once('media-library-toggle', '.js-media-library-item-weight', context))
        .parent()
        .hide();
    },
  };

  /**
   * Disable the open button when the user is not allowed to add more items.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches behavior to disable the media library open button.
   */
  Drupal.behaviors.MediaLibraryWidgetDisableButton = {
    attach: function (context) {
      // When the user returns from the modal to the widget, we want to shift
      // the focus back to the open button. If the user is not allowed to add
      // more items, the button needs to be disabled. Since we can't shift the
      // focus to disabled elements, the focus is set back to the open button
      // via JavaScript by adding the 'data-disabled-focus' attribute.
      once(
        'media-library-disable',
        '.js-media-library-open-button[data-disabled-focus="true"]',
        context,
      ).forEach((button) => {
        $(button).focus();

        // There is a small delay between the focus set by the browser and the
        // focus of screen readers. We need to give screen readers time to shift
        // the focus as well before the button is disabled.
        setTimeout(() => {
          $(button).attr('disabled', 'disabled');
        }, 50);
      });
    },
  };

})(Drupal, jQuery, Sortable, once);
