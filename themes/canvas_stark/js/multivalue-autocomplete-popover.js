/**
 * @file
 * JavaScript behavior for the multivalue autocomplete popover.
 *
 * Handles opening/closing the popover and syncing the preview text
 * with the actual input value.
 */

(function (Drupal) {
  'use strict';

  Drupal.behaviors.multivalueAutocompletePopover = {
    attach: function (context) {
      const wrappers = context.querySelectorAll('.multivalue-autocomplete-wrapper:not([data-popover-initialized])');

      wrappers.forEach(function (wrapper) {
        wrapper.setAttribute('data-popover-initialized', 'true');

        const trigger = wrapper.querySelector('.multivalue-autocomplete-trigger');
        const popover = wrapper.querySelector('.multivalue-autocomplete-popover');
        const closeBtn = wrapper.querySelector('.multivalue-autocomplete-popover-close');
        const removeBtn = wrapper.querySelector('.multivalue-autocomplete-remove-btn');
        const input = wrapper.querySelector('.multivalue-autocomplete-input-wrapper input');
        const preview = wrapper.querySelector('.multivalue-autocomplete-preview');

        if (!trigger || !popover || !input) {
          return;
        }

        /**
         * Position the popover relative to the trigger using fixed positioning.
         * Opens to the left of the trigger to avoid being clipped by the sidebar.
         */
        function positionPopover() {
          const triggerRect = trigger.getBoundingClientRect();
          const popoverWidth = 235;
          const gap = 8;

          // Position to the left of the trigger
          let left = triggerRect.left - popoverWidth - gap;
          let top = triggerRect.top;

          // If it would go off the left edge, position to the right instead
          if (left < 10) {
            left = triggerRect.right + gap;
          }

          // Ensure it doesn't go off the bottom of the viewport
          const viewportHeight = window.innerHeight;
          const popoverHeight = 140;
          if (top + popoverHeight > viewportHeight - 10) {
            top = viewportHeight - popoverHeight - 10;
          }

          // Ensure it doesn't go off the top
          if (top < 10) {
            top = 10;
          }

          popover.style.left = left + 'px';
          popover.style.top = top + 'px';
        }

        /**
         * Close the popover.
         */
        function closePopover() {
          wrapper.classList.remove('is-open');
          trigger.setAttribute('aria-expanded', 'false');
        }

        // Open popover on trigger click
        trigger.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();

          // Close any other open popovers first
          document.querySelectorAll('.multivalue-autocomplete-wrapper.is-open').forEach(function (otherWrapper) {
            if (otherWrapper !== wrapper) {
              otherWrapper.classList.remove('is-open');
              otherWrapper.querySelector('.multivalue-autocomplete-trigger').setAttribute('aria-expanded', 'false');
            }
          });

          const willOpen = !wrapper.classList.contains('is-open');
          wrapper.classList.toggle('is-open');
          trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');

          if (willOpen) {
            positionPopover();
            // Focus the input when opening
            setTimeout(function () {
              input.focus();
              input.select();
            }, 0);
          }
        });

        // Handle close button click
        if (closeBtn) {
          closeBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            closePopover();
            trigger.focus();
          });
        }

        // Handle Remove button click
        if (removeBtn) {
          removeBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            // Close the popover first
            closePopover();

            // Find the Drupal remove button in the table row and click it
            const tableRow = wrapper.closest('tr');
            if (tableRow) {
              const removeActionCell = tableRow.querySelector('.canvas-remove-action');
              if (removeActionCell) {
                const drupalRemoveButton = removeActionCell.querySelector('input[type="submit"][name*="remove"]');
                if (drupalRemoveButton) {
                  const mousedownEvent = new MouseEvent('mousedown', {
                    bubbles: true,
                    cancelable: true,
                    view: window,
                  });
                  drupalRemoveButton.dispatchEvent(mousedownEvent);
                  drupalRemoveButton.click();
                  return;
                }
              }
            }

            // Fallback: clear the input value
            input.value = '';
            updatePreview();
          });
        }

        // Update preview when input value changes
        input.addEventListener('input', function () {
          updatePreview();
        });

        // Also update on autocomplete select (jQuery UI fires 'autocompleteselect')
        if (window.jQuery) {
          window.jQuery(input).on('autocompleteselect autocompletechange', function () {
            setTimeout(updatePreview, 0);
          });
        }

        // Close on Enter key in input
        input.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') {
            // Let the autocomplete handle Enter if it's open
            setTimeout(function () {
              updatePreview();
              closePopover();
              trigger.focus();
            }, 100);
          } else if (e.key === 'Escape') {
            closePopover();
            trigger.focus();
          }
        });

        // Close when clicking outside
        document.addEventListener('click', function (e) {
          if (!wrapper.contains(e.target) && !popover.contains(e.target)) {
            closePopover();
          }
        });

        function updatePreview() {
          const value = input.value.trim();
          preview.textContent = value || 'Empty';
        }

        // Initial preview update
        updatePreview();
      });
    }
  };

})(Drupal);

