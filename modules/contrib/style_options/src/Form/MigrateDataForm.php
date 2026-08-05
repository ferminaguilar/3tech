<?php

namespace Drupal\style_options\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Migrate data class.
 */
class MigrateDataForm extends FormBase {

  /**
   * {@inheritDoc}
   */
  public function getFormId() {
    return 'style_options_migrate_data';
  }

  /**
   * {@inheritDoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['message'] = [
      '#markup' => $this->t('Use this form to migration data from the Option Plugin module paragraph behavior.'),
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Migrate'),
      '#submit' => [[$this, 'submitForm']],
    ];
    return $form;
  }

  /**
   * {@inheritDoc}
   */
  public function submitForm(&$form, FormStateInterface $form_state) {
    style_options_migrate_option_plugin_paragraph_behavior();
    $this->messenger()->addStatus($this->t('Finished migrating data.'));
  }

}
