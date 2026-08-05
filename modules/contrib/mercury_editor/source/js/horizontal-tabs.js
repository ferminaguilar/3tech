/**
 * Manages Mercury Editor tab visibility and dialog tab placement.
 *
 * Responsibilities:
 * 1. Controls visibility of `.me-tab-group` elements based on selected
 *    radio-based tabs.
 * 2. Ensures the correct tab is shown on initial load and when validation
 *    errors are present.
 * 3. Repositions horizontal tab controls into the header of Layout Paragraphs
 *    modal dialogs after they are created.
 *
 * Design notes:
 * - Tabs are implemented using radio inputs for accessibility and state
 *   persistence.
 * - Tab groups are toggled using both `aria-hidden` and a CSS class to support
 *   screen readers and visual hiding.
 * - Error handling intentionally overrides the selected tab to surface
 *   validation errors.
 * - Dialog manipulation is coupled to jQuery UI dialog markup and should be
 *   updated if the dialog system changes.
 *
 * This behavior is global and UI-focused.
 * Avoid adding business logic or editor state mutations here.
 */
(($, Drupal, once) => {
  function toggleTabs(selectedValue) {
    (document.querySelectorAll('.me-tab-group') || []).forEach((tabGroup) => {
      tabGroup.setAttribute('aria-hidden', true);
      tabGroup.classList.add('hidden-tab');
    });
    (
      document.querySelectorAll(`.me-tab-group--${selectedValue}`) || []
    ).forEach((tabGroup) => {
      tabGroup.removeAttribute('aria-hidden');
      tabGroup.classList.remove('hidden-tab');
    });
  }

  Drupal.behaviors.mercuryEditorTabs = {
    attach() {
      once('me-tabs', '.me-tabs input[type="radio"]').forEach((input) => {
        input.addEventListener('change', () => {
          toggleTabs(input.value);
        });
      });
      document.querySelectorAll('.me-tabs').forEach((tabs) => {
        const selected =
          tabs.querySelector('input[type="radio"]:checked') || {};
        toggleTabs(selected.value);
      });
      if (document.querySelector('.me-tab-group .error')) {
        const tabGroup = document
          .querySelector('.me-tab-group .error')
          .closest('.me-tab-group');
        // Get the class that starts with "me-tab-group--" and get the part after "--".
        const selectedValue = tabGroup.className.match(
          /me-tab-group--([^ ]+)/,
        )[1];
        // Get the tab radio button with the same value as the tab group.
        const selected = document.querySelector(
          `.me-tabs input[type="radio"][value="${selectedValue}"]`,
        );
        if (selected) {
          selected.checked = true;
          toggleTabs(selectedValue);
        }
      }
    },
  };

  /**
   * Repositions horizontal tabs into the header of a modal dialog.
   */
  $(window).on('dialog:aftercreate', (event, dialog, $dialog) => {
    const id = $dialog.attr('id');
    if (id && id.indexOf('lpb-dialog-') === 0) {
      const $tabs = $dialog.find('.horizontal-tab-radios');
      const $titlebar = $dialog
        .closest('.ui-dialog')
        .find('.ui-dialog-titlebar');
      if ($tabs.length && $titlebar.length) {
        $titlebar.addClass('has-tabs').append($tabs);
      }
    }
  });
})(jQuery, Drupal, once);
