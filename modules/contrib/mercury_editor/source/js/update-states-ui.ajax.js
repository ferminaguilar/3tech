((Drupal) => {
  /**
   * Ajax command for removing a class from matching element(s).
   * Note that this runs in either the preview or edit screen, depending on
   * where the command was triggered.
   * @param {Drupal.Ajax} [ajax] An Ajax object.
   * @param {object} response The response object.
   */
  Drupal.AjaxCommands.prototype.mercuryEditorUpdateState = (ajax, response) => {
    if (
      response.stateIndex === undefined ||
      response.stateCount === undefined
    ) {
      return;
    }
    Drupal.mercuryEditorBus.post('editor:updateState', {
      stateIndex: response.stateIndex,
      stateCount: response.stateCount,
    });
  };
})(Drupal, drupalSettings, jQuery, once, Drupal.debounce);
