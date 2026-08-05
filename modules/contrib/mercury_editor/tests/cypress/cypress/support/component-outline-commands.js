/**
 * Open the component outline.
 */
Cypress.Commands.add('openComponentOutline', () => {
  // Check that the component outline button exists
  cy.get('#me-component-outline').should('be.visible').click();
  cy.get('.me-component-outline').should('be.visible');
});

/**
 * Close the component outline.
 */
Cypress.Commands.add('closeComponentOutline', () => {
  // Close the outline
  cy.get('#mercury-editor-component-outline')
    .shadow()
    .find('#closeButton')
    .click();
});

/**
 * Gets the component outline tree element.
 */
Cypress.Commands.add('getComponentOutline', () => {
  return cy.get('.me-component-outline__list[role="tree"]');
});

/**
 * Gets all tree items in the component outline.
 */
Cypress.Commands.add('getTreeItems', () => {
  return cy.get('.me-component-outline .is-mounted [role="treeitem"]');
});

/**
 * Gets a specific tree item by its label text.
 */
Cypress.Commands.add('getTreeItemByLabel', (labelText) => {
  return cy
    .get(
      '.me-component-outline__component-label, .me-component-outline__region-label',
    )
    .contains(labelText)
    .closest('[role="treeitem"]');
});

/**
 * Gets all visible tree items (not hidden by collapsed parents).
 */
Cypress.Commands.add('getVisibleTreeItems', () => {
  return cy.get('.me-component-outline [role="treeitem"]').filter(':visible');
});

/**
 * Checks if a tree item is expanded.
 */
Cypress.Commands.add('isTreeItemExpanded', (treeItem) => {
  return treeItem.should('have.attr', 'aria-expanded', 'true');
});

/**
 * Checks if a tree item is collapsed.
 */
Cypress.Commands.add('isTreeItemCollapsed', (treeItem) => {
  return treeItem.should('have.attr', 'aria-expanded', 'false');
});

/**
 * Expands a tree item by clicking its toggle button.
 */
Cypress.Commands.add('expandTreeItem', (treeItem) => {
  return treeItem.find('.me-component-outline__component-toggle').click();
});

/**
 * Collapses a tree item by clicking its toggle button.
 */
Cypress.Commands.add('collapseTreeItem', (treeItem) => {
  return treeItem.find('.me-component-outline__component-toggle').click();
});

/**
 * Opens the context menu for a tree item.
 */
Cypress.Commands.add('openTreeItemMenu', (treeItem) => {
  return treeItem.find('.me-component-outline__component-menu-toggle').click();
});

/**
 * Gets the menu items for an open context menu.
 */
Cypress.Commands.add('getMenuItems', () => {
  return cy.get(
    '.me-component-outline__component-menu-dialog[open] [role="menuitem"]',
  );
});
