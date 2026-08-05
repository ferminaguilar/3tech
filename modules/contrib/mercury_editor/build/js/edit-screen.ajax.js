(function () {
  'use strict';

  ((Drupal) => {
    /**
     * Ajax command wrapper to send a set of ajax commands to iFrame.
     * @param {*} ajax The ajax object.
     * @param {*} response The ajax response.
     * @param {*} status The ajax status.
     */
    Drupal.AjaxCommands.prototype.mercuryEditorEditIframeCommandsWrapper = (
      ajax,
      response,
      status,
    ) => {
      Drupal.mercuryEditorBus.post('preview:ajaxCommands', {
        commands: response.commands,
        status,
      });
    };
  })(Drupal);

})();
