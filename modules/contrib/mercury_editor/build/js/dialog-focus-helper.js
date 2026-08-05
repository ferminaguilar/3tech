(function () {
  'use strict';

  /**
   * Preserves focus across Drupal dialog creation.
   *
   * Drupal dialogs steal focus when they are created, which can break editor
   * keyboard workflows and cause unexpected focus jumps.
   *
   * This listener:
   * - Captures the currently focused element before a dialog is created
   * - Restores focus after dialog creation
   * - Only restores focus if the element explicitly opts in via
   *   `data-keep-focus="true"`
   *
   * This is intentionally global and side-effectful.
   * Do NOT move this into a component or behavior without understanding the
   * dialog lifecycle and focus implications.
   *
   * Related events:
   * - dialog:beforecreate
   * - dialog:aftercreate
   */
  (() => {
    let lastActiveElement = null;

    document.addEventListener('dialog:beforecreate', () => {
      lastActiveElement = document.activeElement;
    });

    document.addEventListener('dialog:aftercreate', (event) => {
      setTimeout(() => {
        if (
          lastActiveElement &&
          typeof lastActiveElement.focus === 'function' &&
          lastActiveElement.getAttribute('data-keep-focus') === 'true' &&
          lastActiveElement !== document.activeElement
        ) {
          lastActiveElement.focus();
        } else if (event.target) {
          // Focus for form element inside the dialog if no element to restore focus to.
          const firstFocusable = event.target.querySelector(
            'input:not([data-once="me-tabs"]), select, textarea, button, a[href], [tabindex]:not([tabindex="-1"])',
          );
          if (firstFocusable && typeof firstFocusable.focus === 'function') {
            firstFocusable.focus();
          }
        }
      });
    });
  })();

})();
