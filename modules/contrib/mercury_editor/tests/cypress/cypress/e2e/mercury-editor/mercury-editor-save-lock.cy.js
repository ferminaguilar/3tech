/**
 * Checks the real Drupal form AJAX lifecycle without persisting test content.
 * Requires the Mercury Editor test content type used by the other editor specs.
 */
describe('Mercury Editor save lock', () => {
  beforeEach(() => {
    cy.loginUserByUid(1);
    cy.visit('/node/add/me_test_ct');
    cy.meStateReady();
    cy.get('#me-save-btn').should('exist');
    cy.window().should((win) => {
      expect(win.jQuery.active).to.equal(0);
    });
  });

  it('blocks repeat saves, editing, and history until response commands finish', () => {
    let saveRequests = 0;
    let historyRequests = 0;
    cy.intercept('**/mercury-editor/*/undo*', () => {
      historyRequests += 1;
    });
    cy.intercept('**/mercury-editor/*/redo*', () => {
      historyRequests += 1;
    });
    cy.intercept('POST', '**/mercury-editor/*', (req) => {
      if (!String(req.body).includes('mercury_editor_save')) return;
      saveRequests += 1;
      req.reply({
        delay: 700,
        statusCode: 200,
        headers: { 'X-Drupal-Ajax-Token': '1' },
        body: [{ command: 'holdSaveTest' }],
      });
    }).as('save');
    cy.window().then((win) => {
      win.Drupal.AjaxCommands.prototype.holdSaveTest = () => {
        win.saveCommandsRunning = true;
        return new win.Promise((resolve) => {
          win.setTimeout(() => {
            win.saveCommandsRunning = false;
            resolve();
          }, 1000);
        });
      };
    });
    cy.get('#me-save-btn').click();
    cy.get('body')
      .should('have.class', 'me-saving')
      .and('have.prop', 'inert', true);
    cy.get('#me-save-btn').should('be.disabled');
    cy.get('#me-undo').should('be.disabled');
    cy.get('#me-redo').should('be.disabled');
    cy.window().then((win) => {
      // Bypass browser hit testing to exercise the event and request guards.
      win.document
        .querySelector('#me-save-btn')
        .dispatchEvent(new win.MouseEvent('click', { bubbles: true }));
      win.Drupal.ajax({ url: `${win.location.pathname}/undo` })
        .execute()
        .catch(() => {});
      win.Drupal.ajax({ url: `${win.location.pathname}/redo` })
        .execute()
        .catch(() => {});
    });
    cy.wait('@save');
    cy.window().should((win) => {
      expect(win.saveCommandsRunning).to.equal(true);
      expect(win.Drupal.mercuryEditorSaveLock.isLocked()).to.equal(true);
    });
    cy.get('body')
      .should('not.have.class', 'me-saving')
      .and('have.prop', 'inert', false);
    cy.get('#me-save-btn').should('not.be.disabled');
    cy.get('#me-preview').should('have.css', 'pointer-events', 'auto');
    cy.then(() => {
      expect(saveRequests).to.equal(1);
      expect(historyRequests).to.equal(0);
    });
  });

  it('routes an implicit keyboard submission through the same save lock', () => {
    cy.intercept('POST', '**/mercury-editor/*', {
      delay: 500,
      statusCode: 200,
      headers: { 'X-Drupal-Ajax-Token': '1' },
      body: [],
    }).as('keyboardSave');
    cy.window().then((win) => {
      win.document.querySelector('.me-entity-form').requestSubmit();
    });
    cy.get('body').should('have.class', 'me-saving');
    cy.wait('@keyboardSave')
      .its('request.body')
      .should('include', 'mercury_editor_save');
    cy.get('body').should('not.have.class', 'me-saving');
    cy.get('#me-preview').should('have.css', 'pointer-events', 'auto');
  });
});
