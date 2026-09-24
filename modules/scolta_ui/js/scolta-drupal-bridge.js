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

  Drupal.behaviors.scoltaSearch = {
    attach: function (context, settings) {
      if (!settings.scolta) {
        return; // Scolta not configured on this page.
      }

      var container = context.querySelector('#scolta-search');
      if (!container) {
        return; // No search widget on this page.
      }

      // Only initialize once per container.
      if (container.dataset.scoltaInitialized) {
        return;
      }
      container.dataset.scoltaInitialized = 'true';

      window.scolta = settings.scolta;

      if (typeof window.Scolta === 'undefined' || typeof window.Scolta.init !== 'function') {
        console.warn('[scolta] scolta.js not loaded. Check library attachments.');
        return;
      }

      window.Scolta.init('#scolta-search');
    }
  };
})(Drupal, drupalSettings);
