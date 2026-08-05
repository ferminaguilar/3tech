var focus_manager = (function (exports) {
  'use strict';

  const lastActiveByForm = new WeakMap();

  function trackFocus() {
    if (trackFocus.initialized) return;
    trackFocus.initialized = true;

    document.addEventListener('focusin', (event) => {
      const form = event.target?.closest?.('form');
      if (form) {
        lastActiveByForm.set(form, event.target);
      }
    });
  }

  function restoreFormFocus(form, { fallback } = {}) {
    const el = lastActiveByForm.get(form);
    if (el && document.contains(el) && typeof el.focus === 'function') {
      el.focus();
      return true;
    }

    if (fallback?.focus) {
      fallback.focus();
      return true;
    }

    return false;
  }

  exports.restoreFormFocus = restoreFormFocus;
  exports.trackFocus = trackFocus;

  Object.defineProperty(exports, '__esModule', { value: true });

  return exports;

})({});
