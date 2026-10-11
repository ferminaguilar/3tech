<?php

namespace Drupal\remove_reset_password\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure settings for Reset Password Form.
 */
class ResetPasswordSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'reset_password_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'remove_reset_password.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('remove_reset_password.settings');

    $form['remove_reset_password_button'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Remove "Reset your password" from local tab for anonymous user.'),
      '#default_value' => $config->get('remove_reset_password_button') ?? FALSE,

    ];
    $form['remove_all_local_tabs'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Remove all local tab for anonymous user.'),
      '#default_value' => $config->get('remove_all_local_tabs') ?? FALSE,

    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('remove_reset_password.settings');
    $config->set('remove_reset_password_button', $form_state->getValue('remove_reset_password_button'));
    $config->set('remove_all_local_tabs', $form_state->getValue('remove_all_local_tabs'));

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
