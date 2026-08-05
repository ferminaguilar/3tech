Cypress.config('defaultCommandTimeout', 10000); // or more

describe('Mercury Editor e2e tests.', () => {
  before(() => {
    // Install the Mercury Editor test module.
    cy.drush('en mercury_editor_setup_test');
    // Clear the cache.
    cy.drush('cr');
    // Give us a taller viewport to work with.
    cy.viewport(1000, 800);
  });

  beforeEach(() => {
    // Login as admin.
    cy.loginUserByUid(1);
  });

  it('creates, edits, and deletes a taxonomy term with Mercury Editor', () => {
    // Enable the mercury_editor_content_moderation_test module.
    cy.drush('en mercury_editor_taxonomy_term_test');

    // Create a new term.
    cy.visit('admin/structure/taxonomy/manage/me_test_vocab/add');
    cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');
    // Tests that syncing the title field works.
    cy.get('input[name="name[0][value]"]').clear();
    cy.get('input[name="name[0][value]"]').type('-- Test term --');
    cy.iframe('#me-preview').find('.page-title').contains(' -- Test term --');

    cy.basicMercuryEditorInteractions();
    cy.meDeletePage();
  });
});
