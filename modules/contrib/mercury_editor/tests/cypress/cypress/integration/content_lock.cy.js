describe('Tests Mercury Editor and Content Lock', () => {
  it('Enables the test module', () => {
    // Clear the cache.
    cy.drush('en -y mercury_editor_content_lock_test');
    // Login as admin.
    cy.loginByUsername('mercury_editor_content_lock_test_user_1');
    // Create a new page.
    cy.visit('/node/add/page');
    cy.get('#edit-title-0-value').type('Test page');
    cy.iframe('#me-preview').find('.lpb-btn--add').click();
    // Make sure all the components are available.
    cy.iframe('#me-preview').get('.lpb-component-list')
      .should('contain', 'Layout')
      .and('contain', 'Section')
      .and('contain', 'Default')
      .and('contain', 'Text')
      .and('contain', 'Image')
      .and('contain', 'Image Gallery')
      .and('contain', 'Video')
      .and('contain', 'Card');
    cy.get('a').contains('Section').click();

  });
});
