/**
 * @file
 * Manages content block preview options.
 */

/**
 * Initializes the content block preview behavior.
 *
 * @param {object} Drupal
 *   The Drupal global.
 * @param {Function} once
 *   Drupal's once function.
 */
((Drupal, once) => {
  Drupal.behaviors.mercuryEditorBlockPreviewOptions = {
    /**
     * Attaches the preview options to the editor.
     *
     * @param {Document|Element} context
     *   The context in which to find the options.
     */
    attach(context) {
      /**
       * Initializes the options for a content block.
       *
       * @param {HTMLElement} container
       *   The preview options disclosure.
       */
      function initOptions(container) {
        const toggle = container.querySelector('#me-block-preview-options-toggle');
        const fields = container.querySelector('#me-block-preview-options-fields');
        const region = container.querySelector('#me-preview-region');
        const showTitle = container.querySelector('#me-preview-show-title');
        const title = container.querySelector('#me-preview-title');
        const titleField = container.querySelector('#me-preview-title-item');
        const iframe = document.querySelector('#me-preview');
        const storageKey = `me-block-preview:${window.location.pathname}:${container.dataset.placement || 'collection'}`;
        const navigation = performance.getEntriesByType('navigation')[0];
        const saved = sessionStorage.getItem(storageKey);
        if (saved && ['reload', 'back_forward'].includes(navigation?.type)) {
          const values = new URLSearchParams(saved);
          let validRegion = false;
          for (const option of region.options) {
            if (option.value === values.get('region')) {
              validRegion = true;
              break;
            }
          }
          if (validRegion &&
              ['0', '1'].includes(values.get('show_title')) &&
              values.has('title') && values.get('title').length <= 255) {
            region.value = values.get('region');
            showTitle.checked = values.get('show_title') === '1';
            title.value = values.get('title');
            titleField.hidden = !showTitle.checked;
          } else {
            console.warn('Invalid saved content block preview options.');
            sessionStorage.removeItem(storageKey);
          }
        } else {
          sessionStorage.removeItem(storageKey);
        }

        /**
         * Saves the options for an editor reload.
         */
        function saveOptions() {
          const values = new URLSearchParams({
            region: region.value,
            show_title: showTitle.checked ? '1' : '0',
            title: title.value,
          });
          sessionStorage.setItem(storageKey, values.toString());
        }

        /**
         * Adds the current preview options to a URL.
         *
         * @param {string|URL} url
         *   The URL to update.
         *
         * @return {string}
         *   The URL with preview options.
         */
        function addOptions(url) {
          const target = new URL(url, window.location.origin);
          if (container.dataset.placement) {
            target.searchParams.set('me_block_placement', container.dataset.placement);
            target.searchParams.set('me_block_token', container.dataset.token);
          }
          target.searchParams.set('me_preview_region', region.value);
          target.searchParams.set('me_preview_show_title', showTitle.checked ? '1' : '0');
          target.searchParams.set('me_preview_title', title.value);
          return target.toString();
        }

        /**
         * Adds preview options to editor AJAX requests.
         *
         * @param {CustomEvent} event
         *   The event containing the AJAX request options.
         */
        function addRequestOptions(event) {
          const options = event.detail;
          const url = new URL(options.url, window.location.origin);
          if (url.origin === window.location.origin && url.pathname.includes('/mercury-editor/')) {
            options.url = addOptions(url);
          }
        }
        document.addEventListener('mercury-editor:ajax-options', addRequestOptions);

        iframe.dataset.src = addOptions(iframe.dataset.src);
        let waiting = false;
        /**
         * Refreshes the preview after outstanding AJAX requests finish.
         */
        function refresh() {
          titleField.hidden = !showTitle.checked;
          if (document.body.classList.contains('me-ajaxing')) {
            if (!waiting) {
              waiting = true;
              document.addEventListener('mercury-editor:ajax-idle', onAjaxIdle, { once: true });
            }
            return;
          }
          // Avoid adding preview changes to the browser's joint history.
          iframe.contentWindow.location.replace(addOptions(iframe.dataset.src));
        }

        /**
         * Retries a deferred refresh when AJAX becomes idle.
         */
        function onAjaxIdle() {
          waiting = false;
          refresh();
        }

        /**
         * Saves and applies changed preview options.
         */
        function changeOptions() {
          saveOptions();
          refresh();
        }
        const refreshTitle = Drupal.debounce(refresh, 400);
        region.addEventListener('change', changeOptions);
        showTitle.addEventListener('change', changeOptions);
        /**
         * Saves title changes and refreshes after typing stops.
         */
        function changeTitle() {
          saveOptions();
          refreshTitle();
        }
        title.addEventListener('input', changeTitle);
        /**
         * Clears temporary options when leaving the editor.
         */
        function clearOptions() {
          sessionStorage.removeItem(storageKey);
        }
        document.querySelector('#me-done-btn').addEventListener('click', clearOptions);
        /**
         * Updates the disclosure's visibility and accessible expanded state.
         *
         * @param {boolean} expanded
         *   Whether the disclosure should be open.
         */
        function setDisclosure(expanded) {
          fields.hidden = !expanded;
          toggle.setAttribute('aria-expanded', String(expanded));
        }

        /**
         * Toggles the preview options disclosure.
         */
        function toggleDisclosure() {
          setDisclosure(fields.hidden);
        }
        toggle.addEventListener('click', toggleDisclosure);
        /**
         * Closes the disclosure and restores focus on Escape.
         *
         * @param {KeyboardEvent} event
         *   The keyboard event.
         */
        function handleKeydown(event) {
          if (event.key === 'Escape' && !fields.hidden) {
            event.preventDefault();
            event.stopPropagation();
            setDisclosure(false);
            toggle.focus();
          }
        }
        container.addEventListener('keydown', handleKeydown);
      }
      once('me-block-preview-options', '#me-block-preview-options', context).forEach(initOptions);
    },
  };
})(Drupal, once);
