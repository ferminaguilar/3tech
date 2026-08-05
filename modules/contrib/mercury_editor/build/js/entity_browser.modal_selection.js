(function () {
  'use strict';

  /**
   * @file entity_browser.modal_selection.js
   *
   * Closes entity browser mercury-dialog after a selection is made.
   */

  (function (drupalSettings) {

    // For some reason, Entity Browser does not close the modal with Ajax commands.
    // We follow their lead in how they close the modal.
    // This file gets loaded and executes when a selection is made in the modal.

    // This code block is duplicated from Entity Browser's entity_browser.modal_selection.js file.
    parent.jQuery(parent.document)
      .find(':input[data-uuid*=' + drupalSettings.entity_browser.modal.uuid + ']')
      .trigger('entities-selected', [drupalSettings.entity_browser.modal.uuid, drupalSettings.entity_browser.modal.entities])
      .unbind('entities-selected').show();

    // If there is a mercury-dialog element, close it. This code fires even on pages that do not have Mercury Editor so the element may not exist.
    Array.from(parent.document.querySelectorAll('.entity-browser-modal-iframe')).forEach((element) => {
      element.closest('mercury-dialog')?.close();
    });

    // TODO: Find a solution to attach Mercury libraries only to pages that have Mercury Editor.
  })(drupalSettings);

})();
