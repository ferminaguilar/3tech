/**
 * Tests full-page morphing with an independently cached title above the node.
 */
Cypress.config('defaultCommandTimeout', 15000);

describe('Mercury Editor full-page preview', () => {
  before(() => {
    cy.drush('en', ['mercury_editor_page_preview_test']);
    cy.drush('cr');
  });

  after(() => {
    const config = String.raw`\Drupal::configFactory()->getEditable("mercury_editor.settings")`;
    cy.drush('php:eval', [
      `'${config}->clear("full_page_preview.node.me_test_ct")->save();'`,
    ]);
    cy.drush('pmu', ['mercury_editor_page_preview_test']);
    cy.drush('cr');
  });

  beforeEach(() => {
    cy.loginUserByUid(1);
  });

  it('configures the content type and morphs a cached hero without reloading the iframe', () => {
    const config = String.raw`\Drupal::configFactory()->getEditable("mercury_editor.settings")`;
    cy.drush('php:eval', [
      `'${config}->set("full_page_preview.node.me_test_ct", "me_test_ct")->save();'`,
    ]);
    cy.visit('/admin/config/content/mercury-editor');
    cy.get('input[name="bundles[node][me_test_ct]"]').uncheck();
    cy.get('input[name="full_page_preview[node][me_test_ct]"]')
      .should('be.disabled')
      .and('be.checked');
    cy.get('input[name="bundles[node][me_test_ct]"]').check();
    cy.get('input[name="full_page_preview[node][me_test_ct]"]')
      .should('be.enabled')
      .and('be.checked');
    cy.get('input[name="bundles[node][me_test_ct]"]').uncheck();
    cy.get('input[name="full_page_preview[node][me_test_ct]"]')
      .should('be.disabled')
      .and('be.checked');
    cy.get('input[name="bundles[node][me_test_ct]"]').check();
    cy.get('input[name="full_page_preview[node][me_test_ct]"]').check();
    cy.get('#mercury-editor-settings-form').submit();
    cy.get('input[name="full_page_preview[node][me_test_ct]"]').should(
      'be.checked',
    );

    cy.visit('/node/add/me_test_ct');
    cy.meStateReady();
    cy.iframe('#me-preview').find('#me-page-preview-hero').should('exist');
    cy.get('#me-preview').then(($frame) => {
      const frame = $frame[0];
      const document = frame.contentDocument;
      const body = document.body;
      let morphs = 0;
      document.addEventListener('mercury-editor:morph:complete', (event) => {
        if (event.target === body) morphs += 1;
      });
      cy.wrap({ frame, document, body, morphs: () => morphs }).as('preview');
    });
    cy.intercept('POST', '**/mercury-editor/*').as('entityUpdate');
    cy.get('input[name="title[0][value]"]')
      .clear()
      .type('Hero preview first title');
    cy.iframe('#me-preview')
      .find('#me-page-preview-hero')
      .should('have.text', 'Hero preview first title');
    cy.wait('@entityUpdate').then(({ response }) => {
      const wrapper = response.body.find(
        (command) =>
          command.command === 'mercuryEditorEditIframeCommandsWrapper',
      );
      expect(
        wrapper.commands.some(
          (command) =>
            command.command === 'mercuryMorph' && command.selector === 'body',
        ),
      ).to.equal(true);
    });
    // State history groups changes made within three seconds.
    cy.wait(3100);
    cy.get('input[name="title[0][value]"]')
      .clear()
      .type('Hero preview second title');
    cy.iframe('#me-preview')
      .find('#me-page-preview-hero')
      .should('have.text', 'Hero preview second title');
    cy.get('@preview').then(({ frame, document, body, morphs }) => {
      expect(frame.contentDocument).to.equal(document);
      expect(document.body).to.equal(body);
      expect(morphs()).to.be.at.least(2);
    });
    // The full-page subrequest must not reset the editor's state history.
    cy.get('#me-undo').should('not.have.class', 'disabled');
    // Behaviors and add controls must still work after the body morphs.
    cy.meAddComponent('me_test_text');
    cy.get('.layout-paragraphs-component-form').should('exist');
    cy.meSetCKEditor5Value('field_me_test_text', 'Component after body morph');
    cy.meSaveComponent();
    cy.get('body').should('not.have.class', 'me-ajaxing');
    cy.iframe('#me-preview')
      .find('.js-lpb-component[data-uuid]')
      .should('have.length', 1)
      .and('contain', 'Component after body morph');

    // Refresh a preview that already contains a component, then add a sibling.
    cy.iframe('#me-preview').trigger('keydown', { key: 'Escape' });
    cy.get('.layout-paragraphs-component-form:visible').should('not.exist');
    cy.get('input[name="title[0][value]"]').type(' updated');
    cy.iframe('#me-preview')
      .find('#me-page-preview-hero')
      .should('have.text', 'Hero preview second title updated');
    cy.meFindComponent('Component after body morph').then((component) => {
      cy.meAddComponent('me_test_text', { after: component });
    });
    cy.meSetCKEditor5Value('field_me_test_text', 'Sibling after body morph');
    cy.meSaveComponent();
    cy.get('body').should('not.have.class', 'me-ajaxing');
    cy.iframe('#me-preview')
      .find('.js-lpb-component[data-uuid]')
      .should('have.length', 2)
      .and('contain', 'Sibling after body morph');
  });

  it('keeps the default entity scope when the content type is unchecked', () => {
    cy.visit('/admin/config/content/mercury-editor');
    cy.get('input[name="full_page_preview[node][me_test_ct]"]').uncheck();
    cy.get('#mercury-editor-settings-form').submit();
    cy.visit('/node/add/me_test_ct');
    cy.meStateReady();
    cy.intercept('POST', '**/mercury-editor/*').as('entityUpdate');
    cy.get('input[name="title[0][value]"]').clear().type('Entity-only preview');
    cy.wait('@entityUpdate').then(({ response }) => {
      const wrapper = response.body.find(
        (command) =>
          command.command === 'mercuryEditorEditIframeCommandsWrapper',
      );
      const morphs = wrapper.commands.filter(
        (command) => command.command === 'mercuryMorph',
      );
      expect(morphs.some((command) => command.selector === 'body')).to.equal(
        false,
      );
      expect(
        morphs.some((command) =>
          command.selector.includes('data-me-edit-screen-key'),
        ),
      ).to.equal(true);
    });
  });
});
