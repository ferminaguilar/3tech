import { trackFocus, restoreFormFocus } from './focus-manager';

((Drupal) => {
  /**
   * Generates the API URL for a given action.
   * @param {String} action The action to perform.
   * @param {Object} options The options.
   * @param {Object} extraParams Extra GET parameters to add to the URL.
   * @return {String} The API URL.
   */
  function apiUrl(action, options, extraParams = {}) {
    const { meId, layoutId } = options;
    const drupalUrl = options.url
      ? options.url
      : Drupal.url(
          options.uuid
            ? `mercury-editor/${meId}/${layoutId}/action/${action}/${options.uuid}`
            : `mercury-editor/${meId}/${layoutId}/action/${action}`,
        );
    const url = new URL(drupalUrl, window.location.origin);
    url.searchParams.set('me_id', String(meId));
    Object.entries(extraParams).forEach(([key, value]) => {
      // Omit null/undefined/empty values.
      if (value !== null && value !== undefined && value !== '') {
        url.searchParams.set(key, String(value));
      }
    });
    return url.toString();
  }
  /**
   * Makes an API call to the given action.
   * @param {String} action The action to perform.
   * @param {Object} options The options.
   * @param {Object} extraParams Extra GET parameters to add to the URL.
   * @param {Object} extraData Extra POST data to send.
   * @return {Promise} The AJAX promise.
   */
  function makeRequest(action, options, extraParams = {}, extraData = {}) {
    return Drupal.ajax({
      url: apiUrl(action, options, extraParams),
      type: 'POST',
      submit: extraData,
    }).execute();
  }
  function safeToCloseDialog() {
    const unsavedChanges = document.querySelector(
      '.layout-paragraphs-component-form[data-form-changed="true"], .me-entity-form[data-form-changed="true"]',
    );
    if (!unsavedChanges) {
      return true;
    }
    // eslint-disable-next-line no-alert
    const proceed = window.confirm(Drupal.t('Discard unsaved changes?'));
    // const proceed = false;
    if (!proceed) {
      Drupal.mercuryEditorBus.post('preview:selectComponent', {
        uuid: document.querySelector('[name="uuid"]').value,
      });
      restoreFormFocus(
        document.querySelector('.layout-paragraphs-component-form'),
      );
    }
    return proceed;
  }
  /**
   * A collection of component actions (edit, delete, duplicate, etc.).
   * Most actions are handled by the default handler which just makes a request.
   * For more complex actions, custom handlers are defined.
   */
  const componentActionHandlers = {
    /**
     * Opens the component edit form in a modal and opens the sidebar if needed.
     * @param {Object} data Data from the triggering event.
     * @param {*} openSidebar Whether to force open the sidebar.
     */
    edit(data, openSidebar = true) {
      if (!data.uuid) {
        return;
      }

      if (openSidebar) {
        Drupal.mercuryEditorBus.post('editor:openSidebar');
      }
      Drupal.mercuryEditorBus.post('preview:selectComponent', {
        uuid: data.uuid,
      });
      makeRequest('edit', {
        meId: data.meId,
        layoutId: data.layoutId,
        uuid: data.uuid,
      });
    },
    /**
     * Inserts a new component.
     * @param {Object} data The data from the triggering event.
     */
    insert(data) {
      makeRequest(
        'insert',
        {
          meId: data.meId,
          layoutId: data.layoutId,
        },
        {
          parent_uuid: data.parentUuid,
          placement: data.placement,
          region: data.region,
          sibling_uuid: data.siblingUuid,
        },
      );
    },
    /**
     * Reorders components.
     * @param {Object} data The data from the triggering event.
     */
    reorder(data) {
      makeRequest(
        'reorder',
        {
          meId: data.meId,
          layoutId: data.layoutId,
        },
        {
          component_uuid: data.componentUuid,
        },
        {
          components: JSON.stringify(data.components),
        },
      );
    },
    /**
     * A default handler for component actions that just makes a request.
     * @param {Object} data The data from the triggering event. Must include a URL.
     */
    defaultHandler(data) {
      makeRequest(data.action, {
        meId: data.meId,
        layoutId: data.layoutId,
        uuid: data.uuid,
        url: data.url,
      });
    },
  };
  const componentEventHandlers = {
    /**
     * Closes the component form modal.
     * @param {Object} data Data from the triggering event.
     */
    blur(data) {
      // If this blur was triggered by a focus event, do nothing. The focus
      // action will take care of it by replacing the dialog.
      if (data.originalEvent === 'lpb-component:focus') {
        return;
      }
      if (!safeToCloseDialog(data.uuid)) {
        return;
      }
      const { uuid } = data;
      const dialogElement = document.querySelector(
        `mercury-dialog:has([name="uuid"][value="${uuid}"])`,
      );
      dialogElement?.close();
    },
    /**
     * Handles focus on a component.
     * @param {Object} data Data from the triggering event.
     */
    focus(data) {
      // If the UUID is missing, do nothing — this can happen when an event
      // fires on an element that hasn't rendered its data-uuid attribute yet.
      if (!data.uuid) {
        return;
      }
      // If the component form is already open, do nothing.
      if (
        document.querySelector(
          `.layout-paragraphs-component-form [name="uuid"][value="${data.uuid}"]`,
        )
      ) {
        return;
      }
      if (!safeToCloseDialog(data.uuid)) {
        return;
      }
      if (data.originalEvent !== 'lpb-component:update') {
        componentActionHandlers.edit(data, false);
      }
    },
  };
  Drupal.mercuryEditorBus.on('component:*', (msg) => {
    const { type, payload } = msg;
    const action = type.split(':')[1];
    const handler = componentActionHandlers[action];
    if (handler) {
      handler(payload);
    } else {
      // Use the default handler if no specific handler is found.
      componentActionHandlers.defaultHandler({ ...payload, action });
    }
  });
  Drupal.mercuryEditorBus.on('component-event:*', (msg) => {
    const { type, payload } = msg;
    const handler = componentEventHandlers[type.split(':')[1]];
    if (handler) {
      handler(payload);
    }
  });

  trackFocus();
})(Drupal);
