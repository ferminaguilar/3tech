describe('Mercury Editor undo/redo state history limit', () => {
  before(() => {
    // Install the Mercury Editor test module.
    cy.drush('en mercury_editor_setup_test');
    // Keep a small history to verify limit behavior quickly.
    cy.drush('config:set', ['mercury_editor.settings', 'state_history_limit', 2]);
    // Clear the cache.
    cy.drush('cr');
    // Give us a taller viewport to work with.
    cy.viewport(1000, 800);
  });

  beforeEach(() => {
    // Login as admin.
    cy.loginUserByUid(1);
  });

  after(() => {
    // Restore module default.
    cy.drush('config:set', ['mercury_editor.settings', 'state_history_limit', 20]);
    cy.drush('cr');
  });

  it('keeps only the most recent states for undo/redo', () => {
    cy.visit('/node/add/me_test_ct');
    cy.wait(3500);

    cy.meAddComponent('me_test_text');
    cy.meSetCKEditor5Value('field_me_test_text', 'State one');
    cy.meSaveComponent().then((firstComponent) => {
      const componentUuid = firstComponent.attr('data-uuid');
      cy.wait(3500);
      cy.meSelectComponent(componentUuid);
      cy.meSetCKEditor5Value('field_me_test_text', 'State two');
      cy.meSaveComponent().then(() => {
        cy.wait(3500);
        cy.meSelectComponent(componentUuid);
        cy.meSetCKEditor5Value('field_me_test_text', 'State three');
        cy.meSaveComponent();
      });
    });

    cy.get('.me-button--undo').should('not.have.class', 'disabled');

    cy.iframe('#me-preview').contains('State one').should('not.exist');
    cy.iframe('#me-preview').contains('State two').should('not.exist');
    cy.iframe('#me-preview').contains('State three').should('exist');

    // Undo once should move from state three to state two.
    cy.get('.me-button--undo').click();
    cy.iframe('#me-preview').contains('State three').should('not.exist');
    cy.iframe('#me-preview').contains('State two').should('exist');
    cy.iframe('#me-preview').contains('State one').should('not.exist');

    // A second undo should be unavailable because state one was evicted.
    cy.get('.me-button--undo').should('have.class', 'disabled');
    cy.iframe('#me-preview').contains('State three').should('not.exist');
    cy.iframe('#me-preview').contains('State two').should('exist');
    cy.iframe('#me-preview').contains('State one').should('not.exist');

    // Redo should move back to state three.
    cy.get('.me-button--redo').click();
    cy.iframe('#me-preview').contains('State three').should('exist');
    cy.iframe('#me-preview').contains('State two').should('not.exist');
    cy.iframe('#me-preview').contains('State one').should('not.exist');
  });
});
