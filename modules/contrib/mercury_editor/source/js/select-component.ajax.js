((Drupal) => {
  /**
   * Ajax command for removing a class from matching element(s).
   * @param {Drupal.Ajax} [ajax] An Ajax object.
   * @param {object} response The response object.
   */
  Drupal.AjaxCommands.prototype.mercuryEditorSelectComponent = (
    ajax,
    response,
  ) => {
    if (response.uuid === undefined) {
      return;
    }
    Drupal.mercuryEditorBus.post('preview:selectComponent', {
      uuid: response.uuid,
      forceReload: response.forceReload || false,
    });
  };
})(Drupal);
