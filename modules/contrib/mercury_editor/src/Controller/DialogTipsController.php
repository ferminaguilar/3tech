<?php

namespace Drupal\mercury_editor\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Controller routines for dialog tips routes.
 */
class DialogTipsController extends ControllerBase {

  /**
   * Displays a page with dialog settings tips.
   *
   * @return array
   *   A renderable array.
   */
  public function tips() {
    return $this->getDialogTipsContent();
  }

  /**
   * Gets the dialog tips content.
   *
   * @return array
   *   The dialog tips content as a render array.
   */
  protected function getDialogTipsContent() {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['mercury-editor-dialog-tips']],
      'intro' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Mercury Editor uses YAML format to configure modal dialog settings. You can customize dialog behavior using the paragraph edit form ID as the primary key ([paragraph_type]_form).'),
      ],
      'structure' => [
        '#type' => 'details',
        '#title' => $this->t('Configuration Structure'),
        '#open' => TRUE,
        'content' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => '<p>' . $this->t('The configuration follows a hierarchical structure:') . '</p>
<ul>
<li><strong>_defaults:</strong> ' . $this->t('Global default settings applied to all dialogs') . '</li>
<li><strong>_dialog_defaults:</strong> ' . $this->t('Default settings for modal dialogs (not dock/tray)') . '</li>
<li><strong>_dock_defaults:</strong> ' . $this->t('Default settings for dock/tray dialogs') . '</li>
<li><strong>[component_type_id]_form:</strong> ' . $this->t('Specific settings for individual component types') . '</li>
<li><strong>dialog_[component_type_id]_form:</strong> ' . $this->t('Dialog-specific overrides for component types') . '</li>
<li><strong>dock_[component_type_id]_form:</strong> ' . $this->t('Dock-specific overrides for component types') . '</li>
<li><strong>component_menu:</strong> ' . $this->t('Settings for the component menu dialog.') . '</li>
</ul>',
        ],
      ],
      'properties' => [
        '#type' => 'details',
        '#title' => $this->t('Available Properties'),
        '#open' => TRUE,
        'content' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => '<p>' . $this->t('Common dialog properties you can configure:') . '</p>
<dl>
<dt><strong>width</strong></dt><dd>' . $this->t('Dialog width (e.g., 500px, min-content, max-content)') . '</dd>
<dt><strong>height</strong></dt><dd>' . $this->t('Dialog height (e.g., 400px, min-content, max-content)') . '</dd>
<dt><strong>resizable</strong></dt><dd>' . $this->t('Whether dialog can be resized (true/false)') . '</dd>
<dt><strong>moveable</strong></dt><dd>' . $this->t('Whether dialog can be moved (true/false)') . '</dd>
<dt><strong>dialogClass</strong></dt><dd>' . $this->t('CSS class to add to the dialog') . '</dd>
<dt><strong>drupalAutoButtons</strong></dt><dd>' . $this->t('Whether to show Drupal auto-generated buttons (true/false)') . '</dd>
</dl>',
        ],
      ],
      'examples' => [
        '#type' => 'details',
        '#title' => $this->t('Example Configuration'),
        '#open' => TRUE,
        'content' => [
          '#type' => 'html_tag',
          '#tag' => 'pre',
          '#attributes' => ['style' => 'background: #f5f5f5; padding: 15px; border: 1px solid #ddd; overflow-x: auto;'],
          '#value' => '_defaults:
  width: min-content
  height: min-content
  drupalAutoButtons: false
  dialogClass: lpb-dialog
  resizable: true
  moveable: true

# Use the "_dialog" prefix to denote settings for dialogs (versus dock/tray).
_dialog_defaults:
  dialogClass: mercury-dialog
  resizable: false

# Use the "_dock" prefix for dock/tray specific defaults.
_dock_defaults:
  moveable: false
  resizable: false

# Settings for component menu dialog
component_menu:
  width: min-content

# Settings for section forms
section_form:
  width: max-content
  height: 500px
  dialogClass: section-dialog

# Settings for text forms
text_form:
  width: 500px
  height: 220px
  resizable: true

# Dialog-specific override for text forms
dialog_text_form:
  dialogClass: text-form-dialog
  moveable: true

# Dock-specific override for section forms
dock_section_form:
  width: 400px
  height: 300px',
        ],
      ],
      'tips' => [
        '#type' => 'details',
        '#title' => $this->t('Tips and Best Practices'),
        '#open' => FALSE,
        'content' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => '<ul>
<li>' . $this->t('Use <code>min-content</code> for width to fit content exactly') . '</li>
<li>' . $this->t('Use <code>max-content</code> for width to prevent text wrapping') . '</li>
<li>' . $this->t('Set <code>resizable: true</code> to allow users to resize dialogs') . '</li>
<li>' . $this->t('Set <code>moveable: true</code> to allow users to drag dialogs') . '</li>
<li>' . $this->t('Use <code>dialogClass</code> to apply custom styling') . '</li>
<li>' . $this->t('Set <code>drupalAutoButtons: false</code> to hide default Drupal buttons') . '</li>
<li>' . $this->t('The component menu automatically defaults to <code>width: fit-content</code> if no width is set') . '</li>
<li>' . $this->t('Settings cascade from defaults to specific overrides') . '</li>
<li>' . $this->t('Dock dialogs are positioned relative to the editor interface') . '</li>
</ul>',
        ],
      ],
    ];
  }

}
