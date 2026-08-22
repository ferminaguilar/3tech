Cypress.config('defaultCommandTimeout', 10000);

describe('Mercury Editor card component tests.', () => {
  before(() => {
    cy.drush('en mercury_editor_components_test');
    cy.drush('cr');
    cy.viewport(1000, 800);
  });

  beforeEach(() => {
    cy.loginUserByUid(1);
  });

  after(() => {
    cy.drush('pmu -y mercury_editor_components_test');
    cy.drush('cr');
  });

  it('adds a card component with heading, image, and link', () => {
    cy.visit('/node/add/me_test_ct');
    cy.get('#me-preview').its('0.contentDocument');
    cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');
    cy.get('input[name="title[0][value]"]').clear();
    cy.get('input[name="title[0][value]"]').type('Card Test Page');

    cy.meAddComponent('me_test_section');
    cy.meChooseLayout('layout_twocol');
    cy.meSaveComponent().then((section) => {
      cy.meAddComponent('me_test_card', {
        region: 'first',
        section,
      });

      cy.get('input[name="field_me_test_title[0][value]"]').type(
        'Mercury Editor Card',
      );

      // Select example-1 from media library
      cy.get('.js-media-library-open-button').click();
      cy.get('.media-library-widget-modal .js-media-library-item')
        .contains('example-1')
        .parents('.js-media-library-item')
        .find('input[type="checkbox"]')
        .check({ force: true });
      // Wait for Insert selected button to be enabled
      cy.get('.media-library-widget-modal button')
        .contains('Insert selected')
        .should('not.be.disabled')
        .click();
      cy.get('.media-library-widget-modal').should('not.exist');

      // Wait for media to be inserted
      cy.get('.media-library-item__preview').should('be.visible');
      cy.get('.media-library-item__name').should('contain', 'example-1');

      // Add text content

      cy.meSetCKEditor5Value(
        'field_me_test_text',
        'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Aliquam tortor justo, facilisis id feugiat ac, condimentum ac enim. Etiam ac faucibus lacus, in sodales tellus. Pellentesque nec laoreet massa. Donec consequat mi augue, eget fermentum metus gravida vitae.',
      );
      cy.get('input[name="field_me_test_link[0][uri]"]').type(
        'https://www.drupal.org/project/mercury_editor',
      );
      cy.get('input[name="field_me_test_link[0][title]"]').type(
        'Learn More about Mercury Editor',
      );
      cy.iframe('#me-preview').find('.ajax-progress').should('not.exist');

      cy.meSaveComponent().then((component) => {
        const uuid = component.attr('data-uuid');
        const selector = `[data-uuid="${uuid}"]`;

        // Find and click the remove button to remove the image
        cy.get(
          '.layout-paragraphs-component-form [name="field_me_test_media-0-media-library-remove-button"]',
        ).click({ force: true });
        cy.get('.ajax-progress').should('not.exist');

        // Re-save the component to confirm removal
        cy.meSaveComponent();

        // Confirm that the image was removed
        cy.get('.media-library-item__preview').should('not.exist');

        // Re-add an image - example-2
        cy.get('.js-media-library-open-button').click();

        // Select example-2 from media library
        cy.get('.media-library-widget-modal .js-media-library-item')
          .contains('example-2')
          .parents('.js-media-library-item')
          .find('input[type="checkbox"]')
          .check({ force: true });

        // Wait for Insert selected button to be enabled
        cy.get('.media-library-widget-modal button')
          .contains('Insert selected')
          .should('not.be.disabled')
          .click();
        cy.get('.media-library-widget-modal').should('not.exist');

        // Wait for media to be inserted
        cy.get('.media-library-item__preview').should('be.visible');
        cy.get('.media-library-item__name').should('contain', 'example-2');

        cy.meSaveComponent();

        // Verify the media was changed to example-2
        cy.iframe('#me-preview')
          .find(selector)
          .find('img')
          .should('have.attr', 'src')
          .and('include', 'example-2');
      });
    });
  });
});
