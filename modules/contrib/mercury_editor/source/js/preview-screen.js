((Drupal, drupalSettings, $, once) => {
  /**
   * Adds the me_id GET parameter to a URL.
   * @param {String} url The url to add the me_id GET parameter to.
   * @return {String} The url with the me_id GET parameter.
   */
  function addPreviewParam(url) {
    const mercuryEditorId = drupalSettings.mercuryEditor?.id || null;
    if (!mercuryEditorId) {
      return url;
    }
    const urlObj = new URL(url, window.location.origin);
    if (urlObj.origin !== window.location.origin) {
      return url;
    }
    urlObj.searchParams.set('me_id', mercuryEditorId);
    return urlObj.toString();
  }

  // Intercept all XMLHttpRequests to add me_id GET parameter.
  const originalOpen = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function open(
    method,
    url,
    async,
    user,
    password,
  ) {
    return originalOpen.call(
      this,
      method,
      addPreviewParam(url),
      async,
      user,
      password,
    );
  };

  // Intercept all fetch requests to add me_id GET parameter.
  const originalFetch = window.fetch;
  window.fetch = (input, init) => {
    let url = typeof input === 'string' ? input : input.url;
    if (url) {
      url = addPreviewParam(url);
    }
    return originalFetch(url || input, init);
  };

  /**
   * Prevent a click.
   *
   * @param {Event} e The click event.
   * @return {Boolean} False.
   */
  function preventDefault(e) {
    e.stopPropagation();
    e.preventDefault();
    return false;
  }

  function handleComponentActionClick(event) {
    event.stopPropagation();
    event.preventDefault();
    const element = event.target; // .closest('[data-me-component-action]');
    const meId = drupalSettings.mercuryEditor?.id || null;
    Drupal.mercuryEditorBus.post('editor:saveScrollPosition');
    Drupal.mercuryEditorBus.post(
      'editor:ajaxPreviewPageState',
      drupalSettings.ajaxPageState,
    );
    Drupal.mercuryEditorBus.post(
      `component:${element.getAttribute('data-me-component-action')}`,
      {
        uuid: element.closest('[data-uuid]')?.getAttribute('data-uuid'),
        layoutId: element
          ?.closest('[data-lpb-id]')
          ?.getAttribute('data-lpb-id'),
        meId,
        parentUuid: element.getAttribute('data-me-parent-uuid'),
        region: element.getAttribute('data-me-region'),
        siblingUuid: element.getAttribute('data-me-sibling-uuid'),
        placement: element.getAttribute('data-me-placement'),
        url: element.getAttribute('href'),
      },
    );
    return false;
  }

  /**
   * Attaches the behavior to the edit screen.
   */
  Drupal.behaviors.mercuryEditorPreviewScreen = {
    attach(context) {
      // Check for duplicate data attributes.
      const duplicateContainers = Array.from(
        document.querySelectorAll('[data-me-edit-screen-key]'),
      )
        .map((container) => container.getAttribute('data-me-edit-screen-key'))
        .filter((value, index, self) => self.indexOf(value) !== index);
      if (duplicateContainers.length > 0) {
        // eslint-disable-next-line no-console
        console.error(
          'Multiple HTML elements found using the same data attribute, "data-me-edit-screen-key", which should be unique. Make sure attributes are not passed to child elements in twig templates.',
          duplicateContainers,
        );
      }
      Drupal.mercuryEditorBus.post(
        'editor:ajaxPreviewPageState',
        drupalSettings.ajaxPageState,
      );
      Drupal.mercuryEditorBus.post(
        'editor:layoutParagraphsSettings',
        drupalSettings.lpBuilder || {},
      );
      once(
        'me-component-actions',
        '[data-me-component-action]',
        context,
      ).forEach((el) => {
        $(el).off(); // Since core attaches behavior with JQuery.
        el.addEventListener('mousedown', preventDefault);
        el.addEventListener('mouseup', preventDefault);
        el.addEventListener('click', handleComponentActionClick);
      });

      // Prevent links from working in the preview iframe.
      if (window.parent !== window) {
        once('me-stop-iframed-links', 'a', context).forEach((link) => {
          if (link.closest('.lpb-controls') === null) {
            link.setAttribute('target', '_parent');
            link.addEventListener('click', (e) => {
              e.stopPropagation();
              e.preventDefault();
              return false;
            });
          }
        });
        once(
          'me-prevent-focus',
          'a, button, input, textarea, select, details',
          context,
        ).forEach((focussable) => {
          if (
            focussable.closest('.lpb-controls') === null &&
            focussable.closest('.mercury-editor-ui') === null &&
            !focussable.classList.contains('use-postmessage')
          ) {
            focussable.setAttribute('tabindex', '-1');
          }
        });
      }
    },
  };
  // Sends all Layout Paragraphs events to the parent window.
  [
    'lpb-component:move',
    'lpb-component:drop',
    'lpb-component:insert',
    'lpb-component:delete',
    'lpb-component:update',
    'lpb-component:blur',
    'lpb-component:focus',
    'lpb-component:drag',
  ].forEach((eventName) => {
    document.addEventListener(eventName, (event) => {
      const uuid =
        event?.target instanceof Element
          ? event.target.getAttribute('data-uuid')
          : undefined;
      Drupal.mercuryEditorBus.post(
        `component-event:${eventName.split(':')[1]}`,
        {
          uuid,
          layoutId:
            event.target && event.target !== document
              ? event.target
                  ?.closest('[data-lpb-id]')
                  ?.getAttribute('data-lpb-id')
              : undefined,
          meId: drupalSettings.mercuryEditor?.id || null,
          value: event.target?.outerHTML,
          originalEvent: event.detail?.originalEvent?.type || null,
        },
      );
    });
  });
})(Drupal, drupalSettings, jQuery, once);
