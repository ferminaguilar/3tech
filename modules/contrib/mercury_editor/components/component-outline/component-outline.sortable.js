(function () {
  'use strict';

  ((Drupal, once, Sortable) => {
    /**
     * Returns a list of errors for the "accepts" Sortable callback, or an empty array if there are no errors.
     * @param {Element} settings The builder settings.
     * @param {Element} el The element being moved.
     * @param {Element} target The destination
     * @param {Element} source The source
     * @param {Element} sibling The next sibling element
     * @return {Array} An array of errors.
     */
    function acceptsErrors(settings, el, target, source, sibling) {
      return Drupal._lpbMoveErrors.accepts
        .map((validator) =>
          validator.apply(null, [settings, el, target, source, sibling]),
        )
        .filter((errors) => errors !== false && errors !== undefined);
    }

    /**
     * Returns a list of errors for the "moves" Sortable callback, or an empty array if there are no errors.
     * @param {Element} settings The builder settings.
     * @param {Element} el The element being moved.
     * @param {Element} source The source
     * @param {Element} handle The drag handle being grabbed
     * @return {Array} An array of errors.
     */
    function movesErrors(settings, el, source, handle) {
      const moveErrors = Drupal._lpbMoveErrors.moves
        .map((validator) => validator.apply(null, [settings, el, source, handle]))
        .filter((errors) => errors !== false && errors !== undefined);
      return moveErrors;
    }

    function onReorder(mercuryEditorEntityId, layoutId, componentUuid) {
      const order = Array.from(
        document.querySelectorAll(
          '.me-component-outline__list .me-component-outline__component',
        ),
      ).map((item) => {
        return {
          uuid: item.getAttribute('data-uuid'),
          parentUuid:
            item.parentNode.closest('[data-uuid]')?.getAttribute('data-uuid') ||
            null,
          region:
            item.closest('[data-region]')?.getAttribute('data-region') || null,
        };
      });
      Drupal.mercuryEditorBus.post('component:reorder', {
        meId: mercuryEditorEntityId,
        layoutId,
        components: order,
        componentUuid,
      });
    }

    Drupal.behaviors.mercuryEditorComponentOutlineSortable = {
      attach: function attach(context, drupalSettings) {
        once(
          'me-component-outline-sortable',
          '.me-component-outline__list, .me-component-outline__components',
          context,
        ).forEach((element) => {
          // Initialize SortableJS on the outline list.
          const layoutId = element
            .closest('[data-layout-id]')
            .getAttribute('data-layout-id');
          const mercuryEditorEntityId = element
            .closest('[data-mercury-editor-id]')
            .getAttribute('data-mercury-editor-id');
          const settings = drupalSettings.lpBuilder[layoutId] || {};
          Sortable.create(element, {
            draggable: '.me-component-outline__component',
            group: `components-${layoutId}`,
            direction: 'vertical',
            invertSwap: true,
            swapThreshold: 0.5,
            ghostClass: 'me-component-outline-sortable-ghost',
            filter: (event) =>
              movesErrors(settings, null, null, event.target).length > 0,
            onStart: (event) => {
              // Clone the element and add a class for styling.
              // This is the element that will be left in its original place to
              // retain original dimensions and prevent content reflow while
              // dragging.
              const original = event.item;
              const clone = original.cloneNode(true); // Clone the element
              original
                .closest('.me-component-outline__list')
                .classList.add('me-is-sorting');
              clone.querySelector('.me-component-outline-drag-image')?.remove();
              clone.classList.add('me-component-outline-sortable-placeholder'); // Add a class for styling
              clone.classList.remove('me-component-outline-sortable-ghost');
              original.parentNode.insertBefore(clone, original); // Keep the clone in place
            },
            onMove: (event) => {
              // Item cannot be dragged into the destination.
              if (
                acceptsErrors(
                  settings,
                  event.dragged,
                  event.to,
                  event.from,
                  event.related,
                ).length > 0
              ) {
                if (event.to) {
                  event.to.classList.add('sortable-to--disallowed');
                }
                return false;
              }
              // Prevent dropping directly before the cloned placeholder.
              if (event.related && event.to) {
                const children = Array.from(event.to.children);
                const index = children.indexOf(event.related);
                const nextSibling = event.willInsertAfter
                  ? children[index + 1]
                  : children[index];
                if (
                  nextSibling &&
                  nextSibling.classList.contains(
                    'me-component-outline-sortable-placeholder',
                  )
                ) {
                  return false;
                }
              }
            },
            onEnd: (event) => {
              // Remove the cloned placeholder element.
              document
                .querySelectorAll('.me-component-outline-sortable-placeholder')
                .forEach((placeholderElement) => placeholderElement.remove());
              const { item } = event;
              item
                .closest('.me-component-outline__list')
                .classList.remove('me-is-sorting');
              function getDepth(node) {
                let depth = 0;
                while (
                  // eslint-disable-next-line no-cond-assign
                  (node = node.parentNode.closest(
                    '.me-component-outline__component',
                  ))
                ) {
                  depth += 1;
                }
                return depth;
              }
              item.style.setProperty('--me-treeitem-depth', getDepth(item));
              item
                .querySelectorAll(
                  '.me-component-outline__region, .me-component-outline__component',
                )
                .forEach((child) => {
                  child.style.setProperty('--me-treeitem-depth', getDepth(child));
                });
              const uuid = item.closest('[data-uuid]').getAttribute('data-uuid');
              onReorder(mercuryEditorEntityId, layoutId, uuid);
            },
          });
        });
      },
    };

    /**
     * Sets a custom drag image for components being dragged for better control of styling.
     */
    document.addEventListener('dragstart', (event) => {
      if (event.target.classList.contains('me-component-outline__component')) {
        const dragImage = event.target.cloneNode(true);
        dragImage.classList.add('me-component-outline-drag-image');
        // Remove all child nodes except the controls.
        dragImage.childNodes.forEach((child) => {
          if (
            child.classList &&
            !child.classList.contains('me-component-outline__component-controls')
          ) {
            child.remove();
          }
        });
        Object.assign(dragImage.style, {
          position: 'absolute',
          top: '-9999px',
          left: '-9999px',
          pointerEvents: 'none',
          width: `${event.target.offsetWidth}px`,
        });
        // Temporarily add the drag image to the DOM to ensure styles are applied.
        event.target.prepend(dragImage);

        // Calculate the mouse offset within the source element
        const srcRect = event.target.getBoundingClientRect();
        const mouseX = event.clientX;
        const mouseY = event.clientY;
        const rawOffsetX = mouseX - srcRect.left;
        const rawOffsetY = mouseY - srcRect.top;

        event.target.classList.add('me-component-outline-is-dragging');
        event.dataTransfer.setDragImage(dragImage, rawOffsetX, rawOffsetY);
        // Remove element once drag image is captured.
        setTimeout(() => {
          dragImage.remove();
        }, 0);
      }
    });
  })(Drupal, once, Sortable);

})();
