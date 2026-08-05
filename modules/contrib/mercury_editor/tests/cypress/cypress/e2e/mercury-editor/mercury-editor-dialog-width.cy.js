Cypress.config('defaultCommandTimeout', 10000);

/**
 * Helper: simulate the user dragging the resize handle on #me-edit-screen to
 * a given pixel width.
 *
 * Replicates what a real drag would do:
 *  1. mercury-dialog sets --me-dialog-dock-right-width on document.documentElement
 *  2. Its ResizeObserver fires mercury:dockResize from #me-edit-screen
 *  3. edit-screen.js saves width to localStorage
 *
 * @param {number} width Desired width in pixels.
 */
function simulateTrayResize(width) {
  cy.window().then((win) => {
    const dialogEl = win.document.querySelector('#me-edit-screen');

    // Step 1: Set the CSS custom property as mercury-dialog would after a drag.
    win.document.documentElement.style.setProperty(
      '--me-dialog-dock-right-width',
      `${width}px`,
    );
  });
}

/**
 * Helper: return the value of a CSS custom property set on <html>.
 *
 * @param {string} property CSS custom property name (e.g. '--me-dialog-dock-right-width').
 * @returns {Cypress.Chainable<string>} Trimmed value string.
 */
function getCSSVar(property) {
  return cy.window().then((win) =>
    win
      .getComputedStyle(win.document.documentElement)
      .getPropertyValue(property)
      .trim(),
  );
}

describe('Mercury Editor dialog width tests.', () => {
  before(() => {
    // Install the Mercury Editor test module.
    cy.drush('en mercury_editor_setup_test');
    // Clear the cache.
    cy.drush('cr');
    // Remove any component-specific dialog_settings that could interfere with
    // width assertions (e.g. me_test_text_form.width overwriting the user's
    // drag-resized width via _applySizingVars when the dialog opens).
    const cleanAllCmd = Cypress.env('drushCommand').replace(
      '$COMMAND',
      `php:eval '$config = \\Drupal::configFactory()->getEditable("mercury_editor.settings"); $settings = $config->get("dialog_settings") ?? []; unset($settings["me_test_text_form"]); unset($settings["dock_me_test_text_form"]); $config->set("dialog_settings", $settings)->save();' -y`,
    );
    cy.exec(cleanAllCmd);
    cy.drush('cr');
    // Use a wide viewport so the tray is visible without viewport clamping.
    cy.viewport(1400, 900);
  });

  beforeEach(() => {
    // Log in as admin.
    cy.loginUserByUid(1);
    // Start each test with a clean localStorage so no stale width values
    // interfere with the assertions below.
    cy.clearLocalStorage();
  });

  after(() => {
    // Restore the default dialog tray width so later test suites aren't affected.
    cy.drush('config:set', [
      'mercury_editor.settings',
      'dialog_tray_width',
      '400',
    ]);
    cy.drush('cr');
  });

  // ---------------------------------------------------------------------------
  // Test 1 – Initial width respects the admin setting.
  // ---------------------------------------------------------------------------
  it('respects the "Initial Dialog Tray Width" setting on admin/config/content/mercury-editor/dialog', () => {
    const testWidth = 600;

    // Change the initial tray width via config and rebuild caches.
    cy.drush('config:set', [
      'mercury_editor.settings',
      'dialog_tray_width',
      testWidth.toString(),
    ]);
    cy.drush('cr');

    cy.visit('/node/add/me_test_ct');
    cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');

    // edit-screen.js sets --me-dialog-width-default from
    // drupalSettings.mercuryEditor.defaultWidth (which comes from
    // mercury_editor.settings.dialog_tray_width).
    getCSSVar('--me-dialog-width-default').should('equal', `${testWidth}px`);

    // Also verify drupalSettings carries the configured value.
    cy.window()
      .its('drupalSettings.mercuryEditor.defaultWidth')
      .should('equal', testWidth);

    // Restore the default for subsequent tests.
    cy.drush('config:set', [
      'mercury_editor.settings',
      'dialog_tray_width',
      '400',
    ]);
    cy.drush('cr');
  });

  // ---------------------------------------------------------------------------
  // Test 2 – Width persists in localStorage when the user selects a new component.
  // ---------------------------------------------------------------------------
  it('preserves the user-set tray width when switching between components', () => {
    cy.visit('/node/add/me_test_ct');
    cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');

    // Add a first text component so there is something to navigate away from.
    cy.meAddComponent('me_test_text');
    cy.meSetCKEditor5Value('field_me_test_text', 'First component');

    cy.meSaveComponent().then((firstComponent) => {
      // Simulate the user dragging the tray border to 620 px.
      simulateTrayResize(620);

      // The resize handler must have persisted the value to localStorage.
      cy.window()
        .its('localStorage')
        .invoke('getItem', 'me-dialog-dock-right-width')
        .should('equal', '620');

      // The CSS custom property should reflect the new width immediately.
      getCSSVar('--me-dialog-dock-right-width').should('equal', '620px');

      // Simulate selecting a new component by adding a second one.
      cy.meAddComponent('me_test_text', { after: firstComponent });
      cy.meSetCKEditor5Value('field_me_test_text', 'Second component');
      cy.meSaveComponent();

      // The localStorage entry must not have been overwritten by the new
      // component form opening.
      cy.window()
        .its('localStorage')
        .invoke('getItem', 'me-dialog-dock-right-width')
        .should('equal', '620');

      // The CSS custom property should still reflect the user's chosen width.
      getCSSVar('--me-dialog-dock-right-width').should('equal', '620px');
    });
  });

  // ---------------------------------------------------------------------------
  // Test 3 – Width is restored from localStorage on page reload.
  // ---------------------------------------------------------------------------
  it('restores the tray width from localStorage after a page reload', () => {
    cy.visit('/node/add/me_test_ct');
    cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');

    // Simulate the user resizing the tray to 650 px.
    simulateTrayResize(650);

    // Verify localStorage was updated.
    cy.window()
      .its('localStorage')
      .invoke('getItem', 'me-dialog-dock-right-width')
      .should('equal', '650');

    // Reload the page (localStorage survives the reload).
    cy.reload();
    cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');

    // edit-screen.js reads 'me-dialog-dock-right-width' from localStorage on
    // attach and re-applies it to --me-dialog-dock-right-width.
    getCSSVar('--me-dialog-dock-right-width').should('equal', '650px');
  });

  // ---------------------------------------------------------------------------
  // Test 4 – Collapse closes the tray; re-opening restores the previous width.
  // ---------------------------------------------------------------------------
  it('collapses the tray and restores the previous width on re-opening', () => {
    cy.visit('/node/add/me_test_ct');
    cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');

    // Set a known user width before collapsing.
    simulateTrayResize(550);
    getCSSVar('--me-dialog-dock-right-width').should('equal', '550px');

    // Click the sidebar-toggle button to collapse the tray.
    // The button fires editor:closeSidebar, which stores the current width
    // to 'me-dialog-dock-right-width-expanded' and sets the CSS var to 10 px.
    cy.get('#me-sidebar-toggle-btn').click();

    // The button class should switch to indicate the sidebar is now collapsed.
    cy.get('#me-sidebar-toggle-btn').should(
      'have.class',
      'me-button--sidebar-expand',
    );

    // The tray width CSS var should be set to the collapsed sentinel value.
    getCSSVar('--me-dialog-dock-right-width').should('equal', '10px');

    // localStorage must record the collapsed state.
    cy.window()
      .its('localStorage')
      .invoke('getItem', 'mercury-dialog-dock-collapsed')
      .should('equal', 'true');

    // Click the button again to re-open the tray.
    cy.get('#me-sidebar-toggle-btn').click();

    // The button class should revert to the expanded indicator.
    cy.get('#me-sidebar-toggle-btn').should(
      'have.class',
      'me-button--sidebar-collapse',
    );

    // The CSS var must be restored to the pre-collapse width.
    getCSSVar('--me-dialog-dock-right-width').should('equal', '550px');

    // The collapsed flag must be cleared (either removed or set to 'false').
    cy.window()
      .its('localStorage')
      .invoke('getItem', 'mercury-dialog-dock-collapsed')
      .should((val) => {
        expect(val === null || val === 'false').to.be.true;
      });
  });

  // ---------------------------------------------------------------------------
  // Test 5 – Component-specific width settings are respected.
  // ---------------------------------------------------------------------------
  it('respects component-specific dialog width settings', () => {
    const componentWidth = 700;

    // Inject a component-specific dock width via drush php:eval.
    // The dialog context key for docked component edit forms is
    // 'dock_{paragraph_type}_form', so for me_test_text it is
    // 'dock_me_test_text_form'.
    // Single-quoted shell string keeps $variables literal; PHP uses double quotes.
    const setConfigCmd = Cypress.env('drushCommand').replace(
      '$COMMAND',
      `php:eval '$config = \\Drupal::configFactory()->getEditable("mercury_editor.settings"); $settings = $config->get("dialog_settings") ?? []; $settings["dock_me_test_text_form"] = ["width" => ${componentWidth}]; $config->set("dialog_settings", $settings)->save();' -y`,
    );
    cy.exec(setConfigCmd);
    cy.drush('cr');

    cy.visit('/node/add/me_test_ct');
    cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');

    // Add a me_test_text component. The INSERT form will open (since
    // skip_create_form is not set). Save it to open the EDIT form.
    // The EDIT form uses dialog context 'dock_me_test_text_form' (dock=right),
    // which will have width=700 from the config set above, causing
    // _applySizingVars to set --me-dialog-dock-right-width to 700 px.
    cy.meAddComponent('me_test_text');
    cy.meSaveComponent();

    getCSSVar('--me-dialog-dock-right-width').should('equal', `${componentWidth}px`);

    // Clean up: remove the component-specific setting.
    const cleanupCmd = Cypress.env('drushCommand').replace(
      '$COMMAND',
      `php:eval '$config = \\Drupal::configFactory()->getEditable("mercury_editor.settings"); $settings = $config->get("dialog_settings") ?? []; unset($settings["dock_me_test_text_form"]); $config->set("dialog_settings", $settings)->save();' -y`,
    );
    cy.exec(cleanupCmd);
    cy.drush('cr');
  });
});
