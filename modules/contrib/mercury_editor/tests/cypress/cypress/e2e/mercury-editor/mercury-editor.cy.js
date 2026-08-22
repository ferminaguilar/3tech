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

  it('creates, edits, and deletes a node with Mercury Editor', () => {
    // Create a new page.
    cy.visit('/node/add/me_test_ct');
    // Wait for the Mercury Editor interface to fully load
    cy.get('#me-preview').its('0.contentDocument');
    cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');
    // Tests that syncing the title field works.
    cy.get('input[name="title[0][value]"]').clear();
    cy.get('input[name="title[0][value]"]').type('-- Test page --');
    cy.iframe('#me-preview').find('.page-title').contains(' -- Test page --');
    cy.basicMercuryEditorInteractions();
    cy.meDeletePage();
  });

  it('tests undo and redo functionality in Mercury Editor', () => {
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
        cy.meSaveComponent();
      });
    });

    cy.iframe('#me-preview').should('contain', 'Third edit');

    cy.log('first undo');
    cy.get('.me-button--undo').click();
    cy.iframe('#me-preview').contains('First edit').should('exist');
    cy.iframe('#me-preview').should('contain', 'Second edit');
    cy.iframe('#me-preview').contains('Third edit').should('not.exist');

    cy.get('.me-button--undo').click();
    cy.iframe('#me-preview').contains('First edit').should('exist');
    cy.iframe('#me-preview').contains('Second edit').should('not.exist');

    cy.get('.me-button--redo').should('not.have.class', 'disabled');
    cy.get('.me-button--redo').click();
    cy.iframe('#me-preview').contains('Second edit').should('exist');

    cy.meFindComponent('Second edit').then((component) => {
      cy.wait(3000);
      cy.meAddComponent('me_test_text', { after: component });
    });
    cy.meSetCKEditor5Value('field_me_test_text', 'Final edit');
    cy.meSaveComponent();

    cy.iframe('#me-preview').should('contain', 'Final edit');
    cy.get('.me-button--undo').click();
    cy.iframe('#me-preview').contains('Final edit').should('not.exist');
    cy.iframe('#me-preview').contains('First edit').should('exist');

    cy.get('.me-button--undo').click();
    cy.iframe('#me-preview').contains('Second edit').should('not.exist');
    cy.iframe('#me-preview').contains('First edit').should('exist');
  });

  it('creates multiple revisions of a node with Mercury Editor', () => {
    let nid;

    // Enable the mercury_editor_content_moderation_test module.
    cy.drush('en mercury_editor_content_moderation_test');
    // Create a new page.
    cy.visit('/node/add/me_test_ct');
    cy.get('[name="revision_log[0][value]"]').type('First draft.');
    cy.meSavePage();
    cy.meExitEditor();
    cy.location('pathname').then((pathname) => {
      const match = pathname.match(/\/node\/(\d+)/);
      expect(match, 'node nid is present in URL').to.not.be.null;
      nid = match[1];
    });

    cy.then(() => cy.visit(`/node/${nid}/revisions`));
    cy.get('.revision-current').contains('First draft.');
    cy.then(() => cy.visit(`/node/${nid}`));
    cy.meEditPage();
    cy.meExitEditor();
    cy.meEditPage();
    cy.get('[name="moderation_state[0][state]"]').select('Published');
    cy.get('[name="revision_log[0][value]"]').type('Published draft.');
    cy.meSavePage();
    cy.meExitEditor();
    cy.then(() => cy.visit(`/node/${nid}/revisions`));
    cy.get('.revision-current').contains('Published draft.');

    cy.then(() => cy.visit(`/node/${nid}`));
    cy.meEditPage();
    cy.get('[name="moderation_state[0][state]"]').select('Draft');
    cy.get('[name="revision_log[0][value]"]').type('New unpublished draft.');
    cy.meSavePage();
    cy.meExitEditor();
    cy.then(() => cy.visit(`/node/${nid}/revisions`));
    cy.get('.node-revision-table tr:first-child').contains(
      'New unpublished draft.',
    );

    cy.then(() => cy.visit(`/node/${nid}`));
    cy.meEditPage();
    cy.get('[name="moderation_state[0][state]"]').select('Published');
    cy.get('[name="revision_log[0][value]"]').type('Latest published draft.');
    cy.meSavePage();
    cy.meExitEditor();
    cy.then(() => cy.visit(`/node/${nid}/revisions`));
    cy.get('.revision-current').contains('Latest published draft.');

    // Change moderation state without exiting the editor.
    cy.then(() => cy.visit(`/node/${nid}`));
    cy.meEditPage();
    cy.get('[name="moderation_state[0][state]"]').select('Draft');
    cy.get('[name="revision_log[0][value]"]').type(
      'Latest draft - without exit.',
    );
    cy.meSavePage();
    cy.get('[name="moderation_state[0][state]"]').select('Published');
    cy.get('[name="revision_log[0][value]"]').clear();
    cy.get('[name="revision_log[0][value]"]').type(
      'Latest published - without exit.',
    );
    cy.meSavePage();
    cy.meExitEditor();

    cy.then(() => cy.visit(`/node/${nid}/revisions`));
    cy.get('body')
      .find('.revision-current')
      .should('contain', 'Latest published - without exit.');

    // Install the Mercury Editor test module.
    cy.drush('en mercury_editor_layout_builder_test');
    // Create a new page.
    cy.visit('/node/add/me_test_ct');
    cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');
    // Tests that syncing the title field works.
    cy.get('#edit-title-0-value').clear();
    cy.get('#edit-title-0-value').type('-- Test page --');
    cy.iframe('#me-preview').find('.page-title').contains(' -- Test page --');
    cy.basicMercuryEditorInteractions();
    cy.meDeletePage();
    cy.drush('pmu mercury_editor_layout_builder_test');
  });

  it('tests that block conditional visibility works with Mercury Editor', () => {
    /* eslint-disable max-nested-callbacks */
    cy.drush('en mercury_editor_block_visibility_test');
    cy.visit('/node/add/me_test_ct');
    cy.iframe('#me-preview')
      .find('.block-mercury-editor-block-visibility-test')
      .should('contain', 'Tests block visibility with Mercury Editor.');
    cy.meAddComponent('me_test_section');
    cy.meChooseLayout('layout_twocol');
    cy.meSaveComponent().then((section) => {
      cy.meSelectComponent(section.attr('data-uuid'));
      cy.meAddComponent('me_test_text', {
        region: 'first',
        section,
      });
      cy.meSetCKEditor5Value('field_me_test_text', 'Left');
      cy.meSaveComponent().then((component) => {
        cy.iframe('#me-preview').find(component).should('contain', 'Left');
      });

      cy.meSelectComponent(section.attr('data-uuid'));
      cy.meAddComponent('me_test_text', {
        region: 'second',
        section,
      });
      cy.meSetCKEditor5Value('field_me_test_text', 'Right');
      cy.meSaveComponent().then((component) => {
        cy.iframe('#me-preview').find(component).should('contain', 'Right');
      });
    });

    cy.meSavePage();
    cy.iframe('#me-preview')
      .find('.block-mercury-editor-block-visibility-test')
      .should('contain', 'Tests block visibility with Mercury Editor.');
    cy.meExitEditor();
    cy.get('.block-mercury-editor-block-visibility-test').should(
      'contain',
      'Tests block visibility with Mercury Editor.',
    );
    cy.meDeletePage();
    cy.drush('pmu mercury_editor_block_visibility_test');
  });
});
