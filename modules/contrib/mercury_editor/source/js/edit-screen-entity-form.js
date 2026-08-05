((Drupal, once) => {
  Drupal.behaviors.mercuryEditorEntityForm = {
    attach(context) {
      if (!once('me-entity-form', '.me-entity-form', context)) {
        return;
      }
      // Add a class to the form if an input, textarea, or select is changed.
      document
        .querySelector('form.me-entity-form')
        .addEventListener('change', (e) => {
          e.target.closest('form').classList.add('unsaved-changes');
        });
      // Warn the user if attempting to leave the page with unsaved changes.
      window.addEventListener('beforeunload', (e) => {
        if (document.querySelector('.me-entity-form.unsaved-changes')) {
          e.preventDefault();
          e.returnValue = '';
        }
      });
    },
  };
})(Drupal, once);
