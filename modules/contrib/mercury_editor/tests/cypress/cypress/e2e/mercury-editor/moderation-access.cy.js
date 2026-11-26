const getFixture = (title) => {
  cy.loginUserByUid(1);
  cy.visit('/admin/content');
  cy.get('[name="title"]').clear().type(title);
  cy.get('#edit-submit-content').click();
  return cy.contains('tr', title).find('a[href*="/node/"][href*="/edit"]')
    .first()
    .invoke('attr', 'href')
    .then((href) => {
      const match = href.match(/\/node\/(\d+)\/edit/);
      expect(match, 'content edit link').not.to.be.null;
      const id = match[1];
      cy.loginUserByUsername('me_approval_author');
      cy.visit(`/node/${id}/edit`);
      return cy.location('pathname').then((pathname) => {
        const editor = pathname.match(/\/mercury-editor\/([0-9a-f-]+)$/);
        expect(editor, 'editor URL').not.to.be.null;
        return { id, uuid: editor[1] };
      });
  });
};

const expectProgress = () => {
  cy.wait('@progress').then(({ response }) => {
    expect(response.statusCode).to.eq(200);
    expect(response.body.map((command) => command.command)).not.to.include('redirect');
    expect(response.body).not.to.deep.include({
      command: 'invoke',
      selector: '.me-entity-form',
      method: 'removeClass',
      args: ['unsaved-changes'],
    });
  });
};

const expectSavedTitle = (id, title, pending = false) => {
  cy.request(`/node/${id}${pending ? '/latest' : ''}`).its('body').should('contain', title);
};

const expectModerationState = (id, state) => {
  cy.loginUserByUid(1);
  cy.visit(`/node/${id}/edit`);
  cy.contains('Current state').parent().should('contain', state);
};

describe('Moderation access after saving', () => {
  before(() => {
    cy.drush('pm:list', [], { status: 'enabled', format: 'list' })
      .its('stdout')
      .then((modules) => {
        if (modules.split(/\s+/).includes('mercury_editor_content_moderation_test')) {
          cy.drush('pmu mercury_editor_content_moderation_test');
        }
      });
    cy.drush('en mercury_editor_content_moderation_test');
    cy.drush('cr');
  });

  beforeEach(() => {
    cy.viewport(1200, 800);
    cy.loginUserByUsername('me_approval_author');
    cy.intercept('POST', '**', (request) => {
      const body = typeof request.body === 'string'
        ? Object.fromEntries(new URLSearchParams(request.body))
        : request.body;
      const button = body?._triggering_element_value || body?.op;
      if (button === 'Save progress') {
        request.alias = 'progress';
      }
    });
  });

  after(() => {
    cy.drush('pmu mercury_editor_content_moderation_test');
    cy.drush('cr');
  });

  [
    { title: 'Default revision', pending: false, destination: false },
    { title: 'Pending revision', pending: true, destination: false },
    { title: 'Destination override', pending: true, destination: true },
  ].forEach(({ title, pending, destination }) => {
    it(`submits ${title.toLowerCase()} for approval and redirects`, () => {
      getFixture(title).then(({ id, uuid }) => {
        const query = destination ? '?destination=/user' : '';
        const editedTitle = `${title} after autosave`;
        cy.visit(`/node/${id}/edit${query}`);
        cy.meStateReady();

        if (pending) {
          cy.get('[name="moderation_state[0][state]"]').select('draft');
          cy.meSavePage();
          // Wait for autosave to snapshot the replacement form before editing it.
          cy.meStateReady();
          cy.get('[name="moderation_state[0][state]"]').should('have.value', 'draft');
        }

        cy.get('[name="moderation_state[0][state]"]').select('needs_approval');
        expectProgress();
        cy.get('[name="title[0][value]"]').type('{end} after autosave');
        expectProgress();
        expectSavedTitle(id, title, pending);
        cy.iframe('#me-preview').should('contain', editedTitle);
        cy.get('.me-entity-form').should('have.class', 'unsaved-changes');
        cy.get('[name="title[0][value]"]').should('have.value', editedTitle)
          .then(($title) => expect($title[0].validity.valid, 'required title').to.be.true);

        if (destination) {
          cy.request({ url: '/user', followRedirect: false })
            .its('headers.location')
            .then((url) => new URL(url, Cypress.config('baseUrl')).pathname)
            .as('profilePath');
        }
        cy.intercept('POST', '**', (request) => {
          const body = typeof request.body === 'string'
            ? Object.fromEntries(new URLSearchParams(request.body))
            : request.body;
          if ((body?._triggering_element_value || body?.op) === 'Save') {
            request.alias = 'finalSave';
          }
        });
        cy.get('#me-save-btn').click();
        cy.wait('@finalSave').then(({ response }) => {
          expect(response.statusCode).to.eq(200);
          const commands = response.body;
          const clear = commands.findIndex((command) => command.command === 'invoke' && command.method === 'removeClass' && command.selector === '.me-entity-form' && command.args.includes('unsaved-changes'));
          const redirect = commands.findIndex((command) => command.command === 'redirect');
          expect(clear).to.be.at.least(0);
          expect(redirect).to.be.greaterThan(clear);
          expect(commands.filter((command) => command.command === 'redirect')).to.have.length(1);
          const target = destination ? '/user' : `/node/${id}${pending ? '/latest' : ''}`;
          expect(new URL(commands[redirect].url, window.location.origin).pathname).to.eq(target);
        });
        if (destination) {
          cy.get('@profilePath').then((profilePath) => {
            cy.location('pathname').should('eq', profilePath);
          });
        }
        else {
          cy.location('pathname').should('eq', `/node/${id}${pending ? '/latest' : ''}`);
        }
        expectSavedTitle(id, editedTitle, pending);
        cy.request({ url: `/mercury-editor/${uuid}`, failOnStatusCode: false })
          .its('status')
          .should('eq', 403);
        expectModerationState(id, 'Needs Approval');
      });
    });
  });

  it('saves an editable revision and keeps the editor open', () => {
    getFixture('Editable revision').then(({ id }) => {
      cy.visit(`/node/${id}/edit`);
      cy.meStateReady();
      cy.get('[name="title[0][value]"]').type('{end} saved');
      expectProgress();
      cy.meSavePage();
      cy.get('[name="title[0][value]"]').should('have.value', 'Editable revision saved');
      cy.get('.me-entity-form').should('exist');
      expectSavedTitle(id, 'Editable revision saved');
      expectModerationState(id, 'Draft');
    });
  });

  it('shows validation errors and stays in the editor', () => {
    getFixture('Validation error').then(({ id }) => {
      cy.visit(`/node/${id}/edit`);
      cy.meStateReady();
      cy.get('[name="title[0][value]"]').clear();
      cy.get('.me-form-error-messages__button').should('be.visible');
      cy.get('#me-save-btn').click();
      cy.get('.me-form-error-messages__wrapper').should('contain', 'field is required');
      cy.get('.me-entity-form').should('exist');
      expectSavedTitle(id, 'Validation error');
    });
  });
});
