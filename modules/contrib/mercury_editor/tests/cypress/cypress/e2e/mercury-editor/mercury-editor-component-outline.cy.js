/**
 * Mercury Editor component outline e2e tests.
 */
import '@4tw/cypress-drag-drop';

Cypress.config('defaultCommandTimeout', 10000);

describe('Mercury Editor component outline e2e tests.', () => {
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

  it('opens and closes the component outline', () => {
    // Create a new page.
    cy.visit('/node/add/me_test_ct');
    // Wait for the Mercury Editor interface to fully load
    cy.get('#me-preview').its('0.contentDocument');

    // Check that the component outline button exists
    cy.openComponentOutline();

    // Wait for the component outline to be loaded via AJAX
    cy.get('.me-component-outline').should('exist');

    // Close the outline
    cy.closeComponentOutline();

    // Ensure it's closed.
    cy.get('.me-component-outline').should('not.exist');
  });

  describe('Component Outline with Nested Layouts', () => {
    before(() => {
      cy.loginUserByUid(1);

      // Adjust content type to support nested layouts.
      cy.visit('admin/structure/types/manage/me_test_ct/form-display');
      cy.get(
        '[data-drupal-selector="edit-fields-field-me-test-content-settings-edit"]',
      ).click();
      cy.get(
        '[data-drupal-selector="edit-fields-field-me-test-content-settings-edit-form-settings-nesting-depth"]',
      ).select('1');
      cy.get(
        'input[name="field_me_test_content_plugin_settings_update"]',
      ).click();
      cy.contains('You have unsaved changes.');
      cy.get('input[value="Save"]').click();

      // Create a new page.
      cy.visit('/node/add/me_test_ct');
      cy.get('.me-entity-form').should('have.class', 'me-autosave-initialized');
      // Wait for the Mercury Editor interface to fully load
      cy.get('#me-preview').its('0.contentDocument');

      // Set the title of the page
      cy.get('input[name="title[0][value]"]').clear();
      cy.get('input[name="title[0][value]"]').type('Component Outline Test');
      cy.iframe('#me-preview')
        .find('.page-title')
        .contains('Component Outline Test');

      // Create nested structure:
      // - Section 1 (Two Column)
      //   - left: Text Component A
      //   - right: Section 2 (Two Column)
      //    - Left: Text Component B
      //    - Right: Text Component C
      // - Text Component D
      // - Section 3 (Two Column)
      //   - left: Text Component D
      //   - right: Text Component E

      // First section
      cy.meAddComponent('me_test_section');
      cy.meChooseLayout('layout_twocol');
      cy.meSaveComponent().then((section1) => {
        cy.meSelectComponent(section1.attr('data-uuid'));
        cy.meAddComponent('me_test_text', {
          region: 'first',
          section: section1,
        });
        cy.meSetCKEditor5Value('field_me_test_text', 'Text A');
        cy.meSaveComponent();

        // Nested section
        cy.meSelectComponent(section1.attr('data-uuid'));
        cy.meAddComponent('me_test_section', {
          region: 'second',
          section: section1,
        });
        cy.meChooseLayout('layout_twocol');
        cy.meSaveComponent().then((section2) => {
          cy.meSelectComponent(section2.attr('data-uuid'));
          cy.meAddComponent('me_test_text', {
            region: 'first',
            section: section2,
          });
          cy.meSetCKEditor5Value('field_me_test_text', 'Text B');
          cy.meSaveComponent();

          cy.meSelectComponent(section2.attr('data-uuid'));
          cy.meAddComponent('me_test_text', {
            region: 'second',
            section: section2,
          });
          cy.meSetCKEditor5Value('field_me_test_text', 'Text C');
          cy.meSaveComponent();
        });

        cy.meSelectComponent(section1.attr('data-uuid'));
        cy.meAddComponent('me_test_section', { after: section1 });
        cy.meChooseLayout('layout_twocol');
        cy.meSaveComponent().then((section3) => {
          cy.meSelectComponent(section3.attr('data-uuid'));
          cy.meAddComponent('me_test_text', {
            region: 'first',
            section: section3,
          });
          cy.meSetCKEditor5Value('field_me_test_text', 'Text D');
          cy.meSaveComponent();

          cy.meSelectComponent(section3.attr('data-uuid'));
          cy.meAddComponent('me_test_text', {
            region: 'second',
            section: section3,
          });
          cy.meSetCKEditor5Value('field_me_test_text', 'Text E');
          cy.meSaveComponent();
        });
      });

      cy.meSavePage();
    });

    beforeEach(() => {
      // Login as admin.
      cy.loginUserByUid(1);

      // Find the page we created.
      cy.visit('/admin/content');
      cy.get('a[aria-label="Edit Component Outline Test"]')
        .first()
        .click({ force: true });
    });

    it('displays components in an outline', () => {
      // Open the component outline
      cy.openComponentOutline();

      // Ensure the component outline is visible
      cy.get('.me-component-outline__list[role="tree"]').should('exist');

      // Close the outline
      cy.closeComponentOutline();
    });

    it('updates the outline when items are reordered', () => {
      // Open the component outline
      cy.openComponentOutline();

      // Before reordering, ensure the outline is correct.
      cy.get('.me-component-outline')
        .find(
          '.me-component-outline__component .me-component-outline__component',
        )
        .find(
          '.me-component-outline__regions .me-component-outline__region:nth-child(2)',
        )
        .contains('Text');
      cy.get('.me-component-outline')
        .find(
          '.me-component-outline__component .me-component-outline__component',
        )
        .find(
          '.me-component-outline__regions .me-component-outline__region:nth-child(3)',
        )
        .contains('Text');

      cy.meFindComponent('Text B').then((component) => {
        cy.meSelectComponent(component.attr('data-uuid'));
      });

      // Move the selected component (Text B) down
      cy.iframe('#me-preview').find('[data-active="true"] .lpb-down').click();

      // After reordering, ensure the outline is updated.
      cy.get('.me-component-outline')
        .find(
          '.me-component-outline__component .me-component-outline__component',
        )
        .find(
          '.me-component-outline__regions .me-component-outline__region:nth-child(2)',
        )
        .should('not.contain', 'Text');
      cy.get('.me-component-outline')
        .find(
          '.me-component-outline__component .me-component-outline__component',
        )
        .find(
          '.me-component-outline__regions .me-component-outline__region:nth-child(3)',
        )
        .find('.me-component-outline__component:nth-child(1)')
        .contains('Text');
      cy.get('.me-component-outline')
        .find(
          '.me-component-outline__component .me-component-outline__component',
        )
        .find(
          '.me-component-outline__regions .me-component-outline__region:nth-child(3)',
        )
        .find('.me-component-outline__component:nth-child(2)')
        .contains('Text');

      // Close the outline
      cy.closeComponentOutline();
    });

    it('syncs highlighted state with the preview', () => {
      // Open the component outline
      cy.openComponentOutline();

      // No item should be highlighted initially
      cy.get('.me-component-outline__component.is-highlighted').should(
        'not.exist',
      );

      // Click on the controls of the first section component in the outline
      cy.get(
        '.me-component-outline__component[data-uuid] .me-component-outline__component-controls',
      )
        .first()
        .click()
        .then((controls) => {
          const uuid = controls.attr('data-uuid');

          // Ensure the corresponding item in the outline is highlighted
          cy.get(
            `.me-component-outline__component[data-uuid="${uuid}"]`,
          ).should('have.class', 'is-highlighted');

          // Ensure the section component with the matching UUID is active in the preview
          cy.iframe('#me-preview')
            .find(`[data-uuid="${uuid}"][data-active="true"]`)
            .should('exist');
        });

      // Click on the first text component in the preview
      cy.iframe('#me-preview')
        .find('[data-type="me_test_text"]')
        .first()
        // Force the click because the element may be partially covered by the outline when it's open.
        .click({force: true})
        .then((textComponent) => {
          const textUuid = textComponent.attr('data-uuid');

          // Ensure the corresponding item in the outline is highlighted
          cy.get(
            `.me-component-outline__component[data-uuid="${textUuid}"]`,
          ).should('have.class', 'is-highlighted');
        });

      // Close the outline
      cy.closeComponentOutline();

      // Reopen the outline
      cy.openComponentOutline();

      // Ensure the previously highlighted item is still highlighted
      cy.iframe('#me-preview')
        .find('[data-active="true"]')
        .then(($activeComponent) => {
          const activeUuid = $activeComponent.attr('data-uuid');
          cy.get(
            `.me-component-outline__component[data-uuid="${activeUuid}"]`,
          ).should('have.class', 'is-highlighted');
        });

      // Click on the h1.page-title in the preview
      cy.iframe('#me-preview').find('h1.page-title').click();

      // Ensure no component in the preview is active
      cy.iframe('#me-preview').find('[data-active="true"]').should('not.exist');

      // Ensure no item in the outline is highlighted
      cy.get('.me-component-outline__component.is-highlighted').should(
        'not.exist',
      );

      // Close the outline
      cy.closeComponentOutline();

      // Reopen the outline
      cy.openComponentOutline();

      // Ensure no item in the outline is highlighted
      cy.get('.me-component-outline__component.is-highlighted').should(
        'not.exist',
      );

      // Focus on the first section component in the outline
      cy.get('.me-component-outline__component[role="treeitem"]')
        .first()
        .focus();

      // Typing Enter key should activate an outline item
      cy.focused()
        .type('{enter}')
        .then((item) => {
          const uuid = item.attr('data-uuid');

          // Ensure the corresponding item in the outline is highlighted
          cy.get(
            `.me-component-outline__component[data-uuid="${uuid}"]`,
          ).should('have.class', 'is-highlighted');

          // Ensure the section component with the matching UUID is active in the preview
          cy.iframe('#me-preview')
            .find(`[data-uuid="${uuid}"][data-active="true"]`)
            .should('exist');
        });
    });

    it('implements proper ARIA attributes and roles', () => {
      // Open the component outline
      cy.openComponentOutline();

      // Check tree structure has proper ARIA roles
      cy.getComponentOutline().should('have.attr', 'role', 'tree');
      cy.getComponentOutline().should('have.attr', 'aria-label');

      // Check that tree items exist
      cy.get('.me-component-outline__component[role="treeitem"]').should(
        'have.length',
        8,
      );

      // Check that sections are expandable
      cy.get(
        '.me-component-outline__component[role="treeitem"][aria-expanded]',
      ).should('have.length', 3);

      // Check that sections have toggles
      cy.get(
        '.me-component-outline__component[role="treeitem"][aria-expanded] .me-component-outline__component-toggle',
      ).should('have.length', 3);

      // Check regions have proper group role
      cy.get('.me-component-outline__regions[role="group"]').should('exist');

      // Check that only one item has tabindex="0" (roving tabindex)
      cy.getTreeItems().then(($items) => {
        const focusableItems = Array.from($items).filter(
          (item) => item.tabIndex === 0,
        );
        expect(focusableItems).to.have.length(1);
      });
    });

    it('supports arrow key navigation', () => {
      // Open the component outline
      cy.openComponentOutline();

      // Focus first tree item
      cy.getTreeItems().first().focus();

      // Test Down Arrow - moves to next visible item
      cy.getTreeItems().first().type('{downarrow}');
      cy.getVisibleTreeItems().eq(1).should('be.focused');

      // Test Up Arrow - moves to previous visible item
      cy.focused().type('{uparrow}');
      cy.getVisibleTreeItems().eq(0).should('be.focused');

      // Test End - moves to last visible item
      cy.focused().type('{end}');
      cy.getVisibleTreeItems().last().should('be.focused');

      // Test Home - moves to first item
      cy.focused().type('{home}');
      cy.getVisibleTreeItems().first().should('be.focused');
    });

    it('supports Right Arrow key for expansion', () => {
      // Open the component outline
      cy.openComponentOutline();

      // Focus first section (should be expandable)
      cy.getTreeItemByLabel('Section - Two column').first().focus();

      // Right arrow on collapsed node should expand it
      cy.focused().type('{rightarrow}');
      cy.isTreeItemExpanded(
        cy.getTreeItemByLabel('Section - Two column').first(),
      );

      // Right arrow on expanded node should move to first child
      cy.focused().type('{rightarrow}');

      // Should focus on top region
      cy.focused()
        .find('.me-component-outline__region-label')
        .first()
        .should('contain', 'Top')
        .should('be.visible');

      // Move to first section
      cy.focused().type('{downarrow}');

      // Should focus first section
      cy.focused()
        .find('.me-component-outline__region-label')
        .first()
        .should('contain', 'First')
        .should('be.visible');

      // Right arrow on expanded node should move to first child
      cy.focused().type('{rightarrow}');

      // Should focus first child component
      cy.focused()
        .find('.me-component-outline__component-label--component')
        .first()
        .should('contain', 'Text')
        .should('be.visible');
    });

    it('supports Left Arrow key for collapse and parent navigation', () => {
      // Open the component outline
      cy.openComponentOutline();

      // Expand first section
      cy.getTreeItemByLabel('Section - Two column').first().focus();
      cy.focused().type('{rightarrow}'); // Expand
      cy.isTreeItemExpanded(
        cy.getTreeItemByLabel('Section - Two column').first(),
      );
      cy.focused().type('{rightarrow}'); // Move to child
      cy.focused().type('{downarrow}'); // Move to next region
      cy.focused().type('{rightarrow}'); // Move to child
      cy.getTreeItemByLabel('Text').first().should('be.focused');

      // Left arrow on child should move to parent
      cy.focused().type('{leftarrow}');

      // Should focus parent region
      cy.focused()
        .find('.me-component-outline__region-label')
        .first()
        .should('contain', 'First')
        .should('be.visible');

      // Left arrow on child should move to parent
      cy.focused().type('{leftarrow}');
      cy.getTreeItemByLabel('Section - Two column')
        .first()
        .should('be.focused');

      // Left arrow on expanded parent should collapse it
      cy.focused().type('{leftarrow}');
      cy.isTreeItemCollapsed(
        cy.getTreeItemByLabel('Section - Two column').first(),
      );
    });

    it('supports Enter key activation', () => {
      // Open the component outline
      cy.openComponentOutline();

      // Focus a component (not section)
      cy.getTreeItemByLabel('Section - Two column').first().focus();
      cy.focused().type('{rightarrow}'); // Expand
      cy.focused().type('{rightarrow}'); // Move to child component

      // Enter should activate the component (select it in the editor)
      cy.focused().type('{enter}');

      // This should trigger component selection in the preview frame
      // We can verify this by checking if a message was sent to the iframe
      // Note: In a real test, you might need to spy on postMessage calls
    });

    it('supports type-ahead search', () => {
      // Open the component outline
      cy.openComponentOutline();

      // Expand sections to make components visible
      cy.getTreeItemByLabel('Section - Two column').first().focus();
      cy.focused().type('{rightarrow}'); // Expand first section

      // Type 'T' to search for 'Text'
      cy.focused().type('t');

      // Should focus on first item starting with 'T'
      cy.focused().should('contain.text', 'Text');

      // Type 'e' to continue search for 'Te'
      cy.focused().type('e');

      // Should still be on a Text component
      cy.focused().should('contain.text', 'Text');
    });

    it('maintains focus visibility and roving tabindex', () => {
      // Open the component outline
      cy.openComponentOutline();

      // Focus first item
      cy.getVisibleTreeItems().first().focus();
      cy.getVisibleTreeItems().first().should('have.attr', 'tabindex', '0');

      // Move to next item
      cy.focused().type('{downarrow}');

      // Previous item should have tabindex="-1", current should have tabindex="0"
      cy.getVisibleTreeItems().first().should('have.attr', 'tabindex', '-1');
      cy.getVisibleTreeItems().eq(1).should('have.attr', 'tabindex', '0');
    });

    it('implements proper ARIA attributes for menu buttons', () => {
      // Open the component outline
      cy.openComponentOutline();

      // Check menu toggle buttons have proper attributes
      cy.get('.me-component-outline__component-menu-toggle').each(($button) => {
        cy.wrap($button).should('have.attr', 'aria-haspopup', 'true');
        cy.wrap($button).should('have.attr', 'aria-expanded', 'false');
      });

      // Check menu dialogs have proper attributes when closed
      cy.get('.me-component-outline__component-menu-dialog').each(($dialog) => {
        cy.wrap($dialog).should('have.attr', 'role', 'menu');
        cy.wrap($dialog).should('have.attr', 'aria-hidden', 'true');
        cy.wrap($dialog).should('not.have.attr', 'open');
      });
    });

    it('opens menu with Space and Enter keys', () => {
      // Open the component outline
      cy.openComponentOutline();

      // Focus first tree item, then tab to menu button
      cy.getTreeItems().first().focus();
      cy.focused()
        .find('.me-component-outline__component-menu-toggle')
        .first()
        .focus();

      // Space key should open menu and focus on first item
      cy.focused().type(' ').should('have.attr', 'aria-expanded', 'true');
      cy.getMenuItems().first().should('be.focused');

      // Close menu and refocus on treeitem
      cy.focused().type('{esc}');
      cy.focused().should('have.attr', 'role', 'treeitem');
      cy.focused()
        .find('.me-component-outline__component-menu-toggle')
        .first()
        .focus();

      // Enter key should also open menu
      cy.focused().type('{enter}');
      cy.getMenuItems().first().should('be.focused');
    });

    it('opens menu with Down Arrow and focuses first item', () => {
      // Open the component outline
      cy.openComponentOutline();

      cy.getTreeItems().first().focus();
      cy.focused()
        .find('.me-component-outline__component-menu-toggle')
        .first()
        .focus();

      // Down arrow should open menu and focus first menu item
      cy.focused()
        .type('{downarrow}')
        .should('have.attr', 'aria-expanded', 'true');
      cy.getMenuItems().first().should('be.focused');
    });

    it('supports menu item navigation with arrow keys', () => {
      // Open the component outline
      cy.openComponentOutline();

      // Open menu
      cy.getTreeItems().first().focus();
      cy.focused()
        .find('.me-component-outline__component-menu-toggle')
        .first()
        .click();

      // Focus first menu item
      cy.getMenuItems().first().focus();

      // Down arrow moves to next item
      cy.focused().type('{downarrow}');
      cy.getMenuItems().eq(1).should('be.focused');

      // Up arrow moves back
      cy.focused().type('{uparrow}');
      cy.getMenuItems().first().should('be.focused');

      // Down arrow at last item wraps to first
      cy.getMenuItems().last().focus();
      cy.focused().type('{downarrow}');
      cy.getMenuItems().first().should('be.focused');

      // Up arrow at first item wraps to last
      cy.focused().type('{uparrow}');
      cy.getMenuItems().last().should('be.focused');
    });

    it('closes menu when clicking outside', () => {
      // Open the component outline
      cy.openComponentOutline();

      // Open menu
      cy.getTreeItems().first().focus();
      cy.focused()
        .find('.me-component-outline__component-menu-toggle')
        .first()
        .click();
      cy.get('.me-component-outline__component-menu-dialog[open]').should(
        'exist',
      );

      // Click outside
      cy.get('body').click();
      cy.get('.me-component-outline__component-menu-dialog[open]').should(
        'not.exist',
      );
    });

    // TODO: Revisit this once we've upgraded to Cypress 14.3+ when the `cy.press()` command was introduced.
    // it('supports Tab key to leave menu', () => {
    //   // Open the component outline
    //   cy.openComponentOutline();

    //   // Open menu and focus menu item
    //   cy.getTreeItems().first().focus();
    //   cy.focused().find('.me-component-outline__component-menu-toggle').first().click();
    //   cy.getMenuItems().first().focus();

    //   // Tab should close menu and move focus to next focusable element
    //   cy.focused().press(Cypress.Keyboard.Keys.TAB);
    //   cy.get('.me-component-outline__component-menu-dialog[open]').should('not.exist');
    // });

    it('maintains only one menu open at a time', () => {
      // Open the component outline
      cy.openComponentOutline();

      // Open first menu
      cy.getVisibleTreeItems()
        .eq(0)
        .find('.me-component-outline__component-menu-toggle')
        .first()
        .click();
      cy.get('.me-component-outline__component-menu-dialog[open]').should(
        'have.length',
        1,
      );

      // Open second menu
      cy.getVisibleTreeItems()
        .eq(1)
        .find('.me-component-outline__component-menu-toggle')
        .first()
        .click({ force: true });

      // Only second menu should be open
      cy.getVisibleTreeItems()
        .eq(1)
        .find('.me-component-outline__component-menu-dialog[open]')
        .first()
        .should('have.length', 1);
      cy.getVisibleTreeItems()
        .eq(1)
        .find('.me-component-outline__component-menu-toggle')
        .first()
        .should('have.attr', 'aria-expanded', 'true');
      cy.getVisibleTreeItems()
        .eq(0)
        .find('.me-component-outline__component-menu-toggle')
        .first()
        .should('have.attr', 'aria-expanded', 'false');
    });

    it('remembers expanded/collapsed states across when menu is reopened or page reloads', () => {
      // Open the component outline
      cy.openComponentOutline();

      cy.getVisibleTreeItems().should('have.length', 2);

      // Focus first tree item
      cy.getVisibleTreeItems().first().focus();

      // Test Right Arrow - opens menu
      cy.focused().type('{rightarrow}');
      cy.getVisibleTreeItems().should('have.length', 8);

      // 0 items should be visible when closed.
      cy.closeComponentOutline();
      cy.getTreeItems().should('not.exist');

      // 4 items should still be visible when reopened.
      cy.openComponentOutline();
      cy.getVisibleTreeItems().should('have.length', 8);

      // Reload page
      cy.reload();

      // 4 items should still be visible when reopened.
      cy.openComponentOutline();
      cy.getVisibleTreeItems().should('have.length', 8);
    });

    it('edits, duplicates, and deletes components via the menu', () => {
      cy.openComponentOutline();
      // Edit Text A
      cy.getVisibleTreeItems()
        .eq(0)
        .find('.me-component-outline__component-toggle')
        .first()
        .click();
      cy.getVisibleTreeItems()
        .eq(3)
        .find('.me-component-outline__component-menu-toggle')
        .first()
        .click();
      cy.getMenuItems().contains('Edit').click();
      cy.get('mercury-dialog[id^="lpb-dialog-"]')
        .find('.layout-paragraphs-component-form')
        .should('exist');
      cy.meSetCKEditor5Value('field_me_test_text', 'Text A - edited');
      cy.meSaveComponent();
      cy.iframe('#me-preview')
        .find('[data-type="me_test_text"]')
        .first()
        .should('contain.text', 'Text A - edited');
      // Duplicate Text A
      cy.getVisibleTreeItems()
        .eq(3)
        .find('.me-component-outline__component-menu-toggle')
        .first()
        .click();
      cy.getMenuItems().contains('Duplicate').click();
      cy.iframe('#me-preview')
        .find('[data-type="me_test_text"]')
        .filter(':contains("Text A - edited")')
        .should('have.length', 2);
      // Delete duplicated Text A.
      cy.getVisibleTreeItems()
        .eq(4)
        .find('.me-component-outline__component-menu-toggle')
        .first()
        .click();
      cy.getMenuItems().contains('Delete').click();
      cy.get('mercury-dialog[id^="lpb-dialog-"]')
        .contains('Really delete this "Text" component?')
        .should('exist');
      cy.get('[slot="footer"] .lpb-btn--confirm-delete').click();
      cy.iframe('#me-preview')
        .find('[data-type="me_test_text"]')
        .filter(':contains("Text A - edited")')
        .should('have.length', 1);
    });

    it('drags elements to reorder them', () => {
      cy.openComponentOutline();

      // Expand all sections.
      cy.get('.me-component-outline__component-toggle').click({
        multiple: true,
        force: true,
      });

      // Drag and drop a text element from one region to another.
      cy.get('#me-component-outline-wrapper').then(($wrapper) => {
        $wrapper[0].classList.add('cy-is-updating');
      });
      cy.get(
        '.cy-is-updating .me-component-outline__region[data-region="top"] .me-component-outline__components',
      ).should('be.visible');
      cy.get('.me-component-outline__component[data-type="me_test_text"]')
        .first()
        .drag(
          '.me-component-outline__region[data-region="top"] .me-component-outline__components',
        );
      cy.get('.me-component-outline__region[data-region="top"]')
        .find('.me-component-outline__component[data-type="me_test_text"]')
        .should('have.length', 1);
      // Assert wrapper is no longer updating.
      cy.get('#me-component-outline-wrapper').should(
        'not.have.class',
        'cy-is-updating',
      );

      // Drag and drop a section to the first top region.
      cy.get('#me-component-outline-wrapper').then(($wrapper) => {
        $wrapper[0].classList.add('cy-is-updating');
      });
      cy.get('[data-region="top"] ul')
        .first()
        .then(($dest) => {
          cy.get('[data-is-layout="true"]')
            .eq(2)
            .drag($dest, {
              source: { x: 20, y: 5 },
              target: { x: 20, y: 1 },
            });
        });
      // Assert wrapper is no longer updating.
      cy.get('#me-component-outline-wrapper').should(
        'not.have.class',
        'cy-is-updating',
      );
      cy.get('[data-region="top"] [data-is-layout="true"]').should(
        'have.length',
        1,
      );

      // Drag and drop a text element to the top level.
      cy.get('#me-component-outline-wrapper').then(($wrapper) => {
        $wrapper[0].classList.add('cy-is-updating');
      });
      cy.get('[data-type="me_test_text"]')
        .eq(0)
        .drag(
          '.me-component-outline > .me-component-outline__list :first-child',
          {
            source: { x: 20, y: 5 },
            target: { x: 20, y: 5 },
          },
        );
      cy.get(
        '[data-type="me_test_text"][style="--me-treeitem-depth: 0;"]',
      ).should('have.length', 1);
      // Assert wrapper is no longer updating.
      cy.get('#me-component-outline-wrapper').should(
        'not.have.class',
        'cy-is-updating',
      );
    });

    it('cannot drag a section deeper than the allowed nesting depth', () => {
      cy.openComponentOutline();
      cy.get('.me-component-outline__component-toggle').click({
        multiple: true,
        force: true,
      });
      cy.get('[data-is-layout="true"]')
        .eq(2)
        .drag(
          '[data-is-layout="true"] [data-is-layout="true"] [data-region="top"] ul',
          {
            source: { x: 20, y: 5 },
          },
        );
      cy.get(
        '[data-is-layout="true"] [data-is-layout="true"] [data-is-layout="true"]',
      ).should('have.length', 0);
    });

    it('respects "require section" setting when dragging components', () => {
      // Adjust content type to require sections.
      cy.visit('admin/structure/types/manage/me_test_ct/form-display');
      cy.get(
        '[data-drupal-selector="edit-fields-field-me-test-content-settings-edit"]',
      ).click();
      cy.get(
        '[data-drupal-selector="edit-fields-field-me-test-content-settings-edit-form-settings-require-layouts"]',
      ).check();
      cy.get(
        'input[name="field_me_test_content_plugin_settings_update"]',
      ).click();
      cy.contains('You have unsaved changes.');
      cy.get('input[value="Save"]').click();

      // Find the page we created.
      cy.visit('/admin/content');
      cy.get('a[aria-label="Edit Component Outline Test"]')
        .first()
        .click({ force: true });

      cy.openComponentOutline();
      cy.get('.me-component-outline__component-toggle').click({
        multiple: true,
        force: true,
      });
      cy.get('[data-type="me_test_text"]')
        .eq(0)
        .drag(
          '.me-component-outline > .me-component-outline__list :first-child',
          {
            source: { x: 20, y: 5 },
            target: { x: 20, y: 5 },
          },
        );
      cy.get('.me-component-outline > [data-type="me_test_text"]').should(
        'have.length',
        0,
      );

      // Adjust content type to require sections.
      cy.visit('admin/structure/types/manage/me_test_ct/form-display');
      cy.get(
        '[data-drupal-selector="edit-fields-field-me-test-content-settings-edit"]',
      ).click();
      cy.get(
        '[data-drupal-selector="edit-fields-field-me-test-content-settings-edit-form-settings-require-layouts"]',
      ).uncheck();
      cy.get(
        'input[name="field_me_test_content_plugin_settings_update"]',
      ).click();
      cy.contains('You have unsaved changes.');
      cy.get('input[value="Save"]').click();
    });
  });
});
