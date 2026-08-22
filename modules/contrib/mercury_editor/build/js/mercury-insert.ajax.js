(function () {
  'use strict';

  ((Drupal, drupalSettings) => {
    /**
     * Parses returned HTML into a document fragment.
     *
     * @param {string} htmlString
     *   The markup returned by an AJAX response.
     *
     * @returns {DocumentFragment}
     *   A fragment containing the parsed nodes.
     */
    const parseFragment = (htmlString) => {
      const template = document.createElement('template');
      template.innerHTML = htmlString;
      return template.content;
    };

    /**
     * Extracts top-level inserted elements for behavior attachment.
     *
     * @param {DocumentFragment} fragment
     *   The fragment that will be inserted.
     *
     * @returns {Element[]}
     *   Top-level elements in the fragment.
     */
    const getInsertedElements = (fragment) =>
      Array.from(fragment.childNodes).filter(
        (node) => node.nodeType === Node.ELEMENT_NODE,
      );

    const insertionHandlers = {
      before(target, fragment) {
        target.parentNode.insertBefore(fragment, target);
      },
      after(target, fragment) {
        target.parentNode.insertBefore(fragment, target.nextSibling);
      },
      append(target, fragment) {
        target.appendChild(fragment);
      },
      prepend(target, fragment) {
        target.insertBefore(fragment, target.firstChild);
      },
    };

    /**
     * Inserts content for one matched selector target.
     *
     * @param {object} response
     *   The AJAX response command.
     * @param {'before'|'after'|'append'|'prepend'} operation
     *   The insertion operation to execute.
     * @param {object} settings
     *   Drupal settings associated with this command.
     */
    const runInsert = (response, operation, settings) => {
      if (!response.selector || !response.data) {
        return;
      }

      const insertHandler = insertionHandlers[operation];
      if (!insertHandler) {
        return;
      }

      const matches = document.querySelectorAll(response.selector);
      if (!matches.length) {
        return;
      }

      const target =
        operation === 'after' || operation === 'append'
          ? matches[matches.length - 1]
          : matches[0];

      if (!target.parentNode) {
        return;
      }

      const fragment = parseFragment(response.data);
      const insertedElements = getInsertedElements(fragment);

      insertHandler(target, fragment);

      insertedElements.forEach((element) => {
        if (document.documentElement.contains(element)) {
          Drupal.attachBehaviors(element, settings);
        }
      });
    };

    /**
     * Inserts content before the first matched element.
     */
    Drupal.AjaxCommands.prototype.mercuryBefore = function (ajax, response) {
      const settings = response.settings || ajax.settings || drupalSettings;
      runInsert(response, 'before', settings);
    };

    /**
     * Inserts content after the last matched element.
     */
    Drupal.AjaxCommands.prototype.mercuryAfter = function (ajax, response) {
      const settings = response.settings || ajax.settings || drupalSettings;
      runInsert(response, 'after', settings);
    };

    /**
     * Appends content to the last matched element.
     */
    Drupal.AjaxCommands.prototype.mercuryAppend = function (ajax, response) {
      const settings = response.settings || ajax.settings || drupalSettings;
      runInsert(response, 'append', settings);
    };

    /**
     * Prepends content to the first matched element.
     */
    Drupal.AjaxCommands.prototype.mercuryPrepend = function (ajax, response) {
      const settings = response.settings || ajax.settings || drupalSettings;
      runInsert(response, 'prepend', settings);
    };
  })(Drupal, drupalSettings);

})();
