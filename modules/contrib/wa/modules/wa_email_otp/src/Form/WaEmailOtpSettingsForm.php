<?php

namespace Drupal\wa_email_otp\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure WA Email OTP settings for this site.
 */
class WaEmailOtpSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'wa_email_otp_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['wa_email_otp.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('wa_email_otp.settings');

    $form['enable_otp'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Email OTP'),
      '#default_value' => $config->get('enable_otp') ?? TRUE,
      '#description' => $this->t('When turned off, users will login directly via passkey without needing an email OTP.'),
    ];

    $form['otp_email_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OTP Email Subject'),
      '#default_value' => $config->get('otp_email_subject') ?: 'Your One-Time Password for Login',
      '#description' => $this->t('The subject line for the OTP email. Available placeholders: @site'),
    ];

    $form['otp_email_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('OTP Email Body'),
      '#default_value' => $config->get('otp_email_body') ?: "Use the following One-Time Password to complete your login:\n\n@otp\n\nThis code is valid for 60 seconds.",
      '#description' => $this->t('The body text for the OTP email. Available placeholders: @otp, @site'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('wa_email_otp.settings')
      ->set('enable_otp', (bool) $form_state->getValue('enable_otp'))
      ->set('otp_email_subject', $form_state->getValue('otp_email_subject'))
      ->set('otp_email_body', $form_state->getValue('otp_email_body'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
