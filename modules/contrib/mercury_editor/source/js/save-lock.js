((Drupal, $) => {
  let owner = null;
  let restore = [];
  let waiting = [];

  const isLocked = () => owner !== null;
  const isSave = (element) =>
    element?.name === 'mercury_editor_save' &&
    element.closest('.me-entity-form');

  function unlock() {
    if (!isLocked()) return;
    owner = null;
    restore.forEach((callback) => callback());
    restore = [];
    document.body.classList.remove('me-saving');
    const callbacks = waiting;
    waiting = [];
    callbacks.forEach((callback) => callback());
  }

  function lock(ajax) {
    owner = ajax;
    document.body.classList.add('me-saving');
    // Inert blocks pointer and keyboard editing, including the preview iframe.
    // Explicitly cover dialogs, which can escape an inert ancestor when modal.
    [
      document.body,
      ...document.querySelectorAll('dialog, mercury-dialog'),
    ].forEach((element) => {
      const inert = element.inert;
      element.inert = true;
      restore.push(() => {
        element.inert = inert;
      });
    });
    const busy = document.body.getAttribute('aria-busy');
    document.body.setAttribute('aria-busy', 'true');
    restore.push(() => {
      if (busy === null) document.body.removeAttribute('aria-busy');
      else document.body.setAttribute('aria-busy', busy);
    });
    document
      .querySelectorAll('#me-save-btn, #me-undo, #me-redo')
      .forEach((button) => {
        const disabled = button.disabled;
        button.disabled = true;
        restore.push(() => {
          button.disabled = disabled;
        });
      });
  }

  Drupal.mercuryEditorSaveLock = {
    isLocked,
    afterUnlock(callback) {
      if (isLocked()) waiting.push(callback);
      else callback();
    },
  };

  // Guard Drupal's request entry points, including programmatic executions.
  ['eventResponse', 'execute'].forEach((method) => {
    const original = Drupal.Ajax.prototype[method];
    Drupal.Ajax.prototype[method] = function guardedRequest(...args) {
      if (isLocked() || (isSave(this.element) && $.active > 0)) {
        args[1]?.preventDefault();
        args[1]?.stopPropagation();
        return method === 'execute' ? $.Deferred().reject().promise() : false;
      }
      try {
        const result = original.apply(this, args);
        // Form serialization can be cancelled before a request starts.
        if (owner === this && !this.ajaxing) unlock();
        return result;
      } catch (error) {
        if (owner === this) unlock();
        throw error;
      }
    };
  });

  const beforeSerialize = Drupal.Ajax.prototype.beforeSerialize;
  Drupal.Ajax.prototype.beforeSerialize = function serializeForSave(
    element,
    options,
  ) {
    if (isSave(this.element)) {
      lock(this);
      options.mercuryEditorSaveRequest = true;
    }
    try {
      const result = beforeSerialize.call(this, element, options);
      if (result === false && owner === this) unlock();
      return result;
    } catch (error) {
      if (owner === this) unlock();
      throw error;
    }
  };

  const beforeSubmit = Drupal.Ajax.prototype.beforeSubmit;
  Drupal.Ajax.prototype.beforeSubmit = function submitForSave(...args) {
    try {
      const result = beforeSubmit.apply(this, args);
      if (result === false && owner === this) {
        this.ajaxing = false;
        unlock();
      }
      return result;
    } catch (error) {
      if (owner === this) unlock();
      throw error;
    }
  };

  const success = Drupal.Ajax.prototype.success;
  Drupal.Ajax.prototype.success = function saveSuccess(...args) {
    const saving = owner === this;
    try {
      const result = success.apply(this, args);
      return saving ? Promise.resolve(result).finally(unlock) : result;
    } catch (error) {
      if (saving) unlock();
      throw error;
    }
  };

  const error = Drupal.Ajax.prototype.error;
  Drupal.Ajax.prototype.error = function saveError(...args) {
    try {
      return error.apply(this, args);
    } finally {
      if (owner === this) unlock();
    }
  };

  // Cover raw jQuery requests and cancellation/transport failure. Drupal delays
  // ajaxComplete on success until its response commands have finished running.
  $.ajaxPrefilter((options) => {
    if (isLocked() && !options.mercuryEditorSaveRequest) {
      options.beforeSend = () => false;
    }
  });
  $(document).on('ajaxComplete.meSaveLock', (event, xhr, options) => {
    if (options.mercuryEditorSaveRequest) unlock();
  });

  // Also block synthetic events and native form submissions while saving.
  ['click', 'mousedown', 'keydown', 'submit'].forEach((type) => {
    document.addEventListener(
      type,
      (event) => {
        if (isLocked()) {
          event.preventDefault();
          event.stopImmediatePropagation();
        } else if (
          type === 'submit' &&
          event.target?.matches('.me-entity-form') &&
          (!event.submitter || isSave(event.submitter))
        ) {
          // Route implicit Enter-key submissions through the guarded AJAX save.
          const button =
            event.submitter ||
            event.target.querySelector('[name="mercury_editor_save"]');
          if (button) {
            event.preventDefault();
            button.dispatchEvent(
              new Event('mousedown', { bubbles: true, cancelable: true }),
            );
          }
        }
      },
      true,
    );
  });
})(Drupal, jQuery);
