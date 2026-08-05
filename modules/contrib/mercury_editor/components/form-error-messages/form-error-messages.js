((Drupal, once) => {
  class MercuryFormErrorsDisclosure {
    /**
     * Create a new MercuryFormErrorsDisclosure instance.
     * @param {Element} container The container element for the error messages.
     */
    constructor(container) {
      this.container = container;
      this.button = container.parentNode.querySelector(
        '.me-form-error-messages__button',
      );
    }

    /**
     * Initialize the disclosure widget.
     */
    init() {
      const isExpanded =
        sessionStorage.getItem(MercuryFormErrorsDisclosure.getStorageKey()) ===
        'true';
      this.container.toggleAttribute('hidden', !isExpanded);
      this.updateButton();
      this.button.addEventListener('click', this.toggle.bind(this));
    }

    /**
     * Get the storage key for this instance.
     * @return {string} The storage key.
     */
    static getStorageKey() {
      // Use the last segment of the URL (the ME uuid) and concat "-form-errors".
      const urlSegments = window.location.pathname.split('/');
      const uuid = urlSegments[urlSegments.length - 1];
      return `MercuryFormErrorsDisclosure.${uuid}`;
    }

    /**
     * Update the button text and aria-expanded attribute.
     */
    updateButton() {
      const isHidden = this.container.hasAttribute('hidden');
      this.button.setAttribute('aria-expanded', (!isHidden).toString());
      if (isHidden) {
        this.button.textContent =
          this.button.getAttribute('data-label-show') ||
          Drupal.t('Show errors');
      } else {
        this.button.textContent =
          this.button.getAttribute('data-label-hide') ||
          Drupal.t('Hide errors');
      }
    }

    /**
     * Toggle the visibility of the error messages.
     */
    toggle() {
      const isHidden = this.container.hasAttribute('hidden');
      if (isHidden) {
        this.container.removeAttribute('hidden');
        this.button.setAttribute('aria-expanded', 'true');
        this.button.textContent =
          this.button.getAttribute('data-label-hide') ||
          Drupal.t('Hide errors');
        sessionStorage.setItem(
          MercuryFormErrorsDisclosure.getStorageKey(),
          'true',
        );
      } else {
        this.container.setAttribute('hidden', 'true');
        this.button.setAttribute('aria-expanded', 'false');
        this.button.textContent =
          this.button.getAttribute('data-label-show') ||
          Drupal.t('Show errors');
        sessionStorage.setItem(
          MercuryFormErrorsDisclosure.getStorageKey(),
          'false',
        );
      }
    }
  }

  /**
   * Drupal specific behaviors for the Mercury Error Disclosure component.
   */
  Drupal.behaviors.mercuryEditorFormErrors = {
    /**
     * Attach behaviors for the Mercury Error Disclosure component.
     *
     * @param {(Document|HTMLElement)} context
     *  The context in which to attach the behaviors.
     */
    attach(context) {
      once('me-error-messages', '.me-form-error-messages', context).forEach(
        (container) => {
          const disclosure = new MercuryFormErrorsDisclosure(container);
          disclosure.init();
        },
      );
    },
  };
})(Drupal, once);
