/**
 * @file
 * Icon Select Component JavaScript
 *
 * Implements WCAG Menu Button pattern with native dialog element.
 * Provides keyboard navigation and accessibility support.
 */

class MercuryIconSelect {
  /**
   * Creates a new MercuryIconSelect instance
   * @param {Element} element - The root element of the icon select component
   */
  constructor(element) {
    this.element = element;
    this.button = element.querySelector('.me-component-icon-select__button');
    this.dialog = element.querySelector('.me-component-icon-select__dialog');
    this.hiddenInput = element.querySelector('input[type="hidden"]');
    this.closeButton = element.querySelector('.me-component-icon-select__dialog-close');
    this.grid = element.querySelector('.me-component-icon-select__grid');
    this.gridItems = Array.from(this.grid.querySelectorAll('.me-component-icon-select__grid-item'));

    this.focusedIndex = -1;
    this.isOpen = false;

    this.init();
  }

  /**
   * Initialize the component by binding event listeners and setting up initial state
   */
  init() {
    // Bind event listeners
    this.button.addEventListener('click', this.handleButtonClick.bind(this));
    this.button.addEventListener('keydown', this.handleButtonKeydown.bind(this));

    this.closeButton.addEventListener('click', this.close.bind(this));

    this.dialog.addEventListener('click', this.handleDialogClick.bind(this));
    this.dialog.addEventListener('keydown', this.handleDialogKeydown.bind(this));

    // Bind grid item events
    this.gridItems.forEach((item, index) => {
      item.addEventListener('click', this.handleItemClick.bind(this));
      item.addEventListener('keydown', this.handleItemKeydown.bind(this));
      item.addEventListener('focus', () => {
        this.focusedIndex = index;
      });
    });

    // Close on click outside
    document.addEventListener('click', this.handleDocumentClick.bind(this));

    // Initialize selected state
    this.updateSelectedState();
  }

  /**
   * Handle button click to open/close menu
   * @param {Event} event - The click event
   */
  handleButtonClick(event) {
    event.preventDefault();
    event.stopPropagation();

    if (this.isOpen) {
      this.close();
    } else {
      this.open();
    }
  }

  /**
   * Handle keyboard events on the menu button
   * @param {KeyboardEvent} event - The keyboard event
   */
  handleButtonKeydown(event) {
    switch (event.key) {
      case 'Enter':
      case ' ':
        event.preventDefault();
        this.open();
        this.focusFirstItem();
        break;
      case 'ArrowDown':
      case 'Down':
        event.preventDefault();
        this.open();
        this.focusFirstItem();
        break;
      case 'ArrowUp':
      case 'Up':
        event.preventDefault();
        this.open();
        this.focusLastItem();
        break;
    }
  }

  /**
   * Handle clicks within the dialog
   * @param {Event} event - The click event
   */
  handleDialogClick(event) {
    // Prevent dialog from closing when clicking inside
    event.stopPropagation();
  }

  /**
   * Handle keyboard events within the dialog
   * @param {KeyboardEvent} event - The keyboard event
   */
  handleDialogKeydown(event) {
    if (!this.isOpen) return;

    switch (event.key) {
      case 'Escape':
        event.preventDefault();
        this.close();
        this.button.focus();
        break;
      case 'Tab':
        // Allow tab to close the dialog and continue normal tab flow
        this.close();
        break;
      case 'ArrowRight':
      case 'Right':
        event.preventDefault();
        this.moveFocus('next');
        break;
      case 'ArrowLeft':
      case 'Left':
        event.preventDefault();
        this.moveFocus('prev');
        break;
      case 'ArrowDown':
      case 'Down':
        event.preventDefault();
        this.moveFocus('down');
        break;
      case 'ArrowUp':
      case 'Up':
        event.preventDefault();
        this.moveFocus('up');
        break;
      case 'Home':
        event.preventDefault();
        this.focusFirstItem();
        break;
      case 'End':
        event.preventDefault();
        this.focusLastItem();
        break;
    }
  }

  /**
   * Handle clicks on grid items
   * @param {Event} event - The click event
   */
  handleItemClick(event) {
    const value = event.currentTarget.getAttribute('data-value');
    this.selectValue(value);
    this.close();
    this.button.focus();
  }

  /**
   * Handle keyboard events on grid items
   * @param {KeyboardEvent} event - The keyboard event
   */
  handleItemKeydown(event) {
    switch (event.key) {
      case 'Enter':
      case ' ':
        event.preventDefault();
        const value = event.currentTarget.getAttribute('data-value');
        this.selectValue(value);
        this.close();
        this.button.focus();
        break;
    }
  }

  /**
   * Handle clicks outside the component to close dialog
   * @param {Event} event - The click event
   */
  handleDocumentClick(event) {
    if (this.isOpen && !this.element.contains(event.target)) {
      this.close();
    }
  }

  /**
   * Open the dialog and set focus to appropriate item
   */
  open() {
    if (this.button.disabled) return;

    this.dialog.show();
    this.isOpen = true;
    this.button.setAttribute('aria-expanded', 'true');

    // Find currently selected item and set initial focus
    const selectedItem = this.gridItems.find(item =>
      item.getAttribute('data-value') === this.hiddenInput.value
    );

    if (selectedItem) {
      this.focusedIndex = this.gridItems.indexOf(selectedItem);
      selectedItem.focus();
    } else {
      this.focusFirstItem();
    }
  }

  /**
   * Close the dialog and reset focus state
   */
  close() {
    this.dialog.close();
    this.isOpen = false;
    this.button.setAttribute('aria-expanded', 'false');
    this.focusedIndex = -1;
  }

  /**
   * Select a value and update the input
   * @param {string} value - The value to select
   */
  selectValue(value) {
    this.hiddenInput.value = value;
    this.updateSelectedState();

    // Trigger change event
    const changeEvent = new Event('change', { bubbles: true });
    this.hiddenInput.dispatchEvent(changeEvent);

    // Also trigger input event for broader compatibility
    const inputEvent = new Event('input', { bubbles: true });
    this.hiddenInput.dispatchEvent(inputEvent);
  }

  /**
   * Update visual state based on selected value
   */
  updateSelectedState() {
    const selectedValue = this.hiddenInput.value;

    // Update button icon
    const buttonIcon = this.button.querySelector('.me-component-icon-select__button-icon');
    buttonIcon.setAttribute('data-me-icon', selectedValue);

    // Update button label
    const buttonLabel = this.button.querySelector('.me-component-icon-select__button-label');
    const selectedOption = this.gridItems.find(item =>
      item.getAttribute('data-value') === selectedValue
    );

    if (selectedOption) {
      buttonLabel.textContent = selectedOption.getAttribute('aria-label');
    }

    // Update grid item selection states
    this.gridItems.forEach(item => {
      const isSelected = item.getAttribute('data-value') === selectedValue;
      item.classList.toggle('is-selected', isSelected);
    });
  }

  /**
   * Move focus to the first grid item
   */
  focusFirstItem() {
    if (this.gridItems.length > 0) {
      this.focusedIndex = 0;
      this.gridItems[0].focus();
    }
  }

  /**
   * Move focus to the last grid item
   */
  focusLastItem() {
    if (this.gridItems.length > 0) {
      this.focusedIndex = this.gridItems.length - 1;
      this.gridItems[this.focusedIndex].focus();
    }
  }

  /**
   * Move focus in the grid based on direction
   * @param {string} direction - The direction to move focus ('next', 'prev', 'up', 'down')
   */
  moveFocus(direction) {
    if (this.gridItems.length === 0) return;

    const columnsPerRow = 8; // Based on CSS grid
    const currentRow = Math.floor(this.focusedIndex / columnsPerRow);
    const currentCol = this.focusedIndex % columnsPerRow;
    let newIndex = this.focusedIndex;

    switch (direction) {
      case 'next':
        newIndex = (this.focusedIndex + 1) % this.gridItems.length;
        break;
      case 'prev':
        newIndex = (this.focusedIndex - 1 + this.gridItems.length) % this.gridItems.length;
        break;
      case 'down':
        const nextRowIndex = (currentRow + 1) * columnsPerRow + currentCol;
        newIndex = nextRowIndex < this.gridItems.length ? nextRowIndex : this.focusedIndex;
        break;
      case 'up':
        if (currentRow > 0) {
          newIndex = (currentRow - 1) * columnsPerRow + currentCol;
        }
        break;
    }

    if (newIndex !== this.focusedIndex && newIndex >= 0 && newIndex < this.gridItems.length) {
      this.focusedIndex = newIndex;
      this.gridItems[newIndex].focus();
    }
  }
}

// Initialize all icon select components
((Drupal, once) => {
  Drupal.behaviors.mercuryEditorIconSelect = {
    attach: function attach(context) {
      const iconSelects = once('me-component-icon-select', '.me-component-icon-select', context);
      iconSelects.forEach((element) => {
        new MercuryIconSelect(element);
      });
    },
  };
})(Drupal, once);
