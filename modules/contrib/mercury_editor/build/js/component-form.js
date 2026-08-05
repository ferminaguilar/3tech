(function () {
  'use strict';

  ((Drupal, once) => {
    /**
     * Attaches the behavior to the edit screen.
     */
    Drupal.behaviors.mercuryEditorComponentForm = {
      attach(context) {
        if (context.classList.contains('layout-paragraphs-component-form')) {
          const layoutSelect = context.querySelector(
            '.layout-select input[type="radio"]:checked',
          );
          if (layoutSelect) {
            layoutSelect.focus();
          }
        }
        const form = once(
          'me-component-form',
          'mercury-dialog .layout-paragraphs-component-form',
        )[0];
        if (form) {
          form.closest('mercury-dialog').addEventListener('open', (e) => {
            const dialog = e.target.shadowRoot.querySelector('dialog');
            const w = `${dialog.offsetWidth}px`;
            const h = `${dialog.offsetHeight}px`;
            e.target.style.setProperty('--me-dialog-width-default', w);
            e.target.style.setProperty('--me-dialog-height-default', h);
          });
        }
      },
    };
  })(Drupal, once);

})();
