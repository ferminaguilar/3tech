describe('Mercury Editor Templates e2e tests.', () => {
  before(() => {
    // Install the Mercury Editor Templates test module.
    cy.drush('en -y mercury_editor_templates_test');
    // Clear the cache.
    cy.drush('cr');
    // Give us a taller viewport to work with.
    cy.viewport(1000, 800);
  });

  after(() => {
    // Install the Mercury Editor Templates test module.
    cy.drush('pmu -y mercury_editor_templates_test');
    // Clear the cache.
    cy.drush('cr');
  });

  beforeEach(() => {
    // Login as admin.
    cy.loginUserByUid(1);
  });

  it('creates and inserts templates in several contexts', () => {
    // Create a new page.
    cy.visit('/node/add/me_test_ct');
    cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');
    // Add a two-column section with a text component in each column and save
    // it as a new templates.
    cy.meAddComponent('me_test_section');
    cy.meChooseLayout('layout_twocol');
    /* eslint-disable max-nested-callbacks */
    cy.meSaveComponent().then((section) => {
      cy.meAddComponent('me_test_text', {
        region: 'first',
        section,
      });
      cy.meSetCKEditor5Value('field_me_test_text', 'Left');
      cy.meSaveComponent().then((component) => {
        cy.wrap(component).should('contain', 'Left');
      });

      cy.meSelectComponent(section.attr('data-uuid'));
      cy.meAddComponent('me_test_text', {
        region: 'second',
        section,
      });
      cy.meSetCKEditor5Value('field_me_test_text', 'Right');
      cy.meSaveComponent().then((component) => {
        cy.wrap(component).should('contain', 'Right');
      });
    });

    cy.meFindComponent(1).then((component) => {
      // Click on component to focus it and reveal controls
      cy.get(component).click();
      cy.get(component).find('.lpb-save-as-template').click({ force: true });

      // Name and save the new template in dialog with updated class
      cy.get(
        'mercury-dialog[id^=lpb-dialog-] .me-template-dialog-form input.form-text',
      ).type('-- Mercury Editor Templates Test --');
      cy.get(
        'mercury-dialog[id^=lpb-dialog-] [slot=footer] .lpb-btn--save',
      ).click();

      // Delete the section using the new click-based approach
      cy.meFindComponent(1).then((section) => {
        cy.meDeleteComponent(section);
      });
    });

    // Add a new 3-column section.
    cy.meAddComponent('me_test_section');
    cy.meChooseLayout('layout_threecol_25_50_25');
    cy.meSaveComponent().then((section) => {
      // Insert the template AFTER the first section.
      cy.get(section).find('> .lpb-btn--add.after').click();
      cy.get('a')
        .contains('-- Mercury Editor Templates Test --')
        .first()
        .click();
      cy.get('#me-preview')
        .its('0.contentDocument')
        .then((document) => {
          // Asserts that the template was inserted in the correct order.
          cy.get(document)
            .find('[data-layout]')
            .eq(0)
            .should('have.attr', 'data-layout', 'layout_threecol_25_50_25');
          cy.get(document)
            .find('[data-layout]')
            .eq(1)
            .should('have.attr', 'data-layout', 'layout_twocol');
        });
      cy.get('#me-preview')
        .its('0.contentDocument')
        .then((document) => {
          // Insert the template into the first region of the 3-column section.
          cy.get(document)
            .find(
              '[data-layout="layout_threecol_25_50_25"] .layout__region--first .lpb-btn--add',
            )
            .click({ force: true });
          cy.get('a')
            .contains('-- Mercury Editor Templates Test --')
            .first()
            .click();
          cy.get(document)
            .find('[data-layout="layout_threecol_25_50_25"]')
            .find('.layout__region--first')
            .find('[data-layout="layout_twocol"]')
            .should('exist');
        })
        .then(() => {
          // Insert the template BEFORE the first section.
          cy.get('#me-preview')
            .its('0.contentWindow')
            .should('exist')
            .then((win) => {
              win.scrollTo(0, 0);
            });
          // Close edit tray to expand main window. Otherwise the viewport is too
          // narrow and the "before" button is not visible.
          cy.get('#me-sidebar-toggle-btn').click();
          cy.meFindComponent(1)
            .find('> .lpb-btn--add.before')
            .click({ force: true });
          cy.get('a')
            .contains('-- Mercury Editor Templates Test --')
            .first()
            .click();
          cy.get('#me-preview')
            .its('0.contentDocument')
            .then((document) => {
              // Asserts that the template was inserted in the correct order.
              cy.get(document)
                .find('[data-layout]')
                .eq(0)
                .should('have.attr', 'data-layout', 'layout_twocol');
              cy.get(document)
                .find('[data-layout]')
                .eq(1)
                .should('have.attr', 'data-layout', 'layout_threecol_25_50_25');
            })
            .then(() => {
              // Tests the following use case:
              // 1. Save a page.
              // 2. Delete all sections from the page.
              // 3. Add a new section.
              // 4. Add a template into the first region of the new section.
              // 5. Save the page.
              // @see https://www.drupal.org/project/mercury_editor/issues/3398498#comment-15340845
              cy.meSavePage();

              // Delete the first section.
              cy.meFindComponent(1).then((component) => {
                cy.meDeleteComponent(component);

                // Delete the second section.
                cy.meFindComponent(1).then((secondComponent) => {
                  cy.meDeleteComponent(secondComponent);
                });
              });

              // Delete the third section.
              cy.meFindComponent(1).then((component) => {
                cy.meDeleteComponent(component);
              });

              // Add a new two-column section.
              cy.meAddComponent('me_test_section');
              cy.meChooseLayout('layout_twocol');
              cy.meSaveComponent().then(() => {
                // Insert a template into the first column.
                cy.meFindComponent(1)
                  .find('.layout__region--first .lpb-btn--add')
                  .click();
                cy.get('a')
                  .contains('-- Mercury Editor Templates Test --')
                  .first()
                  .click();
                cy.get('#me-preview')
                  .its('0.contentDocument')
                  .then(() => {
                    cy.meSavePage();
                    cy.meFindComponent(1)
                      .find('.layout__region--first')
                      .should('contain', 'Left');
                    cy.meFindComponent(1)
                      .find('.layout__region--first')
                      .should('contain', 'Right');
                  });
              });
            });
        });
    });
  });

  it('creates, edits, and deletes a template with Mercury Editor', () => {
    // Create a new term.
    cy.visit('me-template/add');
    cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');
    // Tests that syncing the title field works.
    cy.get('input[name="label[0][value]"]').clear();
    cy.get('input[name="label[0][value]"]').type(
      '-- Mercury Editor Templates Test --',
    );
    cy.iframe('#me-preview')
      .find('.page-title')
      .contains('-- Mercury Editor Templates Test --');

    cy.basicMercuryEditorInteractions();
    cy.meDeletePage();
  });
});
