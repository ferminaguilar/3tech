(function () {
  'use strict';

  ((Drupal, drupalSettings, $, once) => {
    // Catch all Ajax errors and send to home page.
    // @todo Consider a more elegant / graceful way to handle errors.
    $(document).ajaxError((event, xhr) => {
      if (xhr.status >= 400) {
        // eslint-disable-next-line no-alert
        alert(Drupal.t('Something went wrong. Please try again.'));
        window.location.href = `${window.location.origin}${window.location.pathname}?_ts=${Date.now()}`;
      }
    });

    /**
     * Set the size of the preview iframe.
     * @param {String} w The width of the preview iframe.
     * @param {String} h The height of the preview iframe.
     */
    function setPreviewViewportSize(w, h) {
      const iframe = document.querySelector('#me-preview');
      if (w) {
        iframe.style.width = w;
      } else {
        iframe.style.removeProperty('width');
      }
      if (h) {
        iframe.style.height = h;
      } else {
        iframe.style.removeProperty('height');
      }
    }

    function checkFormForErrors(formSelector, reportValidity = false) {
      const form = document.querySelector(formSelector);
      if (!form) {
        return false;
      }
      const inputs = form.querySelectorAll('input, textarea, select');
      const invalid = Array.from(inputs).filter(
        (input) =>
          !!(
            input.offsetWidth ||
            input.offsetHeight ||
            input.getClientRects().length
          ) && !input.validity.valid,
      );
      if (invalid.length) {
        if (reportValidity) {
          invalid.forEach((input) => {
            input.reportValidity();
          });
        }
        return true;
      }
      const errorMessages = form.querySelectorAll(
        '.me-form-error-messages .messages--error',
      );
      if (errorMessages.length) {
        return true;
      }
      return false;
    }

    /**
     * Save the content.
     * @param {Event} event The click event.
     */
    function save() {
      const saveBtn = document.querySelector(
        '[data-drupal-selector="edit-submit"]:not([disabled])',
      );
      if (saveBtn) {
        // Expand all form error messages.
        Array.from(
          document.querySelectorAll(
            '.me-form-error-messages__button[aria-expanded="false"]',
          ),
        ).forEach((btn) => {
          btn.click();
        });
        // Check for form errors.
        if (checkFormForErrors('.layout-paragraphs-component-form', true)) {
          return;
        }
        if (checkFormForErrors('.me-entity-form', true)) {
          const uuid = document.querySelector('[name="uuid"]')?.value;
          if (uuid) {
            Drupal.mercuryEditorBus.post('preview:unselectComponent', {
              uuid,
            });
          }
          return;
        }
        // Close the Layout Paragraphs dialog if open.
        // document.querySelector('mercury-dialog.lpb-dialog[open]')?.close();
        // No JS errors, proceed to save.
        saveBtn.dispatchEvent(new Event('mousedown'));
      }
    }

    /**
     * Perform an undo or redo action.
     * @param {String} which Either 'undo' or 'redo'.
     */
    function undoRedo(which) {
      const match = window.location.pathname.match(
        /^\/mercury-editor\/([0-9a-fA-F-]+)/,
      );
      const id = match ? match[1] : null;
      Drupal.ajax({
        url: `/mercury-editor/${id}/${which}`,
        submit: {},
        success: () => Drupal.ajax({ url: `/mercury-editor/${id}` }).execute(),
      }).execute();
    }

    /**
     * Initialize the toolbar.
     */
    function initToolbar() {
      const previewIframeWrapper = document.querySelector('#me-iframe-wrapper');
      function mobileViewport() {
        const presetsSelect = document.querySelector('.me-mobile-presets');
        if (presetsSelect) {
          const preset =
            presetsSelect.options[presetsSelect.selectedIndex ?? 0].value;
          const presetValues = preset.split('x');
          setPreviewViewportSize(
            `${presetValues[0]}px`,
            `${Math.min(
            presetValues[1],
            window.innerHeight -
              document.getElementById('me-toolbar').offsetHeight -
              20,
          )}px`,
          );
        } else {
          setPreviewViewportSize(
            '390px',
            `${Math.min(
            '844',
            window.innerHeight -
              document.getElementById('me-toolbar').offsetHeight -
              20,
          )}px`,
          );
        }
      }
      const presetsSelect = document.querySelector('.me-mobile-presets');
      if (presetsSelect) {
        presetsSelect.addEventListener('change', mobileViewport);
      }
      document
        .querySelector('#me-mobile-toggle-btn')
        .addEventListener('click', (e) => {
          if (presetsSelect) {
            presetsSelect.style.display = 'block';
          }
          previewIframeWrapper.classList.add('is-device-preview');
          document
            .querySelector('#me-desktop-toggle-btn')
            .classList.remove('active');
          e.currentTarget.classList.add('active');
          mobileViewport();
          window.addEventListener('resize', mobileViewport);
          e.preventDefault();
          e.stopPropagation();
          return false;
        });
      document
        .querySelector('#me-desktop-toggle-btn')
        .addEventListener('click', (e) => {
          if (presetsSelect) {
            presetsSelect.style.display = 'none';
          }
          previewIframeWrapper.classList.remove('is-device-preview');
          document
            .querySelector('#me-mobile-toggle-btn')
            .classList.remove('active');
          e.currentTarget.classList.add('active');
          window.removeEventListener('resize', mobileViewport);
          setPreviewViewportSize('100cqw', '100cqw');
          e.preventDefault();
          e.stopPropagation();
          return false;
        });
      document.querySelector('#me-undo').addEventListener('click', () => {
        document.querySelector('.lpb-dialog')?.close();
      });
      document.querySelector('#me-redo').addEventListener('click', () => {
        document.querySelector('.lpb-dialog')?.close();
      });

      document
        .querySelector('#me-undo')
        .addEventListener('click', () => undoRedo('undo'));

      document
        .querySelector('#me-redo')
        .addEventListener('click', () => undoRedo('redo'));

      const saveBtn = document.querySelector(
        '[data-drupal-selector="edit-submit"]:not([disabled])',
      );
      if (saveBtn) {
        document.querySelector('#me-save-btn').addEventListener('click', save);
      } else {
        document.querySelector('#me-save-btn').remove();
      }

      // Default dialog width.
      if (
        drupalSettings.mercuryEditor &&
        drupalSettings.mercuryEditor.defaultWidth
      ) {
        document.documentElement.style.setProperty(
          '--me-dialog-width-default',
          `${drupalSettings.mercuryEditor.defaultWidth}px`,
        );
      }

      // Specific dialog widths saved in localStorage from previous sessions.
      const leftDockWidth = localStorage.getItem('me-dialog-dock-left-width');
      const rightDockWidth = localStorage.getItem('me-dialog-dock-right-width');
      const dialogWidth = localStorage.getItem('me-dialog-dock-width');

      if (dialogWidth) {
        document.documentElement.style.setProperty(
          '--me-dialog-dock-width',
          `${dialogWidth}px`,
        );
      }
      if (leftDockWidth) {
        document.documentElement.style.setProperty(
          '--me-dialog-dock-left-width',
          `${leftDockWidth}px`,
        );
      }
      if (rightDockWidth) {
        document.documentElement.style.setProperty(
          '--me-dialog-dock-right-width',
          `${rightDockWidth}px`,
        );
      }

      const sidebarToggle = document.querySelector('#me-sidebar-toggle-btn');
      sidebarToggle.addEventListener('click', (e) => {
        const isCollapsed = localStorage.getItem('mercury-dialog-dock-collapsed');
        if (isCollapsed === 'true') {
          Drupal.mercuryEditorBus.post('editor:openSidebar');
        } else {
          Drupal.mercuryEditorBus.post('editor:closeSidebar');
        }
        e.preventDefault();
        e.stopPropagation();
        return false;
      });

      // Listen for dock resize events.
      document.addEventListener('mercury:dockResize', (e) => {
        // The width of the resize event.
        const { width } = e.detail;
        const docDirection = e.target.getAttribute('dock');
        const storageKey = docDirection
          ? `me-dialog-dock-${docDirection}-width`
          : 'me-dialog-width';
        const contentElements = e.target.shadowRoot.querySelectorAll(
          'header, main, footer',
        );
        // If width is greater than 10, the dock is open.
        if (width > 10) {
          contentElements.forEach((el) => {
            el.style.display = '';
          });
          sidebarToggle.classList.remove('me-button--sidebar-expand');
          sidebarToggle.classList.add('me-button--sidebar-collapse');
          sidebarToggle.innerHTML = `<span>${Drupal.t('Hide sidebar')}</span>`;
          sidebarToggle.setAttribute('title', Drupal.t('Hide sidebar'));
          localStorage.removeItem('mercury-dialog-dock-collapsed');
        } else {
          sidebarToggle.classList.remove('me-button--sidebar-collapse');
          sidebarToggle.classList.add('me-button--sidebar-expand');
          sidebarToggle.innerHTML = `<span>${Drupal.t('Show sidebar')}</span>`;
          sidebarToggle.setAttribute('title', Drupal.t('Show sidebar'));
          localStorage.setItem('mercury-dialog-dock-collapsed', 'true');
          contentElements.forEach((el) => {
            el.style.display = 'none';
          });
        }
        localStorage.setItem(storageKey, width);
      });

      // Set the --me-toolbar-offset-y CSS variable to the height of the toolbar.
      const toolbar = document.getElementById('me-toolbar');
      if (toolbar) {
        document.documentElement.style.setProperty(
          '--me-toolbar-offset-y',
          `${Math.floor(toolbar.offsetHeight) - 1}px`,
        );
      }
    }
    /**
     * Toggles mouse pointer events on the preview iFrame.
     *
     * When dragging the dialog border, the iFrame intercepts mouse events and
     * prematurely stops the drag behavior. Toggling pointer events prevents
     * this behavior.
     *
     * @param {Event} e
     *   The mouseup or mousedown event
     */
    function iFramePointerEventsToggle(e) {
      const iframe = document.querySelector('#me-preview');
      if (iframe) {
        iframe.style.pointerEvents = e.type === 'mouseup' ? 'auto' : 'none';
      }
    }
    /**
     * Attaches the behavior to the edit screen.
     */
    Drupal.behaviors.mercuryEditorEditScreen = {
      attach(context) {
        // After saving the host ME entity, check if a component form is open and
        // if so, re-focus the component to reload its data and form, preventing
        // stale form data issues.
        // This works because '.me-entity-form' is the form for the parent entity,
        // not a component.
        if (context?.classList?.contains('me-entity-form')) {
          const uuid = document.querySelector('[name="uuid"]')?.value;
          if (uuid) {
            Drupal.mercuryEditorBus.post('preview:selectComponent', {
              uuid,
            });
          }
        }

        // Move focus to the first input with error, if any.
        const firstError = once(
          'me-first-error',
          '.js-form-item.error',
          context,
        )[0];
        if (firstError) {
          firstError.focus();
          firstError.scrollIntoView({
            behavior: 'smooth',
          });
        }
        // Initialize the toolbar.
        if (once('me-toolbar', '#me-toolbar', context).length) {
          initToolbar();
        }
        // Open the edit tray dialog.
        if (once('me-edit-tray', '#me-edit-screen', context).length) {
          // Opens the dialog and attaches event listeners.
          const mercuryDialogElement = context.querySelector('#me-edit-screen');
          if (mercuryDialogElement) {
            const mercuryDialog = Drupal.mercuryDialog(mercuryDialogElement);
            mercuryDialog.show();
            // Attaches a custom beforeSerialize() method to Drupal.Ajax.
            // This method adds the ajaxPreviewPageState to the ajax request.
            if (
              typeof Drupal.Ajax !== 'undefined' &&
              typeof Drupal.Ajax.prototype.beforeSerializeMercuryEditor ===
                'undefined'
            ) {
              Drupal.Ajax.prototype.beforeSerializeMercuryEditor =
                Drupal.Ajax.prototype.beforeSerialize;
              Drupal.Ajax.prototype.beforeSerialize = function beforeSerialize(
                element,
                options,
              ) {
                this.beforeSerializeMercuryEditor(element, options);
                const pageState = drupalSettings.ajaxPreviewPageState || {};
                options.data['ajax_preview_page_state[theme]'] = pageState.theme;
                options.data['ajax_preview_page_state[theme_token]'] =
                  pageState.theme_token;
                options.data['ajax_preview_page_state[libraries]'] =
                  pageState.libraries;
              };
            }
            // Remove min-width from the dialog.
            // @todo Refactor once dialog min-width is addressed.
            // var style = document.createElement( 'style' )
            // style.innerHTML = 'dialog { min-width: 0 !important; }'
            // mercuryDialogElement.shadowRoot.appendChild( style );
            // @todo Refactor if we have drag events from dialog.
            document.addEventListener('mousedown', iFramePointerEventsToggle);
            document.addEventListener('mouseup', iFramePointerEventsToggle);
          }
        }
        // Set the iframe URL once other js files have loaded.
        if (once('me-preview-iframe', '#me-preview', context).length) {
          const iframe = document.querySelector('#me-preview');
          iframe.src = iframe.getAttribute('data-src');
        }
      },
    };
    /**
     * Ajax command to show save success feedback.
     */
    Drupal.AjaxCommands.prototype.mercuryEditorSaveSuccess = () => {
      const btn = document.querySelector('#me-save-btn');
      btn.classList.add('me-button--saved');
      btn.innerHTML = `${Drupal.t('Saved!')}`;
      setTimeout(() => {
        btn.classList.remove('me-button--saved');
        btn.innerHTML = `${Drupal.t('Save changes')}`;
      }, 2000);
      // Create a div element.
      const message = document.createElement('div');
      message.className = 'me-toolbar__save-status';
      // Use the current time.
      const now = new Date();
      const time = now.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
      });
      message.innerHTML = Drupal.t('Changes saved at @time.', { '@time': time });
      // Remove any existing messages.
      document
        .querySelectorAll('.me-toolbar__save-status')
        .forEach((el) => el.remove());
      // Prepend the message to the "me-toolbar__edit-controls" element.
      document.querySelector('.me-toolbar__edit-controls').prepend(message);
      // Fade out and remove the message after 3 seconds.
      setTimeout(() => {
        message.style.opacity = '0';
        setTimeout(() => {
          message.remove();
        }, 1000);
      }, 3000);
    };

    /**
     * Scrolls to previous position when re-opening a component dialog.
     */
    document.addEventListener('dialog:aftercreate', (event) => {
      setTimeout(() => {
        if (event.target?.classList?.contains('lpb-dialog')) {
          const uuid = event.target.querySelector('[name="uuid"]')?.value;
          if (!uuid) {
            return;
          }
          const scrollPosition = sessionStorage.getItem(
            `me-component-scroll-position-${uuid}`,
          );
          if (scrollPosition) {
            const scrollElement = event.target.shadowRoot.querySelector('main');
            if (scrollElement) {
              scrollElement.scrollTop = parseInt(scrollPosition, 10);
            }
            sessionStorage.removeItem(`me-component-scroll-position-${uuid}`);
          }
        }
      });
    });
  })(Drupal, drupalSettings, jQuery, once, Drupal.debounce);

})();
