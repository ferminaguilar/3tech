describe('Mercury Editor Content Translations e2e tests.', () => {
  beforeEach(() => {
    cy.loginUserByUid(1);
  });

  before(() => {
    // Install the Mercury Editor test module.
    cy.drush('en mercury_editor_translations_test');
    // Clear the cache.
    cy.drush('cr');
    // Give us a taller viewport to work with.
    cy.viewport(1000, 800);
  });

  after(() => {
    cy.drush('pmu mercury_editor_translations_test');
    cy.drush('cr');
  });
  /* eslint-disable max-nested-callbacks */
  it('tests asymmetric translations with Mercury Editor', () => {
    cy.drush('mercury_editor_translations_test:asym-setup');

    cy.visit('/node/add/me_test_ct');
    cy.meAddComponent('me_test_section');
    cy.meChooseLayout('layout_twocol');
    cy.meSaveComponent().then((section) => {
      cy.meAddComponent('me_test_text', {
        region: 'first',
        section,
      });
      cy.meSetCKEditor5Value('field_me_test_text', 'Left (EN)');
      cy.meSaveComponent().then((component) => {
        cy.iframe('#me-preview').find(component).should('contain', 'Left (EN)');
      });

      cy.meSelectComponent(section.attr('data-uuid'));
      cy.meAddComponent('me_test_text', {
        region: 'second',
        section,
      });
      cy.meSetCKEditor5Value('field_me_test_text', 'Right (EN)');
      cy.meSaveComponent().then((component) => {
        cy.iframe('#me-preview')
          .find(component)
          .should('contain', 'Right (EN)');
      });
    });
    cy.meSavePage();
    cy.meExitEditor();

    cy.url().then((url) => {
      const nid = url.match(/node\/(\d+)/)[1];
      cy.visit(`/es/node/${nid}/translations/add/en/es`);
    });

    cy.meFindComponent(2).then(() => {
      cy.meSetCKEditor5Value('field_me_test_text', 'Left (ES)');
    });
    cy.meSaveComponent().then((newComponent) => {
      cy.iframe('#me-preview')
        .find(newComponent)
        .should('contain', 'Left (ES)');
    });
    cy.meFindComponent(3).then(() => {
      cy.meSetCKEditor5Value('field_me_test_text', 'Right (ES)');
    });
    cy.meSaveComponent().then((newComponent) => {
      cy.iframe('#me-preview')
        .find(newComponent)
        .should('contain', 'Right (ES)');
    });
    cy.meFindComponent(1).then((component) => {
      cy.meAddComponent('me_test_text', {
        region: 'bottom',
        section: component,
      });
      cy.meSetCKEditor5Value('field_me_test_text', 'Footer Component (ES)');
      cy.meSaveComponent().then((newComponent) => {
        cy.iframe('#me-preview')
          .find(newComponent)
          .should('contain', 'Footer Component (ES)');
      });
    });

    cy.meSavePage();
    cy.meExitEditor();

    cy.url().then((url) => {
      const nid = url.match(/node\/(\d+)/)[1];
      cy.visit(`/de/node/${nid}/translations/add/en/de`);
    });

    cy.meFindComponent(1).then((component) => {
      cy.meDeleteComponent(component);
      cy.meAddComponent('me_test_text');
      cy.meSetCKEditor5Value('field_me_test_text', 'Content Component (DE)');
      cy.meSaveComponent();
    });
    cy.meSavePage();
    cy.meExitEditor();

    cy.url().then((url) => {
      const nid = url.match(/node\/(\d+)/)[1];

      cy.visit(`/node/${nid}`);
      cy.get('html').should('contain', 'Left (EN)');
      cy.get('html').should('contain', 'Right (EN)');
      cy.get('html').should('contain', 'Paragraph Language: en');

      cy.visit(`/es/node/${nid}`);
      cy.get('html').should('contain', 'Left (ES)');
      cy.get('html').should('contain', 'Right (ES)');
      cy.get('html').should('contain', 'Footer Component (ES)');
      cy.get('html').should('contain', 'Paragraph Language: es');

      cy.visit(`/de/node/${nid}`);
      cy.get('html').should('contain', 'Content Component (DE)');
      cy.get('html').should('contain', 'Paragraph Language: de');

      cy.visit(`/node/${nid}/delete`);
      cy.get('form.confirmation #edit-submit').click();
    });
  });

  it('tests exit redirection with translations and unpublished draft', () => {
    cy.drush('en mercury_editor_content_moderation_test');
    cy.visit('/node/add/me_test_ct');
    cy.get('[name="moderation_state[0][state]"]').select('Published');
    cy.meSavePage();
    cy.meExitEditor();
    cy.url().then((url) => {
      const nid = url.match(/node\/(\d+)/)[1];
      cy.visit(`/es/node/${nid}/edit`);
    });
    cy.get('[name="moderation_state[0][state]"]').select('draft');
    cy.meSavePage();
    cy.meExitEditor();
    cy.contains('New Mercury Editor Test Content Type').should('exist');
  });

  it('tests symmetric translations with Mercury Editor', () => {
    cy.drush('mercury_editor_translations_test:sym-setup');

    cy.visit('/node/add/me_test_ct');
    cy.meAddComponent('me_test_text');
    cy.meSetCKEditor5Value('field_me_test_text', 'Content (EN)');
    cy.meSaveComponent();
    cy.meSavePage();
    cy.meExitEditor();

    cy.url().then((url) => {
      const nid = url.match(/node\/(\d+)/)[1];
      cy.visit(`/node/${nid}/translations`);
    });
    cy.get('a[hreflang="es"]').click();

    cy.meFindComponent(1).then(() => {
      cy.meSetCKEditor5Value('field_me_test_text', 'Content (ES)').then(() => {
        cy.meSavePage();
        cy.meExitEditor();
      });
    });

    cy.url().then((url) => {
      const nid = url.match(/node\/(\d+)/)[1];
      cy.visit(`/node/${nid}`);
      cy.get('html').should('contain', 'Content (EN)');
      cy.get('div.me-test-paragraph-id')
        .invoke('text')
        .then((englishId) => {
          cy.visit(`/es/node/${nid}`);
          cy.get('div.me-test-paragraph-id')
            .invoke('text')
            .then((spanishId) => {
              expect(spanishId).to.equal(englishId);
              cy.get('html').should('contain', 'Content (ES)');
            });
        });
    });
  });
});
