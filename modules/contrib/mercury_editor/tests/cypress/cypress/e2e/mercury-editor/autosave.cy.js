describe('Mercury Editor autosave and optional saving tests', () => {
  before(() => {
    // Install the Mercury Editor test module.
    cy.drush('en mercury_editor_autosave_test');
    // Clear the cache.
    cy.drush('cr');
    // Give us a taller viewport to work with.
    cy.viewport(1200, 800);
  });

  beforeEach(() => {
    // Login as admin.
    cy.loginUserByUid(1);
  });

  after(() => {
    // Uninstall the test module.
    cy.drush('pmu -y mercury_editor_autosave_test');
    // Clear the cache.
    cy.drush('cr');
  });

  it('require manual saving when autosave is disabled', () => {
    // Create a new page.
    // Create a new page.
    cy.visit('/node/add/me_test_ct');

    cy.meAddComponent('me_test_text');
    cy.meSetCKEditor5Value('field_me_test_text', 'Example text');

    cy.meSaveComponent().then((component) => {
      // Verify that the autosave is disabled on form.
      cy.get(
        'mercury-dialog[id^=lpb-dialog-] .layout-paragraphs-component-form',
      ).should('have.class', 'me-autosave-form--disabled');

      cy.get('.layout-paragraphs-component-form').should(
        'have.class',
        'me-autosave-initialized',
      );

      // Update text to trigger unsaved changes notice.
      cy.meSetCKEditor5Value(
        'field_me_test_text',
        'Editing content in text field',
      );

      // Verify that the autosave notice is shown.
      cy.get(
        'mercury-dialog[id^=lpb-dialog-] .me-autosave-form--disabled .unsaved-changes-notice',
      ).should('contain', 'Unsaved changes');

      // Verify that the save button is displayed.
      cy.get(
        'mercury-dialog[id^=lpb-dialog-] .me-dialog__buttonpane .lpb-btn--save',
      ).should('contain', 'Apply Changes');

      // apply change by clicking the apply changes button
      cy.get(
        'mercury-dialog[id^=lpb-dialog-] .me-dialog__buttonpane .lpb-btn--save',
      ).click();

      // Click outside the form to close editor.
      cy.iframe('#me-preview').click(10, 10);
    });

    // Verify that the autosave notice is no longer shown.
    cy.get(
      'mercury-dialog[id^=lpb-dialog-] .me-autosave-form--disabled .unsaved-changes-notice',
    ).should('not.be.visible');

    // Confirm the content updated as expected.
    cy.iframe('#me-preview').should('not.contain', 'Example text');
  });

  it('shows warning when clicking out of form with unsaved changes', () => {
    // Create a new page.
    cy.visit('/node/add/me_test_ct');

    cy.meAddComponent('me_test_text');
    cy.get('.layout-paragraphs-component-form').should(
      'have.class',
      'me-autosave-initialized',
    );
    cy.meSetCKEditor5Value('field_me_test_text', 'First content in example.');
    cy.meSaveComponent({}).then((component) => {
      cy.meAddComponent('me_test_text', { after: component });
      cy.meSetCKEditor5Value('field_me_test_text', 'Example text');
      cy.meSaveComponent({}).then(() => {
        // Verify that the autosave is disabled on form.
        cy.get(
          'mercury-dialog[id^=lpb-dialog-] .layout-paragraphs-component-form',
        ).should('have.class', 'me-autosave-form--disabled');

        // Update text to trigger unsaved changes.
        cy.meSetCKEditor5Value(
          'field_me_test_text',
          'Unsaved changes in text field',
        );

        // Verify that the autosave notice is shown.
        cy.get(
          'mercury-dialog[id^=lpb-dialog-] .me-autosave-form--disabled .unsaved-changes-notice',
        ).should('contain', 'Unsaved changes');
      });
    });

    // Click on other component to trigger warning.
    cy.meFindComponent('First').then((component) => {
      cy.get(component).click();
    });

    // TODO: Finish test once notice alert has been implemented.
    // // Verify that a warning dialog appears.
    // cy.get('mercury-dialog[role="alertdialog"]')
    //   .should('be.visible')
    //   .should('contain', 'unsaved changes');

    // // Cancel the warning to stay on the form.
    // cy.get('mercury-dialog[role="alertdialog"] button')
    //   .contains('Cancel')
    //   .click();

    // // Verify the form is still open and has unsaved changes.
    // cy.get(
    //   'mercury-dialog[id^=lpb-dialog-] .me-autosave-form--disabled .unsaved-changes-notice',
    // ).should('contain', 'Apply Changes');
  });
});
