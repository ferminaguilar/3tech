/**
 * @file
 * Defines the Mercury Editor morph AJAX command.
 */

import { morphElement } from './lib/mercury-morph.core';

(function (Drupal, drupalSettings) {

  /**
   * Morphs server-rendered markup into an existing element.
   *
   * @param {Drupal.Ajax} ajax
   *   The Drupal AJAX object.
   * @param {object} response
   *   The AJAX command response.
   * @param {string} response.selector
   *   Selector for the element to morph.
   * @param {string} response.data
   *   The server-rendered replacement markup.
   * @param {object} [response.settings]
   *   Drupal settings associated with the response.
   * @param {number} status
   *   The HTTP response status.
   */
  Drupal.AjaxCommands.prototype.mercuryMorph = function (
    ajax,
    response,
    status,
  ) {
    const settings =
      response.settings || ajax.settings || drupalSettings;
    const activeUuid = settings.mercuryEditor?.activeComponentUuid;
    const elements = document.querySelectorAll(response.selector);

    elements.forEach((element) => {

      const template = document.createElement('template');
      template.innerHTML = response.data.trim();
      const newElement = template.content.firstElementChild;

      // If an active UUID is provided, find the corresponding element
      // in the new markup and mark it as active.
      if (activeUuid) {
        const selector = `[data-uuid="${activeUuid}"]`;
        const activeElement = newElement.matches(selector)
          ? newElement
          : newElement.querySelector(selector);

        if (activeElement) {
          activeElement.setAttribute('data-active', 'true');
          activeElement.setAttribute('data-active-within', 'true');
          let parent = activeElement.parentNode instanceof Element
            ? activeElement.parentNode.closest('.js-lpb-component')
            : null;
          while (parent) {
            parent.setAttribute('data-active-within', 'true');
            parent = parent.parentNode instanceof Element
              ? parent.parentNode.closest('.js-lpb-component')
              : null;
          }
        }
      }
      morphElement(element, newElement);

      // Existing behaviors protected by once() will not run twice. Behaviors
      // associated with newly added markup can initialize here.
      Drupal.attachBehaviors(element, settings);
    });
  };
})(Drupal, drupalSettings);
