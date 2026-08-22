/**
 * @file
 * Core Mercury Editor DOM morph helpers.
 */

import morphdom from 'morphdom';

const EVENT_PREFIX = 'mercury-editor:morph';

/**
 * Dispatches a Mercury Editor morph event.
 *
 * @param {EventTarget} target
 *   The target on which to dispatch the event.
 * @param {string} name
 *   The event name, without the Mercury Editor prefix.
 * @param {Object} detail
 *   Event details.
 * @param {boolean} cancelable
 *   Whether listeners may cancel the event.
 *
 * @return {CustomEvent}
 *   The dispatched event.
 */
function dispatchMorphEvent(
  target,
  name,
  detail = {},
  cancelable = false,
) {
  const event = new CustomEvent(`${EVENT_PREFIX}:${name}`, {
    bubbles: true,
    cancelable,
    detail,
  });

  target.dispatchEvent(event);

  return event;
}

/**
 * Morphs one element into another and emits Mercury Editor events.
 *
 * @param {Element} fromElement
 *   The existing DOM element.
 * @param {Element|string} toElement
 *   The incoming element or HTML.
 * @param {Object} options
 *   Additional morphdom options.
 *
 * @return {Node}
 *   The resulting node.
 */
export function morphElement(fromElement, toElement, options = {}) {
  const changes = {
    added: [],
    updated: [],
    removed: [],
  };

  const beforeEvent = dispatchMorphEvent(
    fromElement,
    'before',
    {
      fromElement,
      toElement,
    },
    true,
  );

  if (beforeEvent.defaultPrevented) {
    return fromElement;
  }

  const result = morphdom(fromElement, toElement, {
    ...options,

    onBeforeElUpdated(fromEl, toEl) {
      const event = dispatchMorphEvent(
        fromEl,
        'before-element-update',
        {
          fromElement: fromEl,
          toElement: toEl,
        },
        true,
      );

      if (event.defaultPrevented) {
        return false;
      }

      if (options.onBeforeElUpdated) {
        return options.onBeforeElUpdated(fromEl, toEl);
      }

      return true;
    },

    onElUpdated(element) {
      changes.updated.push(element);

      options.onElUpdated?.(element);
    },

    onBeforeNodeAdded(node) {
      const detail = {
        node,
        replacement: null,
      };

      const event = dispatchMorphEvent(
        fromElement,
        'before-node-add',
        detail,
        true,
      );

      if (event.defaultPrevented) {
        return false;
      }

      const optionResult = options.onBeforeNodeAdded?.(node);

      if (optionResult === false) {
        return false;
      }

      if (optionResult instanceof Node) {
        return optionResult;
      }

      return detail.replacement instanceof Node
        ? detail.replacement
        : node;
    },

    onNodeAdded(node) {
      changes.added.push(node);

      options.onNodeAdded?.(node);
    },

    onBeforeNodeDiscarded(node) {
      const event = dispatchMorphEvent(
        node,
        'before-node-remove',
        {
          node,
          morphRoot: fromElement,
        },
        true,
      );

      if (event.defaultPrevented) {
        return false;
      }

      if (options.onBeforeNodeDiscarded) {
        return options.onBeforeNodeDiscarded(node);
      }

      return true;
    },

    onNodeDiscarded(node) {
      changes.removed.push(node);

      options.onNodeDiscarded?.(node);
    },

    onBeforeElChildrenUpdated(fromEl, toEl) {
      if (options.onBeforeElChildrenUpdated) {
        return options.onBeforeElChildrenUpdated(fromEl, toEl);
      }

      return true;
    },
  });

  dispatchMorphEvent(result, 'complete', {
    root: result,
    changes,
  });

  return result;
}
