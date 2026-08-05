(function () {
  'use strict';

  ((Drupal, drupalSettings) => {
    const editScreenEventHandlers = {
      /**
       * Saves the preview's page state for use in ajax requests.
       * @param {Object} pageState The page state, passed from the preview.
       */
      ajaxPreviewPageState(pageState) {
        drupalSettings.ajaxPreviewPageState = pageState;
      },
      /**
       * Close the sidebar.
       */
      closeSidebar() {
        const isCollapsed = localStorage.getItem('mercury-dialog-dock-collapsed');
        const currentWidth = localStorage.getItem('me-dialog-dock-right-width');
        // Store the current width of the dock before collapsing it, so it can be
        // restored when re-opening the sidebar.
        if (currentWidth) {
          localStorage.setItem('me-dialog-dock-right-width-expanded', currentWidth);
        }
        // If the sidebar is already closed, do nothing.
        if (isCollapsed === 'true') {
          return;
        }
        // When closing the sidebar, set the width to 10px.
        document.documentElement.style.setProperty(
          '--me-dialog-dock-right-width',
          '10px',
        );
        localStorage.setItem('mercury-dialog-dock-collapsed', 'true');
      },
      /**
       * Used to share Layout Paragraphs settings to the parent window.
       * @param {Object} data The Layout Paragraphs settings.
       */
      layoutParagraphsSettings(data) {
        drupalSettings.lpBuilder = data || {};
      },
      /**
       * Respond to mercury editor state updates.
       * @param {Object} data The data containing the state index and count.
       */
      updateState(data) {
        const { stateIndex, stateCount } = data;
        const undoBtn = document.querySelector('#me-undo');
        const redoBtn = document.querySelector('#me-redo');
        if (stateIndex === 0) {
          undoBtn.classList.add('disabled');
        } else {
          undoBtn.classList.remove('disabled');
        }
        if (stateCount === 0 || stateIndex === stateCount - 1) {
          redoBtn.classList.add('disabled');
        } else {
          redoBtn.classList.remove('disabled');
        }
      },
      openSidebar() {
        const isCollapsed = localStorage.getItem('mercury-dialog-dock-collapsed');
        // If the sidebar is already open, do nothing.
        if (isCollapsed !== 'true') {
          return;
        }
        // When re-opening the sidebar, set the width to the default width.
        const sidebarWidth = localStorage.getItem(
          'me-dialog-dock-right-width-expanded',
        );
        localStorage.removeItem('me-dialog-dock-right-width-expanded');
        if (sidebarWidth) {
          document.documentElement.style.setProperty(
            '--me-dialog-dock-right-width',
            `${sidebarWidth}px`,
          );
        }
        localStorage.setItem('mercury-dialog-dock-collapsed', 'false');
      },
      /**
       * Saves the scroll position of the currently open component form.
       */
      saveScrollPosition() {
        const componentForm = document.querySelector(
          '.layout-paragraphs-component-form',
        );
        if (componentForm) {
          // The custom element that hosts the shadow root
          const uuid = componentForm.querySelector('[name="uuid"]').value;
          const dialogHost = componentForm.closest('mercury-dialog');
          const scrollPosition =
            dialogHost.shadowRoot.querySelector('main').scrollTop;
          sessionStorage.setItem(
            `me-component-scroll-position-${uuid}`,
            scrollPosition.toString(),
          );
        }
      },
    };
    Drupal.mercuryEditorBus.on('editor:*', (msg) => {
      const { type, payload } = msg;
      const handler = editScreenEventHandlers[type.split(':')[1]];
      if (handler) {
        handler(payload);
      }
    });
  })(Drupal, drupalSettings, once);

})();
