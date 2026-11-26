import 'cypress-real-events/support';

const collectionPath = '/admin/content/block';
const fixturePath = '/mercury-editor-block-content-test';

/**
 * Gets the preview block element for the given UUID.
 *
 * @param {string} uuid
 * @returns {Cypress.Chainable<JQuery<HTMLElement>>} The preview block element.
 */
const previewBlock = (uuid) => cy.iframe('#me-preview')
  .find(`[data-me-edit-screen-key="${uuid}"]`);

/**
 * Waits for the iframe document to load the requested preview option.
 *
 * @param {string} key
 * @param {string} value
 */
const expectFrameOption = (key, value) => {
  cy.get('#me-preview').should(($iframe) => {
    const document = $iframe[0].contentDocument;
    expect(document.readyState).to.eq('complete');
    expect(new URL(document.location.href).searchParams.get(key)).to.eq(value);
  });
};

/**
 * Expects the preview block for the given UUID to be present and optionally contain the given text.
 *
 * @param {string} uuid
 * @param {string} [text]
 */
const expectPreview = (uuid, text) => {
  previewBlock(uuid).should('have.length', 1).and('be.visible');
  cy.iframe('#me-preview').find('main')
    .find(`[data-me-edit-screen-key="${uuid}"]`)
    .should('have.length', 1);
  previewBlock(uuid).find('[data-lpb-id]').should('have.length', 1);
  cy.get('#me-preview').its('0.contentWindow.drupalSettings.mercuryEditor.id')
    .should('eq', uuid);
  if (text) {
    previewBlock(uuid).should('contain', text);
  }
};

/**
 * Visits the Mercury Editor for the given href, ensuring the editor is loaded.
 *
 * @param {string} href
 */
const visitEditor = (href) => {
  const url = new URL(href, Cypress.config('baseUrl'));
  url.searchParams.set('destination', collectionPath);
  cy.visit(`${url.pathname}${url.search}`);
  cy.location('pathname').should('match', /^\/mercury-editor\/[0-9a-f-]+$/);
  cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');
};

/**
 * Opens the Mercury Editor for the given block and expects its preview to be present.
 *
 * @param {object} block
 * @param {string} block.id
 * @param {string} block.uuid
 * @param {string} block.text
 */
const openBlock = (block) => {
  visitEditor(`${collectionPath}/${block.id}`);
  expectPreview(block.uuid, block.text);
};

/**
 * Edits the text of the given block in the Mercury Editor.
 *
 * @param {object} block
 * @param {string} block.uuid
 * @param {string} text
 */
const editBlockText = (block, text) => {
  previewBlock(block.uuid).find('[data-type="me_test_text"]').first()
    .invoke('attr', 'data-uuid').then((uuid) => {
      cy.meSelectComponent(uuid);
      cy.meSetCKEditor5Value('field_me_test_text', text);
      cy.meSaveComponent();
      previewBlock(block.uuid).find(`[data-uuid="${uuid}"]`)
        .should('have.length', 1).and('contain', text);
    });
};

/**
 * Updates the description (label) of the current block in the Mercury Editor.
 *
 * @param {string} label
 */
const updateDescription = (label) => {
  cy.iframe('#me-preview').click(10, 10);
  cy.intercept('POST', /\/mercury-editor\/[0-9a-f-]+(?:\?.*)?$/, (request) => {
    const values = new URLSearchParams(request.body);
    if (values.get('info[0][value]') === label
      && values.get('_triggering_element_value') === 'Save progress') {
      request.alias = 'blockProgress';
    }
  });
  cy.get('[name="info[0][value]"]').clear().type(label);
  cy.wait('@blockProgress').its('response.statusCode').should('eq', 200);
  cy.get('body').should('not.have.class', 'me-ajax-busy');
  cy.iframe('#me-preview').find('[data-uuid][data-active="true"]').should('not.exist');
};

/**
 * Saves the current block and reopens it, expecting the preview to be present with the given text.
 *
 * @param {string} label
 * @param {string} uuid
 * @param {string} text
 */
const saveAndReopen = (label, uuid, text) => {
  cy.meSavePage();
  expectPreview(uuid, text);
  cy.meExitEditor();
  cy.location('pathname').should('eq', collectionPath);
  cy.contains('tr', label).contains('a', label)
    .invoke('attr', 'href').then(visitEditor);
  expectPreview(uuid, text);
};

describe('Mercury Editor content blocks', () => {
  before(() => {
    cy.drush('en mercury_editor_block_content_test');
    cy.drush('cr');
  });

  beforeEach(() => {
    cy.viewport(1200, 800);
    cy.drush('php:eval', [
      "'echo json_encode(mercury_editor_block_content_test_reset());'",
    ]).its('stdout').then((output) => JSON.parse(output)).as('blocks');
    cy.loginUserByUsername('me_block_content_editor');
  });

  after(() => {
    cy.drush('pmu mercury_editor_block_content_test');
    cy.drush('cr');
  });

  it('exits a new block to its destination or the block library', () => {
    const destination = `${fixturePath}/hidden?from=block-add`;
    cy.visit(`/block/add/me_test_block?destination=${encodeURIComponent(destination)}`);
    cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');
    cy.meExitEditor();
    cy.location('pathname').should('eq', `${fixturePath}/hidden`);
    cy.location('search').should('eq', '?from=block-add');

    cy.visit('/block/add/me_test_block');
    cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');
    cy.meExitEditor();
    cy.location('pathname').should('eq', collectionPath);
  });

  it('exits a saved new block to its destination or the block library', () => {
    cy.visit('/block/add/me_test_block');
    cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');
    updateDescription('New block with library exit');
    cy.meSavePage();
    cy.meExitEditor();
    cy.location('pathname').should('eq', collectionPath);
    cy.contains('tr', 'New block with library exit').should('exist');

    const destination = `${fixturePath}/hidden?from=block-add`;
    cy.visit(`/block/add/me_test_block?destination=${encodeURIComponent(destination)}`);
    cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');
    updateDescription('New block with destination exit');
    cy.meSavePage();
    cy.meExitEditor();
    cy.location('pathname').should('eq', `${fixturePath}/hidden`);
    cy.location('search').should('eq', '?from=block-add');
  });

  it('operates preview options with the keyboard and restores focus', () => {
    cy.get('@blocks').then(({ global }) => {
      openBlock(global);
      cy.get('#me-block-preview-options-fields').should('not.be.visible');
      cy.get('#me-block-preview-options-toggle')
        .should('contain.text', 'Preview options')
        .and('have.attr', 'aria-expanded', 'false')
        .and('have.attr', 'aria-controls', 'me-block-preview-options-fields')
        .focus();
      cy.realPress('Enter');
      cy.get('#me-block-preview-options-fields').should('be.visible');
      cy.get('#me-block-preview-options-toggle').should('have.attr', 'aria-expanded', 'true');
      cy.realPress('Tab');
      cy.focused().should('have.id', 'me-preview-region');
      cy.realPress('Escape');
      cy.get('#me-block-preview-options-fields').should('not.be.visible');
      cy.focused().should('match', '#me-block-preview-options-toggle')
        .and('have.attr', 'aria-expanded', 'false');
      cy.realPress('Space');
      cy.get('#me-block-preview-options-fields').should('be.visible');
      cy.get('#me-block-preview-options-toggle').should('have.attr', 'aria-expanded', 'true');
      cy.realPress('Enter');
      cy.get('#me-block-preview-options-fields').should('not.be.visible');
      cy.get('#me-block-preview-options-toggle').should('have.attr', 'aria-expanded', 'false');
    });
  });

  it('returns to the previous page after changing preview options', () => {
    cy.get('@blocks').then(({ global }) => {
      cy.visit(collectionPath);
      cy.contains('tr', global.label).contains('a', global.label).click();
      cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');
      expectPreview(global.uuid, global.text);

      const title = 'History preview title';
      cy.intercept('GET', '**/mercury-editor-preview*', (request) => {
        request.alias = request.query.me_preview_title === title
          ? 'historyTitle'
          : 'historyPreview';
      });
      cy.get('#me-block-preview-options-toggle').click();
      cy.get('#me-preview-region').select(global.alternate_region);
      cy.wait('@historyPreview');
      expectFrameOption('me_preview_region', global.alternate_region);
      cy.get('#me-preview-show-title').uncheck();
      cy.wait('@historyPreview');
      expectFrameOption('me_preview_show_title', '0');
      cy.get('#me-preview-show-title').check();
      cy.wait('@historyPreview');
      cy.get('#me-preview-title').clear().type(title);
      cy.wait('@historyTitle');
      expectFrameOption('me_preview_title', title);

      cy.go('back');
      cy.location('pathname').should('eq', collectionPath);
    });
  });

  it('uses collection defaults and previews temporary region and title changes', () => {
    cy.get('@blocks').then(({ global }) => {
      openBlock(global);
      const temporaryTitle = 'Temporary <em>preview</em> & title';
      cy.get('#me-block-preview-options-toggle').click();
      cy.get('#me-preview-region').should('have.value', 'content');
      cy.get('#me-preview-show-title').should('be.checked');
      cy.get('#me-preview-title').should('have.value', global.label);
      previewBlock(global.uuid).find('h2').should('contain', global.label);

      cy.intercept('GET', '**/mercury-editor-preview*', (request) => {
        request.alias = request.query.me_preview_title === temporaryTitle
          ? 'titlePreview'
          : 'previewReload';
      });
      ['page_top', 'page_bottom'].forEach((region) => {
        cy.get('#me-preview-region').select(region);
        cy.wait('@previewReload');
        expectFrameOption('me_preview_region', region);
        previewBlock(global.uuid).closest(`.region--${region.replace('_', '-')}`).should('exist');
      });
      cy.get('#me-preview-region').select(global.alternate_region);
      cy.wait('@previewReload');
      expectFrameOption('me_preview_region', global.alternate_region);
      previewBlock(global.uuid).closest(`.region--${global.alternate_region}`).should('exist');
      cy.get('#me-preview-show-title').uncheck();
      cy.get('#me-preview-title-label').should('not.be.visible');
      cy.wait('@previewReload');
      expectFrameOption('me_preview_show_title', '0');
      previewBlock(global.uuid).find('h2').should('not.exist');
      cy.get('#me-preview-show-title').check();
      cy.wait('@previewReload');
      expectFrameOption('me_preview_show_title', '1');
      cy.get('#me-preview-title').clear().type(temporaryTitle);
      cy.wait('@titlePreview');
      expectFrameOption('me_preview_title', temporaryTitle);
      previewBlock(global.uuid).find('h2').should('have.text', temporaryTitle);
      previewBlock(global.uuid).find('h2 em').should('not.exist');

      cy.reload();
      cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');
      cy.get('#me-preview-region').should('have.value', global.alternate_region);
      cy.get('#me-preview-title').should('have.value', temporaryTitle);
      expectFrameOption('me_preview_title', temporaryTitle);
      previewBlock(global.uuid).closest(`.region--${global.alternate_region}`).should('exist');
      previewBlock(global.uuid).find('h2').should('have.text', temporaryTitle);

      updateDescription('Edited block description');
      previewBlock(global.uuid).find('h2').should('have.text', temporaryTitle);
      previewBlock(global.uuid).closest(`.region--${global.alternate_region}`).should('exist');
      editBlockText(global, 'Content edited with preview options.');
      previewBlock(global.uuid).should('contain', 'Content edited with preview options.');
      cy.meExitEditor();
      cy.location('pathname').should('eq', collectionPath);

      cy.visit(`${fixturePath}/hidden`);
      cy.get('#block-me-block-content-alternate').should('contain', 'Alternate placement title');
      cy.get('#block-me-block-content-alternate').should('not.contain', temporaryTitle);
      openBlock(global);
      cy.get('#me-preview-region').should('have.value', 'content');
      cy.get('#me-preview-title').should('have.value', global.label);
    });
  });

  it('uses the clicked frontend placement instead of another placement', () => {
    cy.get('@blocks').then(({ global }) => {
      cy.visit(`${fixturePath}/hidden`);
      cy.get('#block-me-block-content-global .contextual .trigger').click({ force: true });
      cy.get('#block-me-block-content-global .contextual-links')
        .contains('a', /^Edit$/).click();
      cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');
      cy.get('#me-preview-region').should('have.value', 'content');
      cy.get('#me-preview-show-title').should('not.be.checked');
      cy.get('#me-preview-title-label').should('not.be.visible');
      previewBlock(global.uuid).find('h2').should('not.exist');

      cy.visit(`${fixturePath}/hidden`);
      cy.get('#block-me-block-content-alternate .contextual .trigger').click({ force: true });
      cy.get('#block-me-block-content-alternate .contextual-links')
        .contains('a', /^Edit$/).click();
      cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');
      cy.get('#me-preview-region').should('have.value', global.alternate_region);
      cy.get('#me-preview-show-title').should('be.checked');
      cy.get('#me-preview-title').should('have.value', 'Alternate placement title');
      previewBlock(global.uuid).closest(`.region--${global.alternate_region}`).should('exist');
      previewBlock(global.uuid).find('h2').should('contain', 'Alternate placement title');
      previewBlock(global.uuid).should('have.length', 1);
      updateDescription('Editing through alternate placement');
      previewBlock(global.uuid).closest(`.region--${global.alternate_region}`).should('exist');
      previewBlock(global.uuid).find('h2').should('contain', 'Alternate placement title');
    });
  });

  it('uses block layout placement settings and that placement theme', () => {
    cy.get('@blocks').then(({ global }) => {
      cy.visit(`/admin/structure/block/list/${global.admin_theme}`);
      cy.contains('tr', 'Admin placement title').contains('a', 'Edit block').click({ force: true });
      cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');
      cy.get('#me-preview-region').should('have.value', global.admin_region);
      cy.get('#me-preview-show-title').should('be.checked');
      cy.get('#me-preview-title').should('have.value', 'Admin placement title');
      cy.get('#me-preview').its('0.contentWindow.drupalSettings.ajaxPageState.theme')
        .should('eq', global.admin_theme);
      previewBlock(global.uuid).should('contain', 'Admin placement title');
    });
  });

  it('rejects a placement belonging to a different content block', () => {
    cy.get('@blocks').then(({ restricted, global }) => {
      cy.visit(`${fixturePath}/hidden`);
      cy.get('#block-me-block-content-global .contextual .trigger').click({ force: true });
      cy.get('#block-me-block-content-global .contextual-links').contains('a', /^Edit$/)
        .invoke('attr', 'href').then((href) => {
          const query = new URL(href, Cypress.config('baseUrl')).search;
          cy.request({
            url: `${collectionPath}/${restricted.id}${query}`,
            failOnStatusCode: false,
          }).its('status').should('eq', 404);
          cy.request({
            url: `${collectionPath}/${global.id}?me_block_placement=me_block_content_global`,
            failOnStatusCode: false,
          }).its('status').should('eq', 404);
        });
    });
  });

  it('creates an unplaced block and preserves its layout after reopening', () => {
    visitEditor('/block/add/me_test_block');
    cy.location('pathname').then((pathname) => {
      const uuid = pathname.split('/').pop();
      const label = 'ME content block created in preview';
      const text = 'New block layout content.';
      expectPreview(uuid);
      updateDescription(label);
      cy.meAddComponent('me_test_section');
      cy.meChooseLayout('layout_twocol');
      cy.meSaveComponent().then((section) => {
        // With only one allowed child type, LP opens the text form directly.
        cy.intercept('POST', '**/action/insert*').as('insertText');
        cy.iframe('#me-preview')
          .find(`[data-me-component-action="insert"][data-me-parent-uuid="${section.attr('data-uuid')}"][data-me-region="first"]`)
          .first().click();
        cy.wait('@insertText').its('response.statusCode').should('eq', 200);
        cy.meSetCKEditor5Value('field_me_test_text', text);
        cy.meSaveComponent();
      });
      previewBlock(uuid).find('.layout__region--first').should('contain', text);
      previewBlock(uuid).find('.layout__region--second').should('not.contain', text);
      saveAndReopen(label, uuid, text);
      previewBlock(uuid).find('.layout__region--first').should('contain', text);
      cy.visit(`${fixturePath}/hidden`);
      cy.get('main').should('not.contain', text);
    });
  });

  it('edits an unplaced block through component and parent-form refreshes', () => {
    cy.get('@blocks').then(({ unplaced }) => {
      const text = 'Updated unplaced block content.';
      const label = 'ME content block: unplaced edited';
      openBlock(unplaced);
      editBlockText(unplaced, text);
      updateDescription(label);
      expectPreview(unplaced.uuid, text);
      // Reopen the component after a full parent-form preview replacement.
      editBlockText(unplaced, `${text} After refresh.`);
      saveAndReopen(label, unplaced.uuid, `${text} After refresh.`);
      cy.visit(`${fixturePath}/hidden`);
      cy.get('main').should('not.contain', 'After refresh.');
    });
  });

  it('edits a restricted block without changing placement visibility', () => {
    cy.get('@blocks').then(({ restricted }) => {
      const text = 'Updated restricted block content.';
      cy.visit(`${fixturePath}/visible`);
      cy.get('#block-me-block-content-restricted').should('contain', restricted.text);
      cy.visit(`${fixturePath}/hidden`);
      cy.get('#block-me-block-content-restricted').should('not.exist');
      openBlock(restricted);
      editBlockText(restricted, text);
      saveAndReopen(restricted.label, restricted.uuid, text);
      cy.visit(`${fixturePath}/hidden`);
      cy.get('#block-me-block-content-restricted').should('not.exist');
      cy.visit(`${fixturePath}/visible`);
      cy.get('#block-me-block-content-restricted').should('contain', text);
    });
  });

  it('keeps one editing target for a globally placed block after refreshes', () => {
    cy.get('@blocks').then(({ global }) => {
      const text = 'Updated global block content.';
      const label = 'ME content block: global edited';
      cy.visit(`${fixturePath}/hidden`);
      cy.get('#block-me-block-content-global').should('contain', global.text);
      openBlock(global);
      editBlockText(global, text);
      updateDescription(label);
      expectPreview(global.uuid, text);
      editBlockText(global, `${text} After refresh.`);
      saveAndReopen(label, global.uuid, `${text} After refresh.`);
      cy.visit(`${fixturePath}/hidden`);
      cy.get('#block-me-block-content-global')
        .should('have.length', 1).and('contain', 'After refresh.');
    });
  });

  it('denies preview content to anonymous users and users without edit permission', () => {
    cy.get('@blocks').then(({ unplaced }) => {
      const url = `/block-content/${unplaced.uuid}/mercury-editor-preview`;
      cy.clearCookies();
      cy.request({ url, failOnStatusCode: false }).then((response) => {
        expect(response.status).to.eq(403);
        expect(response.body).not.to.contain(unplaced.text);
      });
      cy.loginUserByUsername('me_block_content_viewer');
      cy.request({ url, failOnStatusCode: false }).then((response) => {
        expect(response.status).to.eq(403);
        expect(response.body).not.to.contain(unplaced.text);
      });
    });
  });
});
