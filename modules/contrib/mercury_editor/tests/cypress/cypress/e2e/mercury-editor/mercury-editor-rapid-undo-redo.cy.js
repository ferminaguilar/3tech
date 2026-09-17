describe('Mercury Editor rapid undo/redo', () => {
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

  it('does not throw a tempstore lock error when undo is clicked repeatedly without waiting', () => {
    const alertStub = cy.stub();
    cy.on('window:alert', alertStub);

    // Create a new page.
    cy.visit('/node/add/me_test_ct');
    cy.wait(3000);

    cy.meAddComponent('me_test_text');
    cy.meSetCKEditor5Value('field_me_test_text', 'First edit');
    cy.meSaveComponent().then((component) => {
      cy.wait(3000); // Undo/redo states are throttled.
      cy.meAddComponent('me_test_text', { after: component });
      cy.meSetCKEditor5Value('field_me_test_text', 'Second edit');
      cy.meSaveComponent().then((component2) => {
        cy.wait(3000);
        cy.meAddComponent('me_test_text', { after: component2 });
        cy.meSetCKEditor5Value('field_me_test_text', 'Third edit');
        cy.meSaveComponent().then((component3) => {
          cy.wait(3000);
          cy.meAddComponent('me_test_text', { after: component3 });
          cy.meSetCKEditor5Value('field_me_test_text', 'Fourth edit');
          cy.meSaveComponent().then((component4) => {
            cy.wait(3000);
            cy.meAddComponent('me_test_text', { after: component4 });
            cy.meSetCKEditor5Value('field_me_test_text', 'Fifth edit');
            cy.meSaveComponent();
          });
        });
      });
    });

    cy.iframe('#me-preview').should('contain', 'Fifth edit');

    // Click undo repeatedly, back to back, without waiting for each ajax
    // round trip to finish. Overlapping requests used to race for the same
    // tempstore lock and surface a TempStoreException.
    cy.get('.me-button--undo').click();
    cy.get('.me-button--undo').click();
    cy.get('.me-button--undo').click();
    cy.get('.me-button--undo').click();
    cy.get('.me-button--undo').click();

    // The editor should still be usable and no ajax error should have fired.
    cy.get('.me-entity-form', { timeout: 10000 }).should('exist');
    cy.location('pathname').should('include', '/mercury-editor/');
    cy.wrap(alertStub).should('not.have.been.called');
  });
});
