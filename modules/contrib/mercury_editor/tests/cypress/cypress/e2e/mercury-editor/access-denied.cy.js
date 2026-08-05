describe('Mercury Editor access denied tests', () => {
  before(() => {
    // Install the Mercury Editor test module.
    cy.drush('en mercury_editor_access_test');
    // Clear the cache.
    cy.drush('cr');
    cy.viewport(1000, 800);
  });

  after(() => {
    cy.drush('pmu mercury_editor_access_test');
    cy.drush('cr');
  });

  it('should deny access to content for user without permissions', () => {
    // Login as admin.
    cy.loginUserByUid(1);

    // Create a new page.
    cy.visit('/node/add/me_test_ct');
    cy.get('#me-preview').its('0.contentDocument');
    cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');
    cy.get('#edit-title-0-value').clear();
    cy.get('#edit-title-0-value').type('Access Test Page');
    cy.meSavePage();

    // Save the current URL before exiting the editor.
    cy.url().then((savedUrl) => {
      cy.meExitEditor();

      // Logout admin.
      cy.visit('/user/logout');

      // Login as mercury_test_user (by username).
      cy.loginUserByUsername('mercury_test_user');

      // Clear cache and wait for login to complete.
      cy.drush('cr');
      cy.wait(3000);

      // Visit the saved URL as the test user.
      cy.visit(savedUrl, { failOnStatusCode: false });

      // Confirm access denied (403 page).
      cy.contains('Access denied').should('exist');
    });
  });

  it('should show 404 page without Mercury Editor', () => {
    // Visit a non-existent node.
    cy.visit('/mercury-editor/non-existing-page', { failOnStatusCode: false });

    // Confirm 404 page is displayed.
    cy.contains('Page not found').should('exist');

    // Confirm Mercury Editor iframe does not exist.
    cy.get('iframe[id="me-preview"]').should('not.exist');
  });
});
