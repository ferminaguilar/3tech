describe('Create a new page with Mercury Editor', () => {
  it('Creates a new page', () => {
    // Clear the cache.
    cy.drush('cr');
    // Login as admin.
    cy.loginUserByUid(1);
    // Create a new page.
    cy.visit('/node/add/page');
    cy.get('#edit-title-0-value').type('Test page');
    cy.iframe('#me-preview').find('.lpb-btn--add').click();
    // Make sure all the components are available.
    cy.iframe('#me-preview')
      .get('.lpb-component-list')
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
