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

      // Set once per page, whichever Scolta block is on it: with both the
      // search and the chat blocks, drupalSettings has already merged the two
      // into one object, and the chat widget reads it on DOMContentLoaded,
      // after this behavior has run.
      if (!window.scolta) {
        window.scolta = settings.scolta;
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

      if (typeof window.Scolta === 'undefined' || typeof window.Scolta.init !== 'function') {
        console.warn('[scolta] scolta.js not loaded. Check library attachments.');
        return;
      }

      window.Scolta.init('#scolta-search');
    }
  };
})(Drupal, drupalSettings);
