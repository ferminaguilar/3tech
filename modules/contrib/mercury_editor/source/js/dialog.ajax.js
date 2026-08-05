/**
 * @file
 * Extends the Drupal dialog AJAX functionality to integrate the mercury dialog.
 */

(($, Drupal) => {
  /**
   * Command to open a dialog.
   *
   * @param {Drupal.Ajax} ajax
   *   The Drupal Ajax object.
   * @param {object} response
   *   Object holding the server response.
   * @param {number} [status]
   *   The HTTP status code.
   *
   * @return {bool|undefined}
   *   Returns false if there was no selector property in the response object.
   */
  Drupal.AjaxCommands.prototype.openMercuryDialog = function openMercuryDialog(
    ajax,
    response,
    status,
  ) {
    if (!response.selector) {
      return false;
    }
    $(response.selector).remove();
    const $dialog = $(
      `<mercury-dialog id="${response.selector.replace(
        /^#/,
        '',
      )}" class="ui-front"></mercury-dialog>`,
    ).appendTo('body');
    // Set up the wrapper, if there isn't one.
    if (!ajax.wrapper) {
      ajax.wrapper = $dialog.attr('id');
    }

    // Use the ajax.js insert command to populate the dialog contents.
    response.command = 'insert';
    response.method = 'html';
    ajax.commands.insert(ajax, response, status);

    // By default drupalAutoButtons is true.
    if (typeof response.dialogOptions.drupalAutoButtons === 'undefined') {
      response.dialogOptions.drupalAutoButtons = true;
    } else if (response.dialogOptions.drupalAutoButtons === 'false') {
      // Boolean 'false' values in Ajax post parameters are interpreted
      // as string literals in Drupal\Core\Render\MainContent\DialogRenderer and
      // need to be converted back to boolean false.
      // @see https://www.drupal.org/project/drupal/issues/3014136
      // @see https://www.drupal.org/project/drupal/issues/2793343
      response.dialogOptions.drupalAutoButtons = false;
    } else {
      // Force boolean value.
      response.dialogOptions.drupalAutoButtons =
        !!response.dialogOptions.drupalAutoButtons;
    }
    // If drupalAutoButtons is true and the buttons option is not explicity set,
    // move any form action buttons to the jQuery UI dialog buttons area.
    if (
      !response.dialogOptions.buttons &&
      response.dialogOptions.drupalAutoButtons
    ) {
      response.dialogOptions.buttons =
        Drupal.behaviors.mercuryDialog.prepareDialogButtons($dialog);
    }

    // Bind dialogButtonsChange.
    $dialog.on('dialogButtonsChange', () => {
      if ($dialog[0].tagName !== 'MERCURY-DIALOG') {
        return;
      }
      const buttons =
        Drupal.behaviors.mercuryDialog.prepareDialogButtons($dialog);
      Drupal.mercuryDialog($dialog[0]).applyOptions({ buttons });
    });

    // Open the dialog itself.
    response.dialogOptions = response.dialogOptions || {};
    const dialogElement = $dialog.get(0);
    // If the width is set to auto, set it to fit-content instead.
    if (response.dialogOptions.width === 'auto') {
      response.dialogOptions.width = 'fit-content';
    }
    const dialog = Drupal.mercuryDialog(dialogElement, response.dialogOptions);
    const open = dialogElement.getAttribute('open');
    if (response.dialogOptions.modal && !open) {
      dialog.showModal();
    } else {
      dialog.show();
    }
  };

  /**
   * Command to close a dialog.
   *
   * @param {Drupal.Ajax} ajax
   *   The Drupal Ajax object.
   * @param {object} response
   *   Object holding the server response.
   * @param {number} [status]
   *   The HTTP status code.
   *
   * @return {bool|undefined}
   *   Returns false if there was no selector property in the response object.
   */
  Drupal.AjaxCommands.prototype.closeMercuryDialog =
    function closeMercuryDialog(ajax, response) {
      if (!response.selector) {
        return false;
      }
      let dialog = document.querySelector(response.selector);
      if (!dialog) {
        dialog = window.parent.document.querySelector(response.selector);
      }
      if (dialog) {
        Drupal.mercuryDialog(dialog).close();

        if (!response.persist) {
          dialog.remove();
        }
      }
    };

  /**
   * Overrides the core closeDialog command.
   *
   * In some cases (for example, when using the media library), the mercury
   * editor dialog is opened by a route that is not explicitly related to
   * mercury editor. We need a way to properly close the dialog when the
   * core closeDialog command is called on a mercury editor dialog element.
   */
  Drupal.AjaxCommands.prototype.coreCloseDialog =
    Drupal.AjaxCommands.prototype.closeDialog;
  Drupal.AjaxCommands.prototype.closeDialog = function closeDialog(
    ajax,
    response,
    status,
  ) {
    const dialog = document.querySelector(response.selector);
    if (dialog.tagName === 'MERCURY-DIALOG') {
      return Drupal.AjaxCommands.prototype.closeMercuryDialog(
        ajax,
        response,
        status,
      );
    }
    return Drupal.AjaxCommands.prototype.coreCloseDialog(
      ajax,
      response,
      status,
    );
  };
})(jQuery, Drupal);
