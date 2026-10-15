/**
 * Mercury Editor AJAX indicator e2e tests.
 */
Cypress.config('defaultCommandTimeout', 10000);

describe('Mercury Editor AJAX indicator', () => {
  before(() => {
    cy.drush('en mercury_editor_setup_test');
    cy.drush('cr');
    cy.viewport(1000, 800);
  });

  beforeEach(() => {
    cy.loginUserByUid(1);
    cy.visit('/node/add/me_test_ct');
    cy.get('#me-preview').its('0.contentDocument');
    cy.get('#me-ajax-status').should('have.text', '');
    cy.get('.me-ajax-indicator').should('have.attr', 'aria-hidden', 'true');
  });

  it('shows and clears the indicator for a long request', () => {
    cy.intercept('GET', '/mercury-editor-ajax-test/slow', {
      delay: 750,
      statusCode: 200,
      body: {},
    }).as('slowRequest');

    cy.window().then((win) => {
      win.jQuery.ajax('/mercury-editor-ajax-test/slow');
    });

    cy.get('body').should('have.class', 'me-ajax-busy');
    cy.get('.me-ajax-indicator').should('be.visible');
    cy.get('#me-preview').should('have.css', 'pointer-events', 'none');
    cy.get('#me-ajax-status').should('have.text', 'Updating…');

    cy.wait('@slowRequest');
    cy.get('body').should('not.have.class', 'me-ajax-busy');
    cy.get('.me-ajax-indicator').should('not.be.visible');
    cy.get('#me-ajax-status').should('have.text', '');
    cy.get('#me-preview').should('have.css', 'pointer-events', 'auto');
  });

  it('keeps the busy state while requests overlap', () => {
    cy.intercept('GET', '/mercury-editor-ajax-test/first', {
      delay: 150,
      statusCode: 200,
      body: {},
    }).as('firstRequest');
    cy.intercept('GET', '/mercury-editor-ajax-test/second', {
      delay: 600,
      statusCode: 200,
      body: {},
    }).as('secondRequest');

    cy.window().then((win) => {
      win.jQuery.ajax('/mercury-editor-ajax-test/first');
      win.jQuery.ajax('/mercury-editor-ajax-test/second');
    });

    cy.get('body').should('have.class', 'me-ajax-busy');
    cy.wait('@firstRequest');
    cy.get('body').should('have.class', 'me-ajax-busy');
    cy.wait('@secondRequest');
    cy.get('body').should('not.have.class', 'me-ajax-busy');
  });

  it('clears the indicator after a failed request', () => {
    cy.intercept('GET', '/mercury-editor-ajax-test/failure', {
      statusCode: 500,
      body: {},
    }).as('failedRequest');

    cy.window().then((win) => {
      win.jQuery.ajax('/mercury-editor-ajax-test/failure').fail(() => {});
    });

    cy.get('body').should('have.class', 'me-ajax-busy');
    cy.wait('@failedRequest');
    cy.get('body').should('not.have.class', 'me-ajax-busy');
    cy.get('.me-ajax-indicator').should('not.be.visible');
  });

  it('does not announce overlapping requests more than once', () => {
    cy.intercept('GET', '/mercury-editor-ajax-test/announce-first', {
      delay: 150,
      statusCode: 200,
      body: {},
    }).as('announceFirst');
    cy.intercept('GET', '/mercury-editor-ajax-test/announce-second', {
      delay: 600,
      statusCode: 200,
      body: {},
    }).as('announceSecond');

    cy.window().then((win) => {
      win.jQuery.ajax('/mercury-editor-ajax-test/announce-first');
      win.jQuery.ajax('/mercury-editor-ajax-test/announce-second');
    });

    cy.get('#me-ajax-status').should('have.text', 'Updating…');
    cy.wait('@announceFirst');
    cy.get('#me-ajax-status').should('have.text', 'Updating…');
    cy.wait('@announceSecond');
    cy.get('#me-ajax-status').should('have.text', '');
  });

  it('keeps the busy state across adjacent requests', () => {
    cy.intercept('GET', '/mercury-editor-ajax-test/adjacent-first', {
      statusCode: 200,
      body: {},
    }).as('adjacentFirst');
    cy.intercept('GET', '/mercury-editor-ajax-test/adjacent-second', {
      delay: 300,
      statusCode: 200,
      body: {},
    }).as('adjacentSecond');

    cy.window().then((win) => {
      win.jQuery.ajax('/mercury-editor-ajax-test/adjacent-first', {
        complete: () => {
          win.jQuery.ajax('/mercury-editor-ajax-test/adjacent-second');
        },
      });
    });

    cy.wait('@adjacentFirst');
    cy.get('body').should('have.class', 'me-ajax-busy');
    cy.get('.me-ajax-indicator').should('be.visible');
    cy.wait('@adjacentSecond');
    cy.get('body').should('not.have.class', 'me-ajax-busy');
  });
});
