(function () {
  'use strict';

  /**
   * @file
   * Component Outline Navigation functionality.
   *
   * Expected Markup Structure:
   *
   * <div class="me-component-outline">
   *   <ul class="me-component-outline__list" role="tree">
   *     <li>
   *       <div class="me-component-outline__component" role="treeitem" aria-expanded="false">
   *         <div class="me-component-outline__component-controls">
   *           <button class="me-component-outline__component-toggle">Expand</button>
   *           <button class="me-component-outline__component-label">Component Name</button>
   *           <button class="me-component-outline__component-menu-toggle">Menu</button>
   *         </div>
   *       </div>
   *       <ul role="group" aria-hidden="true">
   *         <!-- Child tree items -->
   *       </ul>
   *     </li>
   *   </ul>
   * </div>
   *
   * Initial State:
   * - Tree items with children should have aria-expanded="false" by default
   * - Child groups should have aria-hidden="true" by default
   * - CSS will hide groups with aria-hidden="true" or when parent has aria-expanded="false"
   * - Only tree items that should be initially expanded should have aria-expanded="true"
   */

  class MercuryComponentOutline {

    constructor(outlineElement) {
      this.outlineElement = outlineElement;
      this.treeItems = [];
      this.addButtons = [];
      this.menuToggles = [];
      this.expandToggles = [];
      this.openMenuDialog = null;

      // Generate unique ID for this outline instance
      this.outlineId = this.generateOutlineId();

      // Add the outline to the list of outlines.
      MercuryComponentOutline.outlines.set(this.outlineId, this);

      // Add class instance to element.
      this.outlineElement.componentOutlineNavigation = this;

      this.mount();
    }

    /**
     * Store a set of all component outline ids.
     *
     * @type {Set<string>}
     * @static
     */
    static outlines = new Map();

    /**
     * Set the visually focused component.
     *
     * This method facilitates the visual focus management of components between
     * the outline and the preview.
     *
     * @param {string|null} uuid - The UUID of the component to focus.
     *
     * @static
     * @example
     * MercuryComponentOutline.setHighlightedComponent();
     */
    static setHighlightedComponent(uuid) {
      sessionStorage.setItem('mercuryEditor.highlightedComponent', uuid);

      // Loop over all outlines and set the highlighted component.
      MercuryComponentOutline.outlines.forEach((mercuryOutline) => {
        mercuryOutline.setHighlightedComponent(uuid);
      });
    }

    /**
     * Generates a unique ID for this outline instance.
     * Uses the entity ID from the data attribute.
     *
     * @return {string} The unique outline ID.
     */
    generateOutlineId() {
      const entityId = this.outlineElement.getAttribute('data-entity-id');
      if (entityId) {
        return `MercuryEditorOutline.${entityId}`;
      }

      // Fallback to element index if no entity ID is available
      const elementIndex = Array.from(document.querySelectorAll('.me-component-outline__list')).indexOf(this.outlineElement);
      return `MercuryEditorOutline.${elementIndex}`;
    }

    /**
     * Gets the sessionStorage key for expanded states.
     *
     * @return {string} The storage key for expanded states.
     */
    getExpandedStatesKey() {
      return `${this.outlineId}.expandedStates`;
    }

    /**
     * Gets the sessionStorage key for the active tree item.
     *
     * @return {string} The storage key for the active tree item.
     */
    getActiveTreeItemKey() {
      return `${this.outlineId}.activeTreeItem`;
    }

    /**
     * Saves the expanded states to sessionStorage.
     *
     * @return {void} Does not return a value.
     */
    saveExpandedStates() {
      const expandedStates = {};
      this.treeItems.forEach((treeItem) => {
        const id = this.getTreeItemId(treeItem);
        if (id && this.getExpandButton(treeItem)) {
          expandedStates[id] = treeItem.getAttribute('aria-expanded') === 'true';
        }
      });
      sessionStorage.setItem(this.getExpandedStatesKey(), JSON.stringify(expandedStates));
    }

    /**
     * Loads the expanded states from sessionStorage.
     *
     * @return {object} The expanded states object.
     */
    loadExpandedStates() {
      const stored = sessionStorage.getItem(this.getExpandedStatesKey());
      return stored ? JSON.parse(stored) : {};
    }

    /**
     * Saves the active tree item to sessionStorage.
     *
     * @param {HTMLElement} treeItem - The active tree item.
     * @return {void} Does not return a value.
     */
    saveActiveTreeItem(treeItem) {
      const id = this.getTreeItemId(treeItem);
      if (id) {
        sessionStorage.setItem(this.getActiveTreeItemKey(), id);
      }
    }

    /**
     * Loads the active tree item from sessionStorage.
     *
     * @return {string|null} The active tree item ID or null.
     */
    loadActiveTreeItem() {
      return sessionStorage.getItem(this.getActiveTreeItemKey());
    }

    /**
     * Gets a unique identifier for a tree item.
     *
     * @param {HTMLElement} treeItem - The tree item element.
     * @return {string|null} The tree item ID or null.
     */
    getTreeItemId(treeItem) {
      const componentLabel = treeItem?.querySelector('.me-component-outline__component-label, .me-component-outline__region-label');
      const uuid = componentLabel?.getAttribute('data-uuid');
      if (uuid) {
        return uuid;
      }

      // Fallback: use text content + depth as identifier
      const text = this.getTreeItemLabel(treeItem);
      const depth = treeItem.style.getPropertyValue('--me-treeitem-depth') || '0';
      return text ? `${text.replace(/\s+/g, '_')}_${depth}` : null;
    }

    /**
     * Finds a tree item by its ID.
     *
     * @param {string} id - The tree item ID.
     * @return {HTMLElement|null} The tree item element or null.
     */
    findTreeItemById(id) {
      return this.treeItems.find((treeItem) => this.getTreeItemId(treeItem) === id) || null;
    }

    /**
     * Initializes the mounting process for the component. It selects all button
     * elements within the outline, converts them into an array, and attaches a
     * keydown event listener to each button.
     *
     * @return {void} Does not return a value.
     */
    mount() {
      this.treeItems = Array.from(this.outlineElement.querySelectorAll('.me-component-outline__component[role="treeitem"]'));
      this.addButtons = Array.from(this.outlineElement.querySelectorAll('.me-component-outline__add-button'));
      this.menuToggles = Array.from(this.outlineElement.querySelectorAll('.me-component-outline__component-menu-toggle'));
      this.expandToggles = Array.from(this.outlineElement.querySelectorAll('.me-component-outline__component-toggle'));
      this.currentFocusedItem = null;
      this.typeAheadTimeout = null;
      this.typeAheadString = '';

      // Set up roving tabindex - only first item should be initially focusable
      this.initializeTabIndex();

      this.treeItems.forEach((treeItem) => {
        treeItem.addEventListener('keydown', this.onKeydownTreeItem.bind(this));
        treeItem.addEventListener('focus', this.onFocusTreeItem.bind(this));
      });

      this.menuToggles.forEach((toggle) => {
        toggle.addEventListener('click', this.onMenuToggleClick.bind(this));
        toggle.addEventListener('keydown', this.onMenuToggleKeydown.bind(this));
      });

      this.expandToggles.forEach((toggle) => {
        toggle.addEventListener('click', this.onExpandToggleClick.bind(this));
      });

      // Bind component control click events
      const componentControls = Array.from(this.outlineElement.querySelectorAll('.me-component-outline__component-controls'));
      componentControls.forEach((control) => {
        control.addEventListener('click', this.onComponentControlClick.bind(this));
      });

      const actionButtons = Array.from(this.outlineElement.querySelectorAll('[role="menuitem"]'));
      actionButtons.forEach((buttonElement) => {
        buttonElement.addEventListener('keydown', this.onComponentActionKeydown.bind(this));
        buttonElement.addEventListener('click', this.onComponentActionClick.bind(this));
      });

      const addComponentButtons = Array.from(this.outlineElement.querySelectorAll('button[data-action="add-component"]'));
      addComponentButtons.forEach((buttonElement) => {
        buttonElement.addEventListener('click', this.onAddComponentClick.bind(this));
      });

      // Close menu when clicking outside
      document.addEventListener('click', this.onDocumentClick.bind(this));

      // Set the depth custom property for each tree item.
      this.setDepthCustomProperty();

      // Initialize the default collapsed state for tree items with children
      this.initializeDefaultState();

      // Mark as mounted.
      this.outlineElement.classList.add('is-mounted');
    }

    /**
     * Set depth custom property.
     *
     * Recursively loop through all treeitems, adding setting a --me-treeitem-depth
     * CSS custom property to each treeitem. This is used to set the indentation of
     * nested trees.
     */
    setDepthCustomProperty() {
      const setDepth = (element, depth) => {
        element.style.setProperty('--me-treeitem-depth', depth);
        const children = element.querySelectorAll(':scope > ul > li > ul > [role="treeitem"]');
        children.forEach((child) => setDepth(child, depth + 1));
      };

      const rootItems = this.outlineElement.querySelectorAll(':scope > [role="treeitem"]');
      rootItems.forEach((item) => setDepth(item, 0));
    }

    /**
     * Initialize the default collapsed state for tree items.
     * Tree items with children should be collapsed by default unless
     * explicitly marked as expanded in the markup or stored in sessionStorage.
     *
     * @return {void} Does not return a value.
     */
    initializeDefaultState() {
      const savedExpandedStates = this.loadExpandedStates();

      this.treeItems.forEach((treeItem) => {
        const hasExpandButton = this.getExpandButton(treeItem);

        if (hasExpandButton) {
          const treeItemId = this.getTreeItemId(treeItem);
          const savedState = treeItemId ? savedExpandedStates[treeItemId] : undefined;

          // Priority: sessionStorage > markup > default (collapsed)
          if (savedState !== undefined) {
            // Use saved state from sessionStorage
            savedState ? this.expandTreeItem(treeItem) : this.collapseTreeItem(treeItem);
          } else {
            // Check if aria-expanded is already set in the markup
            const currentState = treeItem.getAttribute('aria-expanded');

            if (currentState === null) {
              // No explicit state set, default to collapsed
              this.collapseTreeItem(treeItem);
            } else if (currentState === 'false') {
              // Explicitly collapsed, ensure it's properly collapsed
              this.collapseTreeItem(treeItem);
            } else if (currentState === 'true') {
              // Explicitly expanded, ensure it's properly expanded
              this.expandTreeItem(treeItem);
            }
          }
        }
      });

      // Restore active tree item from sessionStorage
      this.restoreActiveTreeItem();
    }

    /**
     * Initialize tabindex for roving tabindex pattern.
     * Restores the active tree item from sessionStorage if available.
     *
     * @return {void} Does not return a value.
     */
    initializeTabIndex() {
      // Set all items to -1 initially
      [
        ...this.treeItems,
        ...this.menuToggles,
        ...this.addButtons
      ].forEach((item) => {
        item.tabIndex = -1;
        this.getMenuToggleByTreeItem(item)?.setAttribute('tabindex', -1);
        this.getAddButtonsByTreeItem(item).forEach(
          (button) => button.setAttribute('tabindex', -1)
        );
      });
    }

    /**
     * Restores the active tree item from sessionStorage.
     * If the saved item doesn't exist, defaults to the first tree item.
     * Ensures all parent tree items are expanded when setting the active item.
     *
     * @return {void} Does not return a value.
     */
    restoreActiveTreeItem() {
      this.setHighlightedComponent(sessionStorage.getItem('mercuryEditor.highlightedComponent'));
      const savedActiveId = this.loadActiveTreeItem();
      let activeTreeItem = null;

      if (savedActiveId) {
        activeTreeItem = this.findTreeItemById(savedActiveId);
      }

      // If saved item doesn't exist, default to first tree item
      if (!activeTreeItem && this.treeItems.length > 0) {
        activeTreeItem = this.treeItems[0];
      }

      if (activeTreeItem) {
        // Ensure all parent tree items are expanded
        this.expandParentTreeItems(activeTreeItem);

        // Set as active (tabindex only, don't focus)
        this.setActiveTreeItem(activeTreeItem);
      }
    }

    /**
     * Sets a tree item as active by updating tabindex values only.
     * Does not set focus on the item.
     *
     * @param {HTMLElement} treeItem - The tree item to set as active.
     * @return {void} Does not return a value.
     */
    setActiveTreeItem(treeItem) {
      this.initializeTabIndex();

      // Set tabindex on the target treeitem (but don't focus)
      treeItem.tabIndex = 0;
      this.getMenuToggleByTreeItem(treeItem)?.setAttribute('tabindex', 0);
      this.getAddButtonsByTreeItem(treeItem).forEach(
        (button) => button.setAttribute('tabindex', 0)
      );
      this.currentFocusedItem = treeItem;
      this.expandParentTreeItems(treeItem);

      // Save to sessionStorage
      this.saveActiveTreeItem(treeItem);
    }

    /**
     * Expands all parent tree items of a given tree item.
     *
     * @param {HTMLElement} treeItem - The tree item whose parents should be expanded.
     * @return {void} Does not return a value.
     */
    expandParentTreeItems(treeItem) {
      let currentItem = treeItem;
      const parentsToExpand = [];

      // Collect all parent tree items
      while (currentItem) {
        const parentTreeItem = this.getParentTreeItem(currentItem);
        if (parentTreeItem && parentTreeItem.matches('[aria-expanded]')) {
          parentsToExpand.unshift(parentTreeItem); // Add to beginning to expand from top down
        }
        currentItem = parentTreeItem;
      }

      // Expand all parent tree items
      parentsToExpand.forEach((parentItem) => {
        this.expandTreeItem(parentItem);
      });
    }

    /**
     * Handles focus events on treeitems to track the currently focused item.
     *
     * @param {FocusEvent} event - The focus event.
     * @return {void} Does not return a value.
     */
    onFocusTreeItem(event) {
      this.currentFocusedItem = event.target;
    }

    /**
     * Sets focus to a treeitem and updates tabindex for roving tabindex pattern.
     *
     * @param {HTMLElement} treeItem - The treeitem to focus.
     * @return {void} Does not return a value.
     */
    setFocusToTreeItem(treeItem) {
      this.setActiveTreeItem(treeItem);
      treeItem.focus();
    }

    /**
     * Sets the highlighted component.
     *
     * The highlighted component should be in sync with the preview.
     * There should only be one highlighted item for all outlines combined.
     * It should always be in sync with the active item to ensure proper tab index.
     * It is not necessarily the native focused element.
     *
     * @param {string|null} uuid - The UUID of the component to highlight. Null if none.
     */
    setHighlightedComponent(uuid) {
      this.treeItems.forEach(item => item.classList.remove('is-highlighted'));
      if (uuid) {
        const treeItem = this.findTreeItemById(uuid);
        if (treeItem) {
          this.setActiveTreeItem(treeItem);
          treeItem.classList.add('is-highlighted');
        }
      }
    }

    /**
     * Handles the keydown events for treeitems to enable navigation using arrow keys
     * and other standard treeview keyboard interactions.
     *
     * @param {KeyboardEvent} event The keydown event triggered by the user.
     * @return {void} This method does not return a value.
     */
    onKeydownTreeItem(event) {
      let preventDefault = false;
      const current = event.target;

      switch (event.key) {
        case 'Up':
        case 'ArrowUp':
          preventDefault = true;
          this.focusPreviousVisibleTreeItem(current);
          break;

        case 'Down':
        case 'ArrowDown':
          preventDefault = true;
          this.focusNextVisibleTreeItem(current);
          break;

        case 'Right':
        case 'ArrowRight':
          preventDefault = true;
          this.handleTreeItemRightArrow(current);
          break;

        case 'Left':
        case 'ArrowLeft':
          preventDefault = true;
          this.handleTreeItemLeftArrow(current);
          break;

        case 'Home':
          preventDefault = true;
          this.focusFirstTreeItem();
          break;

        case 'End':
          preventDefault = true;
          this.focusLastVisibleTreeItem();
          break;

        case 'Enter':
          preventDefault = true;
          this.activateTreeItem(current);
          break;

        default:
          // Handle type-ahead
          if (event.key.length === 1 && /[a-zA-Z0-9]/.test(event.key)) {
            preventDefault = true;
            this.handleTypeAhead(event.key);
          }
      }

      if (preventDefault) {
        event.stopPropagation();
        event.preventDefault();
      }
    }

    /**
     * Handles right arrow key press according to ARIA treeview pattern.
     *
     * @param {HTMLElement} current - The currently focused treeitem.
     * @return {void} Does not return a value.
     */
    handleTreeItemRightArrow(current) {
      const expandButton = this.getExpandButton(current);

      if (expandButton && current.getAttribute('aria-expanded') !== 'true') {
        this.expandTreeItem(current);
        return;
      }

      // Open node: move focus to first child
      const firstChild = this.getFirstChildTreeItem(current);
      if (firstChild) {
        this.setFocusToTreeItem(firstChild);
      }

      // End nodes: do nothing
    }

    /**
     * Handles left arrow key press according to ARIA treeview pattern.
     *
     * @param {HTMLElement} current - The currently focused treeitem.
     * @return {void} Does not return a value.
     */
    handleTreeItemLeftArrow(current) {
      const isExpanded = current.getAttribute('aria-expanded') === 'true';

      if (isExpanded) {
        // Open node: close it
        this.collapseTreeItem(current);
      } else {
        // Child node or closed node: move to parent
        const parentTreeItem = this.getParentTreeItem(current);
        if (parentTreeItem) {
          this.setFocusToTreeItem(parentTreeItem);
        }
      }
    }

    /**
     * Handles component selection by sending a message to the preview frame.
     *
     * @param {string} uuid - The component uuid.
     * @return {void} Does not return a value.
     */
    selectComponent(uuid) {
      Drupal.mercuryEditorBus.post('preview:selectComponent', { uuid });
    }

    /**
     * Handles component label clicks.
     *
     * @param {Event} event - The click event.
     * @return {void} Does not return a value.
     */
    onComponentControlClick(event) {
      this.selectComponent(event.target.getAttribute('data-uuid'));
    }

    /**
     * Handles action button clicks.
     *
     * @param {Event} event - The click event.
     * @return {void} Does not return a value.
     */
    onComponentActionClick(event) {
      const buttonElement = event.target.closest('[role="menuitem"]');
      if (!buttonElement) { return; }
      Drupal.mercuryEditorBus.post('component:' + buttonElement.getAttribute('data-action'), {
        meId: buttonElement.closest('[data-mercury-editor-id]').getAttribute('data-mercury-editor-id'),
        layoutId: buttonElement.closest('[data-layout-id]').getAttribute('data-layout-id'),
        uuid: buttonElement.getAttribute('data-component-uuid'),
      });
    }

    /**
     * Handles add component button clicks.
     * @param {*} event - The click event.
     * @returns
     */
    onAddComponentClick(event) {
      const buttonElement = event.target.closest('button[data-action="add-component"]');
      if (!buttonElement) { return; }
      Drupal.mercuryEditorBus.post('component:insert', {
        meId: buttonElement.closest('[data-mercury-editor-id]').getAttribute('data-mercury-editor-id'),
        layoutId: buttonElement.closest('[data-layout-id]').getAttribute('data-layout-id'),
        parentUuid: buttonElement.getAttribute('data-parent-uuid'),
        region: buttonElement.getAttribute('data-region'),
        siblingUuid: buttonElement.getAttribute('data-sibling-uuid'),
        placement: buttonElement.getAttribute('data-placement'),
      });
    }

    /**
     * Handles action button keydown events to allow activation via Enter or Space keys.
     * @param {KeyboardEvent} event - The keydown event.
     * @return {void}
     */
    onComponentActionKeydown(event) {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        this.onComponentActionClick(event);
      }
    }

    /**
     * Activates a treeitem (default action on Enter key).
     *
     * @param {HTMLElement} treeItem - The treeitem to activate.
     * @return {void} Does not return a value.
     */
    activateTreeItem(treeItem) {

      // End node: trigger component selection
      const componentLabel = treeItem?.querySelector('.me-component-outline__component-label');
      if (componentLabel) {
        this.selectComponent(componentLabel.getAttribute('data-uuid'));
      }
    }

    /**
     * Focuses the first treeitem in the tree.
     *
     * @return {void} Does not return a value.
     */
    focusFirstTreeItem() {
      if (this.treeItems.length > 0) {
        this.setFocusToTreeItem(this.treeItems[0]);
      }
    }

    /**
     * Focuses the last visible treeitem in the tree.
     *
     * @return {void} Does not return a value.
     */
    focusLastVisibleTreeItem() {
      const visibleTreeItems = this.getVisibleTreeItems();
      if (visibleTreeItems.length > 0) {
        this.setFocusToTreeItem(visibleTreeItems[visibleTreeItems.length - 1]);
      }
    }

    /**
     * Focuses the previous visible treeitem relative to the current one.
     *
     * @param {HTMLElement} current - The currently focused treeitem.
     * @return {void} Does not return a value.
     */
    focusPreviousVisibleTreeItem(current) {
      const visibleTreeItems = this.getVisibleTreeItems();
      const currentIndex = visibleTreeItems.indexOf(current);

      if (currentIndex > 0) {
        this.setFocusToTreeItem(visibleTreeItems[currentIndex - 1]);
      }
    }

    /**
     * Focuses the next visible treeitem relative to the current one.
     *
     * @param {HTMLElement} current - The currently focused treeitem.
     * @return {void} Does not return a value.
     */
    focusNextVisibleTreeItem(current) {
      const visibleTreeItems = this.getVisibleTreeItems();
      const currentIndex = visibleTreeItems.indexOf(current);

      if (currentIndex < visibleTreeItems.length - 1) {
        this.setFocusToTreeItem(visibleTreeItems[currentIndex + 1]);
      }
    }

    /**
     * Gets all currently visible treeitems (not hidden by collapsed parents).
     *
     * @return {HTMLElement[]} Array of visible treeitem elements.
     */
    getVisibleTreeItems() {
      return this.treeItems.filter((item) => {
        // Check if any ancestor is collapsed
        let parent = item.parentElement;
        while (parent && parent !== this.outlineElement) {
          const parentTreeItem = parent.closest('[role="treeitem"]');
          if (parentTreeItem && parentTreeItem.getAttribute('aria-expanded') === 'false') {
            return false;
          }
          parent = parent.parentElement;
        }
        return true;
      });
    }

    /**
     * Gets the expand/collapse button for a treeitem, if it exists.
     *
     * @param {HTMLElement} treeItem - The treeitem element.
     * @return {HTMLElement|null} The expand button or null if not found.
     */
    getExpandButton(treeItem) {
      return treeItem?.querySelector('.me-component-outline__component-toggle') ?? null;
    }

    /**
     * Gets the first child treeitem of a parent node.
     *
     * @param {HTMLElement} parentTreeItem - The parent treeitem.
     * @return {HTMLElement|null} The first child treeitem or null.
     */
    getFirstChildTreeItem(parentTreeItem) {
      const childGroup = parentTreeItem?.querySelector('[role="group"]');
      if (childGroup) {
        return childGroup.querySelector('[role="treeitem"]') ?? null;
      }
      return null;
    }

    /**
     * Gets the parent treeitem of a child node.
     *
     * @param {HTMLElement} childTreeItem - The child treeitem.
     * @return {HTMLElement|null} The parent treeitem or null.
     */
    getParentTreeItem(childTreeItem) {
      return childTreeItem?.parentElement?.closest('[role="treeitem"]');
    }

    /**
     * Handles type-ahead functionality for quick navigation.
     *
     * @param {string} key - The typed character.
     * @return {void} Does not return a value.
     */
    handleTypeAhead(key) {
      // Clear previous timeout
      if (this.typeAheadTimeout) {
        clearTimeout(this.typeAheadTimeout);
      }

      // Add character to search string
      this.typeAheadString += key.toLowerCase();

      // Find matching treeitem
      const visibleTreeItems = this.getVisibleTreeItems();
      const currentIndex = this.currentFocusedItem ?
        visibleTreeItems.indexOf(this.currentFocusedItem) : -1;

      // Search from current position + 1, then wrap around
      const searchItems = [
        ...visibleTreeItems.slice(currentIndex + 1),
        ...visibleTreeItems.slice(0, currentIndex + 1)
      ];

      const matchingItem = searchItems.find((item) => {
        const label = this.getTreeItemLabel(item);
        return label && label.toLowerCase().startsWith(this.typeAheadString);
      });

      if (matchingItem) {
        this.setFocusToTreeItem(matchingItem);
      }

      // Reset search string after delay
      this.typeAheadTimeout = setTimeout(() => {
        this.typeAheadString = '';
      }, 500);
    }

    /**
     * Gets the text label of a treeitem for type-ahead search.
     *
     * @param {HTMLElement} treeItem - The treeitem element.
     * @return {string} The text content of the treeitem label.
     */
    getTreeItemLabel(treeItem) {
      const label = treeItem?.querySelector('.me-component-outline__component-label');
      return label?.textContent?.trim() ?? '';
    }

    /**
     * Handles menu toggle button clicks to show/hide the menu dialog.
     *
     * @param {Event} event - The click event.
     * @return {void} Does not return a value.
     */
    onMenuToggleClick(event) {
      event.stopPropagation();
      const toggle = event.target;
      const dialog = toggle.nextElementSibling;

      if (this.openMenuDialog && this.openMenuDialog !== dialog) {
        this.closeMenu(this.openMenuDialog);
      }

      if (dialog.hasAttribute('open')) {
        this.closeMenu(dialog);
      } else {
        this.openMenu(toggle, dialog);
      }
    }

    /**
     * Handles keydown events on menu toggle buttons.
     *
     * @param {KeyboardEvent} event - The keydown event.
     * @return {void} Does not return a value.
     */
    onMenuToggleKeydown(event) {
      const toggle = event.target;
      const dialog = toggle.nextElementSibling;
      let preventDefault = false;

      switch (event.key) {
        case 'Enter':
        case ' ':
          preventDefault = true;
          if (!dialog.hasAttribute('open')) {
            this.openMenu(toggle, dialog);
            this.focusFirstMenuItem(dialog);
          }
          break;
        case 'Down':
        case 'ArrowDown':
          preventDefault = true;
          if (!dialog.hasAttribute('open')) {
            this.openMenu(toggle, dialog);
          }
          this.focusFirstMenuItem(dialog);
          break;
        case 'Right':
        case 'ArrowRight':
        case 'Left':
        case 'ArrowLeft':
          // Prevent left and Right arrows from exiting the menu.
          preventDefault = true;
          break;
        case 'Escape':
          if (dialog.hasAttribute('open')) {
            preventDefault = true;
            this.closeMenu(dialog);
            // Return focus to the associated treeitem
            const treeItem = toggle.closest('[role="treeitem"]');
            if (treeItem) {
              this.setFocusToTreeItem(treeItem);
            }
          }
          break;
      }

      if (preventDefault) {
        event.preventDefault();
        event.stopPropagation();
      }
    }

    /**
     * Opens a menu dialog.
     *
     * @param {HTMLElement} toggle - The toggle button.
     * @param {HTMLDialogElement} dialog - The dialog element.
     * @return {void} Does not return a value.
     */
    openMenu(toggle, dialog) {
      dialog.setAttribute('open', '');
      dialog.setAttribute('aria-hidden', 'false');
      toggle.setAttribute('aria-expanded', 'true');
      this.openMenuDialog = dialog;

      // Add menu item event listeners
      const menuItems = dialog.querySelectorAll('[role="menuitem"]');
      menuItems.forEach((item, index) => {
        item.tabIndex = index === 0 ? 0 : -1; // First item focusable, others not
        item.addEventListener('keydown', this.onMenuItemKeydown.bind(this));
      });
    }

    /**
     * Closes a menu dialog.
     *
     * @param {HTMLDialogElement} dialog - The dialog element.
     * @return {void} Does not return a value.
     */
    closeMenu(dialog) {
      const toggle = dialog.previousElementSibling;
      dialog.removeAttribute('open');
      dialog.setAttribute('aria-hidden', 'true');
      toggle.setAttribute('aria-expanded', 'false');
      this.openMenuDialog = null;

      // Remove menu item event listeners
      const menuItems = dialog.querySelectorAll('[role="menuitem"]');
      menuItems.forEach((item) => {
        item.removeEventListener('keydown', this.onMenuItemKeydown.bind(this));
      });
    }

    /**
     * Gets the menu toggle button associated with a dialog.
     * @param {HTMLDialogElement} dialog - The dialog element.
     * @return {HTMLElement} The toggle button associated with the dialog.
     */
    getMenuToggleByDialog(dialog) {
      return dialog.previousElementSibling;
    }

    /**
     * Gets the menu toggle button within a treeitem.
     * @param {HTMLElement} treeItem - The treeitem element.
     * @return {HTMLElement} The menu toggle button within the treeitem.
     */
    getMenuToggleByTreeItem(treeItem) {
      // Find the menu toggle button within the treeitem
      return treeItem.querySelector(':scope > .me-component-outline__component-controls .me-component-outline__component-menu-toggle');
    }

    /**
     * Gets the related add buttons for a given treeitem.
     * For layouts, these would be buttons within the regions.
     * For components, these would be buttons following the component itself.
     * @param {HTMLElement} treeItem - The treeitem element.
     * @return {HTMLElement[]} The related add buttons for the treeitem.
     */
    getAddButtonsByTreeItem(treeItem) {
      // Find all add buttons within the treeitem
      return Array.from(treeItem.querySelectorAll(
        ':scope > :is(.me-component-outline__component-controls, .me-component-outline__region-controls) > .me-component-outline__add-button'
      ));
    }

    /**
     * Handles keydown events on menu items for keyboard navigation.
     *
     * @param {KeyboardEvent} event - The keydown event.
     * @return {void} Does not return a value.
     */
    onMenuItemKeydown(event) {
      const menuItem = event.target;
      const dialog = menuItem.closest('.me-component-outline__component-menu-dialog');
      const menuItems = Array.from(dialog.querySelectorAll('[role="menuitem"]'));
      const currentIndex = menuItems.indexOf(menuItem);
      let preventDefault = false;

      switch (event.key) {
        case 'Down':
        case 'ArrowDown':
          const nextIndex = (currentIndex + 1) % menuItems.length;
          menuItems[currentIndex].tabIndex = -1;
          menuItems[nextIndex].tabIndex = 0;
          menuItems[nextIndex].focus();
          preventDefault = true;
          break;
        case 'Up':
        case 'ArrowUp':
          const prevIndex = (currentIndex - 1 + menuItems.length) % menuItems.length;
          menuItems[currentIndex].tabIndex = -1;
          menuItems[prevIndex].tabIndex = 0;
          menuItems[prevIndex].focus();
          preventDefault = true;
          break;
        case 'Escape':
          this.closeMenu(dialog);
          // Return focus to the associated treeitem
          const toggle = this.getMenuToggleByDialog(dialog);
          const treeItem = toggle.closest('[role="treeitem"]');
          if (treeItem) {
            this.setFocusToTreeItem(treeItem);
          }
          preventDefault = true;
          break;
        case 'Tab':
          // Allow tab to leave the component outline entirely
          this.closeMenu(dialog);
          break;
      }

      if (preventDefault) {
        event.preventDefault();
        event.stopPropagation();
      }
    }

    /**
     * Focuses the first menu item in a dialog.
     *
     * @param {HTMLDialogElement} dialog - The dialog element.
     * @return {void} Does not return a value.
     */
    focusFirstMenuItem(dialog) {
      const firstMenuItem = dialog.querySelector('[role="menuitem"]');
      if (firstMenuItem) {
        firstMenuItem.tabIndex = 0;
        firstMenuItem.focus();
      }
    }

    /**
     * Handles clicks outside of menus to close them.
     *
     * @param {Event} event - The click event.
     * @return {void} Does not return a value.
     */
    onDocumentClick(event) {
      if (this.openMenuDialog &&
          !this.openMenuDialog.contains(event.target) &&
          !this.getMenuToggleByDialog(this.openMenuDialog)?.contains(event.target)) {
        this.closeMenu(this.openMenuDialog);
      }
    }

    /**
     * Handles expand/collapse toggle button clicks.
     *
     * @param {Event} event - The click event.
     * @return {void} Does not return a value.
     */
    onExpandToggleClick(event) {
      event.stopPropagation();
      const toggleButton = event.target;
      const treeItem = toggleButton.closest('[role="treeitem"]');

      if (!treeItem) {
        return;
      }

      const isExpanded = treeItem.getAttribute('aria-expanded') === 'true';

      if (isExpanded) {
        this.collapseTreeItem(treeItem);
      } else {
        this.expandTreeItem(treeItem);
      }
    }

    /**
     * Expands a tree item and shows its children.
     *
     * @param {HTMLElement} treeItem - The treeitem to expand.
     * @return {void} Does not return a value.
     */
    expandTreeItem(treeItem) {
      treeItem.setAttribute('aria-expanded', 'true');

      // Find the associated group (children container)
      const group = this.getChildGroup(treeItem);
      if (group) {
        group.style.display = '';
        group.setAttribute('aria-hidden', 'false');
      }

      // Update the visible tree items cache
      this.updateTreeItemsCache();

      // Save expanded states to sessionStorage
      this.saveExpandedStates();
    }

    /**
     * Collapses a tree item and hides its children.
     *
     * @param {HTMLElement} treeItem - The treeitem to collapse.
     * @return {void} Does not return a value.
     */
    collapseTreeItem(treeItem) {
      treeItem.setAttribute('aria-expanded', 'false');

      // Find the associated group (children container)
      const group = this.getChildGroup(treeItem);
      if (group) {
        group.style.display = 'none';
        group.setAttribute('aria-hidden', 'true');
      }

      // Update the visible tree items cache
      this.updateTreeItemsCache();

      // Save expanded states to sessionStorage
      this.saveExpandedStates();
    }

    /**
     * Updates the tree items cache to reflect current DOM state.
     * Call this after DOM changes that might affect the tree structure.
     *
     * @return {void} Does not return a value.
     */
    updateTreeItemsCache() {
      this.treeItems = Array.from(this.outlineElement.querySelectorAll('[role="treeitem"]'));
    }

    /**
     * Gets the child group element for a parent treeitem.
     *
     * @param {HTMLElement} treeItem - The parent treeitem.
     * @return {HTMLElement|null} The child group element or null.
     */
    getChildGroup(treeItem) {
      // Method 1: The group is the next sibling element after the treeitem
      const nextSibling = treeItem.nextElementSibling;
      if (nextSibling?.matches('[role="group"]')) {
        return nextSibling;
      }

      // Method 2: Look for the group within the same list item
      const listItem = treeItem.closest('li');
      if (listItem) {
        const groupInListItem = listItem.querySelector('[role="group"]');
        if (groupInListItem) {
          return groupInListItem;
        }
      }

      // Method 3: Look for sibling list that should be treated as a group
      // This handles cases where nested <ul> elements represent the children
      const parentLi = treeItem.parentElement?.closest('li');
      if (parentLi) {
        const nestedList = parentLi.querySelector('ul:not(:first-child)');
        if (nestedList) {
          return nestedList;
        }
      }

      return null;
    }
  }

  ((Drupal, drupalSettings, once) => {
    Drupal.mercuryEditorBus.on('component-event:focus', (msg) => {
      MercuryComponentOutline.setHighlightedComponent(msg.payload.uuid);
    });
    Drupal.mercuryEditorBus.on('component-event:blur', (msg) => {
      MercuryComponentOutline.setHighlightedComponent(null);
    });
    Drupal.mercuryEditorBus.on('editor:updateState', () => {
      Drupal.ajax({
        url: Drupal.url(`mercury-editor/${drupalSettings.mercuryEditor.id}/component-outline?update=true`),
      }).execute();
    });

    Drupal.behaviors.mercuryEditorComponentOutline = {
      attach: function attach(context) {
        // Initialize the component outline navigation for each outline instance.
        const componentOutline = once('me-component-outline', '.me-component-outline__list', context);
        componentOutline.forEach((outline) => new MercuryComponentOutline(outline));

        // Attach click handlers to "Add component" buttons in the "no components"
        // message.
        once('me-no-components', '.me-component-outline__no-components button').forEach((button) => {
          button.addEventListener('click', () => {
            Drupal.mercuryEditorBus.post('component:insert', {
              meId: button.getAttribute('data-mercury-editor-id'),
              layoutId: button.getAttribute('data-layout-id'),
              parentUuid: '',
              region: '',
              siblingUuid: '',
              placement: '',
            });
          });
        });
      },
    };
  })(Drupal, drupalSettings, once);

})();
