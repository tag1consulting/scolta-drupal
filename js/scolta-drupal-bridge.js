/**
 * @file
 * Bridges drupalSettings.scolta to window.scolta for the platform-agnostic
 * scolta.js search engine.
 *
 * Drupal injects configuration via drupalSettings (attached from PHP).
 * scolta.js reads from window.scolta. This behavior copies the settings
 * and calls Scolta.init() once the container element is present.
 */
(function (Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.scolta = {
    attach: function (context) {
      // Only run once on the full document, not on AJAX partial inserts.
      if (context !== document) {
        return;
      }

      if (drupalSettings.scolta) {
        window.scolta = drupalSettings.scolta;
      }

      // Initialize Scolta if the container exists and Scolta is loaded.
      var container = document.querySelector('#scolta-search');
      if (container && typeof window.Scolta !== 'undefined' && typeof window.Scolta.init === 'function') {
        window.Scolta.init('#scolta-search');
      }
    }
  };
})(Drupal, drupalSettings);
