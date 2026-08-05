/**
 * Asserts that an element has the correct component icon set via CSS variable.
 * Only checks the filename, not the full path, to work across different environments.
 *
 * @param {string} filename - The icon filename to check for (e.g., 'component-icon--text.svg')
 *
 * @example
 * cy.get('@textIconSelect').find('.me-component-icon-select__button-icon')
 *   .shouldHaveComponentIcon('component-icon--component.svg');
 */
Cypress.Commands.add('shouldHaveComponentIcon', { prevSubject: true }, (subject, filename) => {
  cy.wrap(subject).should($el => {
    // Escape special regex characters in filename
    const escapedFilename = filename.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    // Check that the CSS variable ends with the filename (before the closing parenthesis)
    const pattern = new RegExp(escapedFilename + '\\)$');
    expect(getComputedStyle($el[0]).getPropertyValue('--me-component-icon-image'), `Expected icon image to end with ${filename}`).to.match(pattern);
  });
});
