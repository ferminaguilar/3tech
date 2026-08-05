(function () {
  'use strict';

  (($, Drupal, drupalSettings) => {
    // TODO: This does not seem to be used, but keeping for reference.
    // drupalSettings.mercuryDialog = {
    //   autoOpen: true,
    //   autoResize: false,
    //   dialogClass: '',
    //   buttonClass: 'button',
    //   buttonPrimaryClass: 'button--primary',
    // };

    /**
     * Returns true if the jQuery dialog element is a <mercury-dialog>.
     * @param {jQuery} $dialog
     * @return {boolean}
     */
    function isMercuryDialog($dialog) {
      return $dialog[0].tagName === 'MERCURY-DIALOG';
    }

    /**
     * Returns true if the element has a truthy modal attribute.
     * @param {Element} element
     * @return {boolean}
     */
    function isModalDialog(element) {
      return (
        element.hasAttribute('modal') &&
        element.getAttribute('modal') !== 'false'
      );
    }

    /**
     * Converts a numeric-only value to a px-based CSS length.
     * Non-numeric strings (e.g. 'fit-content', '50%') pass through unchanged.
     * Note: only matches integers — decimal values pass through as-is.
     * @param {*} value
     * @return {string}
     */
    function getCSSLength(value) {
      if (typeof value === 'undefined' || !/^\d+$/.test(value)) {
        return value;
      }
      return `${value}px`;
    }

    function dispatchDialogEvent(eventType, dialog, element, settings) {
      // Use the jQuery if the DrupalDialogEvent is not defined for BC.
      if (typeof DrupalDialogEvent === 'undefined') {
        $(window).trigger(`dialog:${eventType}`, [dialog, $(element), settings]);
      } else {
        // eslint-disable-next-line no-undef
        const event = new DrupalDialogEvent(eventType, dialog, settings || {});
        element.dispatchEvent(event);
      }
    }

    class MercuryDialog {
      constructor(element, options = {}) {

        this._element = element;
        this._options = options;

        // Override the jQuery dialog method to return the element, preventing
        // uncaught errors from core jQuery dialog being thrown.
        $(element).dialog = () => $(element);

        // Wrap the element in a <mercury-dialog> if it isn't already.
        if (element.tagName !== 'MERCURY-DIALOG') {
          throw new Error('Dialog element must be a <mercury-dialog>.');
        }

        this.open = false;
        this.returnValue = undefined;
        this._element = element;
        this._closing = false;
        this._dockObserver = null;
      }

      /**
       * Create a button pane similar to jQuery UI dialog.
       * @param {Array} buttons The buttons to create.
       */
      _createButtonPane(buttons) {
        const existing = this._element.querySelector(
          '.me-dialog__buttonpane',
        );
        if (existing) {
          existing.remove();
        }
        const uiDialogButtonPane = document.createElement('div');
        uiDialogButtonPane.setAttribute('slot', 'footer');
        uiDialogButtonPane.classList.add('me-dialog__buttonpane');
        this._element.appendChild(uiDialogButtonPane);

        const hasButtons =
          buttons &&
          !$.isEmptyObject(buttons) &&
          (!Array.isArray(buttons) || buttons.length > 0);

        if (hasButtons) {
          const actionsWrapper = document.createElement('div');
          actionsWrapper.classList.add('actions');
          buttons.forEach((props) => {
            const button = document.createElement('button');
            button.classList = props.class;
            button.classList.add('button');
            button.appendChild(document.createTextNode(props.text));
            button.addEventListener('click', props.click);
            actionsWrapper.appendChild(button);
          });
          uiDialogButtonPane.appendChild(actionsWrapper);
        }
      }

      /**
       * ResizeObserver callback that runs when the shadow dialog element resizes.
       * @param {ResizeObserverEntry[]} entries The entries to observe.
       */
      _onDockResize(entries) {
        entries.forEach((entry) => {
          const dockElement = entry.target;
          const dockDirection = dockElement.getAttribute('data-dock');
          if (!dockElement.open || !dockDirection || dockDirection === 'none') {
            return;
          }
          const { width } = entry.contentRect;
          const { height } = entry.contentRect;
          const resizeEvent = new CustomEvent('mercury:dockResize', {
            detail: { width, height },
            bubbles: true,
          });
          this._element.dispatchEvent(resizeEvent);
        });
      }

      /**
       * Apply options to the <mercury-dialog> element.
       * @param {Object} options The options to apply.
       */
      applyOptions(options) {
        this._applyAttributes(options);
        this._applyClasses(options);
        this._applySizingVars(options);
        this._applyButtons(options);
      }

      /**
       * Sets element attributes and classes from options.
       * @param {Object} options
       */
      _applyAttributes(options) {
        const attributeOptions = [
          'title',
          'modal',
          'dock',
          'push',
          'resizable',
          'moveable',
        ];
        attributeOptions.forEach((option) => {
          if (typeof options[option] !== 'undefined') {
            this._element.setAttribute(option, options[option]);
          }
        });
      }

      /**
       * Sets element classes from options, including mapping jQuery UI classes to shadow DOM sub-elements.
      * @param {Object} options
       */
      _applyClasses(options) {
        // dialogClass is deprecated in drupal:10.4.x and will be removed from drupal:12.0.0.
        // @see /core/misc/dialog/dialog.js
        if (options.dialogClass) {
          this._element.classList.add(...options.dialogClass.split(' '));
        }

        // Map jQuery UI class keys to shadow DOM sub-elements.
        const dialogElements = {
          'ui-dialog': this._element,
          'ui-dialog-titlebar': this._element.shadowRoot.querySelector(
            'header[slot="header"]',
          ),
          'ui-dialog-title': this._element.shadowRoot.querySelector(
            'h1.me-dialog__title',
          ),
          'ui-dialog-content':
            this._element.shadowRoot.querySelector('main'),
          'ui-dialog-buttonpane':
            this._element.shadowRoot.querySelector('div[slot="footer"]'),
          'ui-dialog-buttonset':
            this._element.shadowRoot.querySelector('div[slot="footer"]'),
        };
        Object.keys(options.classes || {}).forEach((key) => {
          if (dialogElements[key] && options.classes[key]) {
            dialogElements[key].classList.add(...options.classes[key].split(' '));
          }
        });
      }

      /**
       * Applies sizing CSS custom properties based on dialog options.
       *
       * @param {HTMLElement} element The <mercury-dialog> element.
       * @param {Object} options
       */
      _applySizingVars(options) {

        const dock = this._element.getAttribute('dock');
        const widthProp = dock ? `--me-dialog-dock-${dock}-width` : '--me-dialog-width';
        const heightProp = dock ? `--me-dialog-dock-${dock}-height` : '--me-dialog-height';
        const {width, height, defaultWidth, defaultHeight} = options;

        if (width) {
          const widthValue = getCSSLength(width);
          document.documentElement.style.setProperty(widthProp, widthValue);
        }
        if (height) {
          const heightValue = getCSSLength(height);
          document.documentElement.style.setProperty(heightProp, heightValue);
        }
        if (defaultWidth) {
          const defaultWidthValue = getCSSLength(defaultWidth);
          document.documentElement.style.setProperty(
            '--me-dialog-width-default',
            defaultWidthValue,
          );
        }
        if (defaultHeight) {
          const defaultHeightValue = getCSSLength(defaultHeight);
          document.documentElement.style.setProperty(
            '--me-dialog-height-default',
            defaultHeightValue,
          );
        }
      }

      /**
       * Resolves and renders dialog buttons.
       * @param {Object} options
       */
      _applyButtons(options) {
        if (options.drupalAutoButtons && !options.buttons) {
          options.buttons = Drupal.behaviors.mercuryDialog.prepareDialogButtons(
            $(this._element),
          );
        }
        if (options.buttons && options.buttons.length) {
          this._createButtonPane(options.buttons);
        }
      }

      /**
       * Closes the dialog element.
       * @param {*} value The value to return from the dialog.
       */
      close(value) {
        if (this._closing) return;
        this._closing = true;
        dispatchDialogEvent('beforeclose', this, this._element);
        this._dockObserver?.disconnect();
        Drupal.detachBehaviors(this._element, null, 'unload');
        this._element.close();
        this.returnValue = value;
        dispatchDialogEvent('afterclose', this, this._element);
        $(this._element).remove();
      }

      /**
       * Initializes and opens the dialog element.
       * @param {Object} settings Dialog settings mimicking jQuery UI for compatibility.
       */
      _openDialog(settings) {
        settings = {
          ...this._options,
          ...settings,
        };
        dispatchDialogEvent('beforecreate', this, this._element, settings);
        this.applyOptions(settings);

        this._dockObserver = new ResizeObserver(this._onDockResize.bind(this));

        // Start observing the inner <dialog> for resize once the element fires
        // its 'open' event, ensuring the shadow DOM is fully rendered.
        this._element.addEventListener(
          'open',
          () => {
            this._dockObserver.observe(
              this._element.shadowRoot.querySelector('dialog'),
            );
          },
          { once: true },
        );

        this._element[settings.modal ? 'showModal' : 'show']();

        // Set autoResize to false to prevent Drupal core's jQuery dialog from
        // attempting to resize, which would throw an error.
        const originalResizeSetting = settings.autoResize;
        settings.autoResize = false;
        dispatchDialogEvent('aftercreate', this, this._element, settings);
        settings.autoResize = originalResizeSetting;

        this._element.addEventListener('close', () => {
          this.close();
        }, { once: true });
      }

      show() {
        this._openDialog({ modal: false });
      }

      showModal() {
        this._openDialog({ modal: true });
      }
    }

    // Shared flag across all instances — prevents cascade when a resize on one
    // docked dialog propagates via the shared CSS variable to other instances.
    MercuryDialog.dockResizing = false;

    const dialogInstances = new WeakMap();

    Drupal.mercuryDialog = (element, options = {}) => {
      let dialogInstance = dialogInstances.get(element);

      if (!dialogInstance) {
        const mergedOptions = {
          ...drupalSettings.dialog,
          ...drupalSettings.mercuryEditor,
          ...options,
        };
        dialogInstance = new MercuryDialog(element, mergedOptions);
        dialogInstances.set(element, dialogInstance);
      } else if (Object.keys(options).length > 0) {
        applySizingVars(element, options);
        dialogInstance.applyOptions(options);
      }

      return dialogInstance;
    };

    Drupal.behaviors.mercuryDialog = {
      attach: (context) => {
        // Provide a known 'drupal-mercury-dialog' DOM element for Drupal-based modal
        // dialogs. Non-modal dialogs are responsible for creating their own
        // elements, since there can be multiple non-modal dialogs at a time.

        if (!$('#drupal-mercury-dialog').length) {
          // Add 'ui-front' jQuery UI class so jQuery UI widgets like autocomplete
          // sit on top of dialogs. For more information see
          // http://api.jqueryui.com/theming/stacking-elements/.
          // @todo: .ui-front just sets a z-index of 100 which is not high enough
          // to overlay Gin's Toolbar.
          $(
            '<mercury-dialog id="drupal-mercury-dialog"></mercury-dialog>',
          ).appendTo('body');
        }

        // Special behaviors specific when attaching content within a dialog.
        // These behaviors usually fire after a validation error inside a dialog.
        const $dialog = $(context).closest('mercury-dialog');
        if ($dialog.length) {
          $dialog.trigger('dialogButtonsChange');
        }
      },

      prepareDialogButtons: function prepareDialogButtons($dialog) {
        const buttons = [];
        const dialogElement = $dialog[0];
        const allFormActions = dialogElement.querySelectorAll('.form-actions');
        if (allFormActions.length === 0) {
          return buttons;
        }
        const formActions = allFormActions[allFormActions.length - 1];
        formActions
          .querySelectorAll('input[type=submit], a.button, a.action-link')
          .forEach((button) => {
            button.style.display = 'none';
            buttons.push({
              text: button.textContent || button.getAttribute('value'),
              class: button.className,
              click: function click(e) {
                if (button.tagName.toLowerCase() === 'a') {
                  button.click();
                } else {
                  ['mousedown', 'mouseup', 'click'].forEach((type) => {
                    button.dispatchEvent(
                      new MouseEvent(type, {
                        bubbles: true,
                        cancelable: true,
                        view: window,
                      }),
                    );
                  });
                }
                e.preventDefault();
              },
            });
          });

        return buttons;
      },
    };

    // Moves Layout Paragraphs form buttons into the dialog button pane.
    function moveFormButtonsToDialog(event, dialog, $dialog) {
      if (!isMercuryDialog($dialog)) return;
      if ($dialog.attr('id').indexOf('lpb-dialog-') === 0) {
        const buttons =
          Drupal.behaviors.mercuryDialog.prepareDialogButtons($dialog);
        if (buttons.length) {
          Drupal.mercuryDialog($dialog[0]).applyOptions({ buttons });
        }
      }
    }

    /**
     * ResizeObserver callback that resizes the parent iframe based on
     * the height of the child document html element.
     *
     * @param {HTMLIFrameElement} iframe The iframe element to resize.
     * @return {Function} The callback function.
     */
    function onBodyResize(iframe) {
      return (entries) => {
        // Resize the parent iframe based on the html's border box height.
        if (iframe && entries.length) {
          iframe.style.height = `${entries[0].borderBoxSize[0].blockSize + 1}px`;
          iframe.style.width = `${entries[0].borderBoxSize[0].inlineSize + 1}px`;
        }
      };
    }

    /**
     * Set a max-width on the iframe's <body> to match the dialog's
     * max-width to prevent horizontal scrolling.
     *
     * @param {HTMLIFrameElement} iframe
     *   The iframe element within a mercury dialog.
     * @param {HTMLBodyElement} framedBody
     *   The body element within the iframe.
     */
    function setFrameBodyMaxWidth(iframe, framedBody) {
      const dialogStyles = window.getComputedStyle(
        iframe.closest('mercury-dialog').shadowRoot.querySelector('dialog'),
      );
      const dialogMainStyles = window.getComputedStyle(
        iframe.closest('mercury-dialog').shadowRoot.querySelector('main'),
      );
      framedBody.style.maxWidth = `calc(${dialogStyles.getPropertyValue('max-width')} - ${dialogMainStyles.getPropertyValue('padding-left')} - ${dialogMainStyles.getPropertyValue('padding-right')} - 2px)`;
    }

    /**
     * Resizes an iFrame to match the height of its inner <body> element.
     * @param {HTMLIFrameElement} iframe The iframe element to resize.
     */
    function resizeIframe(iframe) {
      const framedBody = iframe.contentWindow.document.body;
      framedBody.style.width = 'max-content';
      framedBody.style.height = 'fit-content';

      setFrameBodyMaxWidth(iframe, framedBody);

      // Observe changes to the iframe's inner <body> dimensions.
      new ResizeObserver(onBodyResize(iframe)).observe(framedBody, {
        box: 'border-box',
      });
    }

    function updateIframeSize(event, dialog, $dialog) {
      if (!isMercuryDialog($dialog)) return;

      const iframe = $dialog[0].querySelector('iframe');
      if (!iframe) {
        return;
      }

      $dialog[0].style.setProperty('--me-dialog-height-default', 'fit-content');

      const framedBody = iframe?.contentWindow?.document?.body;
      iframe.onload = () => {
        resizeIframe(iframe);
        setFrameBodyMaxWidth(iframe, framedBody);
      };
      if (framedBody) {
        window.addEventListener('resize', () => {
          setFrameBodyMaxWidth(iframe, framedBody);
        });
      }
    }

    // Store open modals.
    const modalStack = [];

    // The following event handlers are used to manage the modal stack.
    // Since native dialog['modal'] elements live in the browser's top-level,
    // we need to make sure any jQuery ui modals that are opened from within
    // a mercury-dialog element get nested within the top-level modal.
    // Otherwise, the jQuery dialog will be obscured by the mercury-dialog modal.
    // See https://developer.chrome.com/blog/what-is-the-top-layer/
    function addModalToStack(event, dialog, $dialog) {
      if (!isMercuryDialog($dialog)) return;
      if (isModalDialog($dialog[0])) {
        modalStack.push($dialog);
      }
    }

    function removeModalFromStack(event, dialog, $dialog) {
      if (!isMercuryDialog($dialog)) return;
      if (isModalDialog($dialog[0])) {
        const index = modalStack.indexOf($dialog);
        if (index > -1) {
          modalStack.splice(index, 1);
        }
      }
    }

    function nestDialogInModal(event, dialog, $dialog) {
      if (!isMercuryDialog($dialog)) return;
      if (modalStack.length > 0) {
        const $parent = $dialog.parent('.ui-dialog');
        const $overlay = $parent.next('.ui-widget-overlay');
        modalStack.slice(-1)[0].append([$parent, $overlay]);
      }
    }

    $(window).on('dialog:aftercreate', moveFormButtonsToDialog);
    $(window).on('dialog:aftercreate', updateIframeSize);
    $(window).on('dialog:aftercreate', addModalToStack);
    $(window).on('dialog:aftercreate', nestDialogInModal);
    $(window).on('dialog:beforeclose', removeModalFromStack);
  })(jQuery, Drupal, drupalSettings);

})();
