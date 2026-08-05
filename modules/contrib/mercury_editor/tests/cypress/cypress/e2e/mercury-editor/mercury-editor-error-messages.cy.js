describe('Mercury Editor validation and messages tests.', () => {
  before(() => {
    // Install the Mercury Editor test module.
    cy.drush('en mercury_editor_field_validation_test');
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
    // Uninstall the test module.
    cy.drush('pmu -y mercury_editor_field_validation_test');
    // Clear the cache.
    cy.drush('cr');
  });

  it('tests field validation when creating a new Mercury Editor component', () => {
    // Creates a new 2-column section and attempts to save it without entering a value for the "label" field.
    cy.visit('/node/add/me_test_ct');
    cy.meAddComponent('me_test_section');
    cy.meChooseLayout('layout_twocol');
    cy.meSaveComponent();
    cy.get(
      'mercury-dialog[id^=lpb-dialog-] .layout-paragraphs-component-form .form-element.error',
      { timeout: 10000 },
    )
      .should('be.visible')
      .and('have.class', 'error');
    cy.get(
      'mercury-dialog[id^=lpb-dialog-] .layout-paragraphs-component-form .me-form-error-messages__button',
    ).click();
    cy.get(
      'mercury-dialog[id^=lpb-dialog-] .layout-paragraphs-component-form .messages--error',
    )
      .should('be.visible')
      .and('contain', ' field is required.')
      // Validate stylized background color for error messages.
      .should('have.css', 'background-color', 'rgb(247, 217, 217)');
    // Uninstall the test module.
    cy.drush('cr');
  });

  // Add test for editing component with required field
  it('tests field validation when editing an existing Mercury Editor component', () => {
    // Creates a new 2-column section and attempts to save it without entering a value for the "label" field.
    cy.visit('/node/add/me_test_ct');
    cy.meAddComponent('me_test_section');
    cy.meChooseLayout('layout_twocol');

    cy.get('mercury-dialog[id^=lpb-dialog-] .layout-paragraphs-component-form')
      .contains('Content')
      .click();

    // Open the edit form for a component with content already filled in.
    cy.get('input[name="field_me_test_label[0][value]"]').type(
      'Example Section Label',
    );
    cy.meSaveComponent().then((section) => {
      // Ensure that autosave has initialized.
      cy.get('.layout-paragraphs-component-form').should(
        'have.class',
        'me-autosave-initialized',
      );
      // Click the Content tab.
      cy.get('.layout-paragraphs-component-form')
        .contains('Content')
        .click({ force: true });
      // Clear the required label field
      cy.get('input[name="field_me_test_label[0][value]"]').clear();
      // Trigger save action and confirm that validation error appears.
      cy.meSaveComponent();
    });

    cy.get(
      '.layout-paragraphs-component-form .me-form-error-messages__button',
    ).click();
    cy.get(
      '.layout-paragraphs-component-form .me-form-error-messages__wrapper .messages--error',
    )
      .should('be.visible')
      .and('contain', ' field is required.')
      // Validate stylized background color for error messages.
      .should('have.css', 'background-color', 'rgb(247, 217, 217)');
  });

  it('tests field validation with a Mercury Editor node form', () => {
    // Removed required title field text to trigger validation error.
    cy.visit('/node/add/me_test_ct');
    // Ensure that autosave has initialized.
    cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');
    // Clear the required title field.
    cy.get('input[name="title[0][value]"]').clear();
    // Confirm that validation error appears once error is triggered.
    cy.get('form.node-form .me-form-error-messages__button').should(
      'be.visible',
    );
    cy.get('form.node-form .me-form-error-messages__button').click();
    cy.get('form.node-form .me-form-error-messages__wrapper .messages--error')
      .should('be.visible')
      .and('contain', ' field is required.')
      // Validate stylized background color for error messages.
      .should('have.css', 'background-color', 'rgb(247, 217, 217)');
  });

  it('tests that if there is an error in the entity form but not the component form, the component form will be closed so a user can see the error', () => {
    // Removed required title field text to trigger validation error.
    cy.visit('/node/add/me_test_ct');
    cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');
    cy.get('input[name="title[0][value]"]').clear();
    cy.meAddComponent('me_test_section');
    cy.meChooseLayout('layout_twocol').then(() => {
      cy.get('.layout-paragraphs-component-form').should(
        'have.class',
        'me-autosave-initialized',
      );
      cy.get(
        'mercury-dialog[id^=lpb-dialog-] .layout-paragraphs-component-form',
      )
        .contains('Content')
        .click();
      // Open the edit form for a component with content already filled in.
      cy.get('input[name="field_me_test_label[0][value]"]').type(
        'Example Section Label',
      );
      cy.meSaveComponent();
    });
    cy.get('#me-save-btn').click();
    cy.get('.layout-paragraphs-component-form').should('not.exist');
    // Confirm that validation error appears once error is triggered.
    cy.get('.me-form-error-messages__button', {
      timeout: 10000,
    }).should('be.visible');
    cy.get('.me-form-error-messages__button').click();
    cy.get('form.node-form .me-form-error-messages__wrapper .messages--error')
      .should('be.visible')
      .and('contain', ' field is required.')
      // Validate stylized background color for error messages.
      .should('have.css', 'background-color', 'rgb(247, 217, 217)');
  });
});
