((Drupal) => {
  /**
   * Collection of functions to respond to postMessage events.
   */
  const previewScreenEventHandlers = {
    /**
     * Runs a set of ajax commands in the preview window.
     * @param {Object} data The ajax commands.
     */
    ajaxCommands(data) {
      const { commands, status } = data;
      const ajaxObj = Drupal.ajax({ url: '' });
      const ajaxCommands = new Drupal.AjaxCommands();
      Object.keys(commands || {}).reduce((executionQueue, key) => {
        return (
          executionQueue
            .then(() => {
              const { command } = commands[key];
              if (command && ajaxCommands[command]) {
                return ajaxCommands[command](ajaxObj, commands[key], status);
              }
            })
            // eslint-disable-next-line no-console
            .catch(console.error)
        );
      }, Promise.resolve());
    },
    /**
     * Selects a component in the preview.
     * @param {Object} data The data containing the uuid of the component to select.
     */
    selectComponent(data) {
      const { uuid, forceReload } = data;
      const component = document.querySelector(`[data-uuid="${uuid}"]`);
      if (!component) {
        return;
      }
      const rect = component.getBoundingClientRect();
      const isOffScreen = rect.bottom < 0 || rect.top > window.innerHeight;
      if (isOffScreen) {
        window.scrollTo({ top: rect.top + window.scrollY - window.innerHeight / 2, behavior: 'smooth' });
      }
      if (component.getAttribute('data-active') !== 'true') {
        const event = new CustomEvent('lpb-component:focus', {
          bubbles: true,
          cancelable: true,
          detail: { uuid },
        });
        component.dispatchEvent(event);
      }
      if (forceReload) {
        Drupal.mercuryEditorBus.post('component:edit', {
          uuid,
          layoutId: component
            .closest('[data-lpb-id]')
            ?.getAttribute('data-lpb-id'),
          meId: drupalSettings.mercuryEditor.id,
        });
      }
    },
    /**
     * Unselects a component in the preview.
     * @param {Object} data The data containing the uuid of the component to unselect.
     */
    unselectComponent(data) {
      const { uuid, originalEvent } = data;
      const component = document.querySelector(`[data-uuid="${uuid}"]`);
      const layoutId = component
        ?.closest('[data-layout-id]')
        ?.getAttribute('data-layout-id');
      const event = new CustomEvent('lpb-component:blur', {
        bubbles: true,
        cancelable: true,
        detail: { uuid, layoutId, originalEvent },
      });
      component.dispatchEvent(event);
    },
  };
  Drupal.mercuryEditorBus.on('preview:*', (msg) => {
    const { type, payload } = msg;
    const handler = previewScreenEventHandlers[type.split(':')[1]];
    if (handler) {
      handler(payload);
    }
  });
})(Drupal);
