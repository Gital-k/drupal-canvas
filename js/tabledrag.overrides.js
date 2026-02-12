/**
 * @file
 * Suppresses Drupal's tabledrag unsaved changes UI elements.
 *
 * Canvas has its own "Review changes" button that provides unsaved changes
 * feedback. This behavior injects CSS to hide Drupal's default tabledrag
 * warning messages, changed indicators, and toggle weight controls.
 *
 * The CSS is injected via JavaScript on first behavior attach to prevent
 * visual flicker while avoiding a separate CSS file dependency.
 */

(function (Drupal) {
  'use strict';

  /**
   * Hides tabledrag UI elements by injecting CSS.
   */
  Drupal.behaviors.canvasTableDragRemoveElements = {
    attach: function () {
      if (!document.getElementById('canvas-tabledrag-hide-style')) {
        const style = document.createElement('style');
        style.id = 'canvas-tabledrag-hide-style';
        style.textContent = '.tabledrag-changed-warning,.tabledrag-changed{display:none!important}';
        document.documentElement.appendChild(style);
      }
    }
  };

})(Drupal);
