((Drupal, drupalSettings, $) => {
  let ajaxing = false;
  function setAjaxing(value) {
    ajaxing = value;
    document.body.classList.toggle('me-ajaxing', value);
  }
  $(document).on('ajaxStart.meAjaxGuard', () => setAjaxing(true));
  $(document).on('ajaxStop.meAjaxGuard', () => setAjaxing(false));

  let interacting = false;
  Drupal.mercuryEditorBus.on('component-event:drag', () => {
    interacting = true;
  });
  Drupal.mercuryEditorBus.on('component-event:drop', () => {
    interacting = false;
  });

  /**
   * Serializes a form.
   * @param {HTMLElement} form The form element.
   * @return {string} The serialized form data.
   */
  function serializeForm(form) {
    // JavaScript editors like CKEditor and CodeMirror save changes to text areas
    // when behaviors are detached. So we need to detach behaviors first, then
    // serialize the form.
    Drupal.detachBehaviors(form, drupalSettings, 'serialize');
    // Drupal.attachBehaviors(form, drupalSettings);
    let formData = Object.fromEntries(new FormData(form).entries());
    formData = Object.fromEntries(
      Object.entries(formData).filter(
        ([, value]) => value !== '' && value !== null && value !== undefined,
      ),
    );
    formData = Object.keys(formData)
      .sort()
      .reduce((obj, key) => {
        obj[key] = formData[key];
        return obj;
      }, {});
    // Remove non-essential keys to avoid false positives.
    ['form_build_id', 'form_id', 'form_token', 'tabs'].forEach((key) => {
      delete formData[key];
    });
    return JSON.stringify(formData);
  }

  /**
   * Debounced function to submit form when it changes.
   * @param {Element} form The form.
   * @return {boolean} True if the form has changed.
   */
  function checkFormForChanges(form) {
    if (
      interacting ||
      ajaxing ||
      !drupalSettings.ajaxPreviewPageState ||
      !form.serializedData ||
      form.hasAttribute('data-me-saving') ||
      form.querySelector('input[type="file"][disabled]')
    ) {
      return false;
    }
    const serializedData = serializeForm(form);
    // No changes detected.
    if (form.serializedData === serializedData) {
      return false;
    }
    form.serializedData = serializedData;
    return true;
  }
  let intervalId = null;
  function startAutoSaveInterval() {
    if (intervalId) {
      clearInterval(intervalId);
    }
    intervalId = setInterval(() => {
      if (ajaxing) {
        return;
      }
      const autosaveForm =
        document.querySelector(
          'mercury-dialog .layout-paragraphs-component-form',
        ) || document.querySelector('mercury-dialog .me-entity-form');
      if (!autosaveForm) {
        return;
      }
      if (!autosaveForm.serializedData) {
        autosaveForm.serializedData = serializeForm(autosaveForm);
        return;
      }
      autosaveForm.classList.add('me-autosave-initialized');
      if (!checkFormForChanges(autosaveForm)) {
        return;
      }
      const saveButton = autosaveForm.querySelector('.me-autosave-btn');
      if (saveButton) {
        // If there is an autosave button, click it to trigger the save.
        saveButton.dispatchEvent(new Event('mousedown'));
      } else {
        // Otherwise, just mark the form as updated.
        autosaveForm.setAttribute('data-form-changed', 'true');
      }
    }, 500);
  }
  Drupal.behaviors.zzMeAutosave = {
    attach(context) {
      if (context === document) {
        startAutoSaveInterval();
      }
    },
  };
})(Drupal, drupalSettings, jQuery);
