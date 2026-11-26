(function () {
  'use strict';

  ((Drupal, drupalSettings, $) => {
    let ajaxing = false;
    function setAjaxing(value) {
      ajaxing = value;
      document.body.classList.toggle('me-ajaxing', value);
      if (!value) {
        document.dispatchEvent(new Event('mercury-editor:ajax-idle'));
      }
    }
    $(document).on('ajaxStart.meAjaxGuard', () => setAjaxing(true));
    $(document).on('ajaxStop.meAjaxGuard', () => setAjaxing(false));
    $.ajaxPrefilter((options) => {
      document.dispatchEvent(
        new CustomEvent('mercury-editor:ajax-options', { detail: options }),
      );
    });

    // Both busy classes are added to document.body when a counted request starts
    // (dialog-opening requests are excluded below). Their CSS lives in
    // source/css/edit-screen.css:
    // - me-ajax-busy shows the toolbar indicator and dims the preview if the
    //   request lasts more than 500ms. It is removed BUSY_HIDE_DELAY after the last
    //   counted request completes, when the live status announcement also clears.
    // - me-preview-busy blocks pointer interaction with the preview iframe and
    //   adds a transparent overlay. It remains for PREVIEW_INTERACTION_DELAY after
    //   me-ajax-busy is removed, letting the preview settle before accepting clicks.
    // New counted requests cancel both removal timers. The separate lifetimes
    // prevent indicator flicker and premature preview interaction between requests.
    // These classes do not lock the whole editor; save-lock.js handles that.
    // The `ajaxing` flag above independently prevents overlapping autosaves.
    let busyCount = 0;
    let busyHideTimer = null;
    let previewInteractionTimer = null;
    const BUSY_HIDE_DELAY = 100;
    const PREVIEW_INTERACTION_DELAY = 700;

    const announceBusyState = () => {
      const statusElement = document.getElementById('me-ajax-status');
      if (statusElement) {
        statusElement.textContent = Drupal.t('Updating…');
      }
    };

    const clearBusyAnnouncement = () => {
      const statusElement = document.getElementById('me-ajax-status');
      if (statusElement) {
        statusElement.textContent = '';
      }
    };

    // Opening the form or dialog for a component changes nothing, so it must not
    // dim the preview or stop it taking clicks. Doing so made every selection
    // feel slow, as the preview stayed locked until the form had loaded and a
    // short time after. Submitting a form still counts as busy, as it changes
    // the content.
    const OPENS_DIALOG = /\/action\/(edit|insert|delete)(\/|\?|$)/;
    const opensDialog = (options) => {
      const data = typeof options?.data === 'string' ? options.data : '';
      return OPENS_DIALOG.test(options?.url || '') && !data.includes('form_build_id');
    };

    const setBusy = (event, xhr, options) => {
      if (opensDialog(options)) {
        if (xhr) {
          xhr.meSkipBusy = true;
        }
        return;
      }
      clearTimeout(busyHideTimer);
      clearTimeout(previewInteractionTimer);

      if (busyCount === 0) {
        document.body.classList.add('me-ajax-busy');
        document.body.classList.add('me-preview-busy');
        announceBusyState();
      }

      busyCount += 1;
    };

    const clearBusy = (event, xhr) => {
      if (xhr?.meSkipBusy) {
        return;
      }
      busyCount = Math.max(0, busyCount - 1);

      if (busyCount !== 0) {
        return;
      }

      clearTimeout(busyHideTimer);
      busyHideTimer = setTimeout(() => {
        if (busyCount === 0) {
          document.body.classList.remove('me-ajax-busy');
          clearBusyAnnouncement();
          previewInteractionTimer = setTimeout(() => {
            if (busyCount === 0) {
              document.body.classList.remove('me-preview-busy');
            }
          }, PREVIEW_INTERACTION_DELAY);
        }
      }, BUSY_HIDE_DELAY);
    };

    $(document)
      .off('.meAjaxBusy')
      .on('ajaxSend.meAjaxBusy', setBusy)
      .on('ajaxComplete.meAjaxBusy', clearBusy);

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
        Drupal.mercuryEditorSaveLock?.isLocked() ||
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
        if (ajaxing || Drupal.mercuryEditorSaveLock?.isLocked()) {
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

})();
