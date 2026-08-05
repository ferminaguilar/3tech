(function () {
  'use strict';

  ((Drupal, drupalSettings) => {
    const SIDE = drupalSettings.mercuryEditor.isPreview ? 'preview' : 'editor';

    // Exact handlers: type -> Set<fn>
    const handlers = new Map();

    // Wildcard handlers: pattern -> { prefix, set }
    // pattern format supported: "<prefix>*" (example: "preview:*", "component-event:*")
    const wildcardHandlers = new Map();

    function routeByType(type) {
      return type.split(':')[0] === 'preview' ? 'preview' : 'editor';
    }

    function getTargetWindow(side) {
      if (side === 'preview') {
        return drupalSettings.mercuryEditor.isPreview
          ? window
          : document.querySelector('#me-preview')?.contentWindow;
      }
      return drupalSettings.mercuryEditor.isPreview ? window.parent : window;
    }

    function isWildcard(pattern) {
      return typeof pattern === 'string' && pattern.endsWith('*');
    }

    function getWildcardPrefix(pattern) {
      // Supports "preview:*" and "preview:" styles (both end with *)
      // Example: "preview:*" -> "preview:"
      // Example: "component-event:*" -> "component-event:"
      if (pattern.endsWith(':*')) return pattern.slice(0, -1); // keep trailing ':'
      return pattern.slice(0, -1); // remove '*'
    }

    function on(typeOrPattern, handler) {
      if (isWildcard(typeOrPattern)) {
        const prefix = getWildcardPrefix(typeOrPattern);
        if (!wildcardHandlers.has(typeOrPattern)) {
          wildcardHandlers.set(typeOrPattern, { prefix, set: new Set() });
        }
        const entry = wildcardHandlers.get(typeOrPattern);
        entry.set.add(handler);

        return () => entry.set.delete(handler);
      }

      if (!handlers.has(typeOrPattern)) handlers.set(typeOrPattern, new Set());
      handlers.get(typeOrPattern).add(handler);
      return () => handlers.get(typeOrPattern)?.delete(handler);
    }

    function deliver(msg, meta) {
      const { type } = msg;

      // Exact match
      const exactSet = handlers.get(type);
      if (exactSet) {
        Array.from(exactSet).forEach((fn) => fn(msg, meta));
      }

      // Wildcard matches
      if (wildcardHandlers.size) {
        wildcardHandlers.forEach(({ prefix, set }) => {
          if (!set.size) return;
          if (!type.startsWith(prefix)) return;

          Array.from(set).forEach((fn) => fn(msg, meta));
        });
      }
    }

    function post(type, payload, opts = {}) {
      const to = opts.to ?? routeByType(type);
      if (!to) throw new Error(`No route for type "${type}"`);

      const payloadIsObject = payload && typeof payload === 'object';
      const hops = Number(opts.hops ?? (payloadIsObject ? payload.hops : 0) ?? 0);

      const envelope = {
        channel: 'mercury-editor',
        type,
        payload: payload ?? null,
        to,
        from: SIDE,
        hops,
      };

      // Optional hard safety net
      if (envelope.hops > 8) {
        // eslint-disable-next-line no-console
        console.warn(
          'mercuryEditorBus: dropping message due to hop limit',
          envelope,
        );
        return;
      }

      if (to === SIDE) {
        queueMicrotask(() => deliver(envelope, { local: true }));
        return;
      }

      const target = getTargetWindow(to);
      if (!target) {
        // eslint-disable-next-line no-console
        console.warn('mercuryEditorBus: target window unavailable', {
          to,
          envelope,
        });
        return;
      }

      target.postMessage({ ...envelope, hops: envelope.hops + 1 }, window.origin);
    }

    // Inbound cross-window messages only
    window.addEventListener('message', (event) => {
      const msg = event.data;
      if (!msg || msg.channel !== 'mercury-editor') return;
      if (msg.to !== SIDE) return;

      // Loop safety: ignore messages that claim to be from me
      if (msg.from === SIDE) return;

      deliver(msg, { event, local: false });
    });

    Drupal.mercuryEditorBus = { post, on };
  })(Drupal, drupalSettings);

})();
