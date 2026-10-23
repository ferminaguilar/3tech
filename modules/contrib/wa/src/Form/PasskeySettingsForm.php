<?php

namespace Drupal\wa\Form;

use Drupal\user\RoleInterface;
use Drupal\user\Entity\Role;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for WA module.
 */
class PasskeySettingsForm extends ConfigFormBase {

  /**
   * The labeler service.
   *
   * @var \Drupal\wa\Service\PasskeyLabeler
   */
  protected $passkeyLabeler;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    /** @var static $instance */
    $instance = parent::create($container);
    $instance->passkeyLabeler = $container->get('wa.passkey_labeler');
    $instance->moduleHandler = $container->get('module_handler');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'wa_settings_form';
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string>
   *   An array of configuration object names.
   */
  protected function getEditableConfigNames(): array {
    return ['wa.settings'];
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array<string, mixed>
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('wa.settings');
    $allowed = $config->get('allowed_aaguids') ?? [];
    $customLabels = $config->get('custom_aaguid_labels') ?? [];
    $enablePasskeyLogin = $config->get('enable_passkey_login') ?? TRUE;
    $allowedRoles = $config->get('allowed_roles') ?? [];
    $notificationSubject = $config->get('passkey_add_notification_subject') ?? 'Security Alert: A new passkey was added to your account';
    $notificationBody = $config->get('passkey_add_notification_body') ?? "Dear [user:name],\n\nA new passkey has been added to your account. The authenticator used was [wa:key:authenticator].\n\nIf you did not perform this action, please contact the site administrator immediately.";
    $loginFieldsetTitle = $config->get('login_fieldset_title') ?? $this->t('Other sign in options');
    $loginFieldsetDescription = $config->get('login_fieldset_description') ?? '';

    // Known provider options keyed by AAGUID.
    $providerOptions = $this->passkeyLabeler->getProviderOptions();
    $knownAaguids = array_keys($providerOptions);

    $allowedKnown = array_values(array_intersect($allowed, $knownAaguids));
    $allowedCustom = array_values(array_diff($allowed, $knownAaguids));
    $allowedCustomWithLabels = array_map(function ($aaguid) use ($customLabels) {
      $label = $customLabels[$aaguid] ?? '';
      return $label !== '' ? $aaguid . '|' . $label : $aaguid;
    }, $allowedCustom);

    $form['enable_passkey_login'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Passkey Login'),
      '#default_value' => $enablePasskeyLogin,
      '#description' => $this->t('If unchecked, the "Sign in with Passkey" button will be hidden and login/registration endpoints will be disabled.'),
    ];

    $roles = Role::loadMultiple();
    $roleOptions = [];
    foreach ($roles as $role) {
      // Filter out anonymous.
      if ($role->id() !== RoleInterface::ANONYMOUS_ID) {
        $roleOptions[$role->id()] = $role->label();
      }
    }

    $form['allowed_roles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Allowed Roles'),
      '#options' => $roleOptions,
      '#default_value' => $allowedRoles,
      '#description' => $this->t('Select which user roles are allowed to use passkeys. If no roles are selected, no one can use passkeys.'),
    ];

    $form['allowed_known'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Allowed authenticators'),
      '#options' => $providerOptions,
      '#default_value' => $allowedKnown,
      '#description' => $this->t('Select which known authenticators (by AAGUID) you allow for registration and login.'),
    ];

    $form['allowed_custom'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Additional AAGUIDs'),
      '#default_value' => implode("\n", $allowedCustomWithLabels),
      '#description' => $this->t('Enter one AAGUID per line (lowercase, hex with dashes). Optionally add a label after a pipe, e.g. 12345678-1234-1234-1234-1234567890ab|My Authenticator.'),
    ];

    $form['login_display'] = [
      '#type' => 'details',
      '#title' => $this->t('Login Form Display'),
      '#open' => TRUE,
    ];

    $form['login_display']['login_fieldset_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Login Fieldset Title'),
      '#default_value' => $loginFieldsetTitle,
      '#description' => $this->t('The title of the fieldset containing the login button.'),
    ];

    $form['login_display']['login_fieldset_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Login Fieldset Description'),
      '#default_value' => $loginFieldsetDescription,
      '#description' => $this->t('The description displayed within the fieldset.'),
    ];

    $form['security_options'] = [
      '#type' => 'details',
      '#title' => $this->t('Security Options'),
      '#description' => $this->t('Configure advanced security settings for passkey authentication.'),
      '#open' => FALSE,
    ];

    $form['security_options']['user_verification'] = [
      '#type' => 'radios',
      '#title' => $this->t('User Verification Requirement'),
      '#default_value' => $config->get('user_verification') ?? 'preferred',
      '#options' => [
        'preferred' => $this->t('Preferred (Allows user presence only)'),
        'required' => $this->t('Required (Enforces PIN/Biometrics)'),
      ],
      '#description' => $this->t('Choose "Required" for higher security, or "Preferred" for better compatibility.'),
      '#required' => TRUE,
    ];

    $form['security_options']['resident_key'] = [
      '#type' => 'radios',
      '#title' => $this->t('Resident Key Requirement'),
      '#default_value' => $config->get('resident_key') ?? 'preferred',
      '#options' => [
        'discouraged' => $this->t('Discouraged (Server stores key handle)'),
        'preferred' => $this->t('Preferred (Attempt to create resident key)'),
        'required' => $this->t('Required (Force resident key - needed for usernameless login)'),
      ],
      '#description' => $this->t('Controls whether the authenticator must create a client-side discoverable credential (resident key). "Required" is necessary for the "Sign in with Passkey" button (usernameless login) to work. "Discouraged" saves storage space on hardware keys but requires entering a username first.'),
      '#required' => TRUE,
    ];

    $form['security_options']['enforce_user_handle'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enforce User Handle Validation'),
      '#default_value' => $config->get('enforce_user_handle') ?? FALSE,
      '#description' => $this->t('If checked, login requests must include the User Handle (User ID) from the authenticator. This hardens security but may block older keys or non-resident credentials.'),
    ];

    $form['notification_options'] = [
      '#type' => 'details',
      '#title' => $this->t('Notification Options'),
      '#description' => $this->t('Configure email notifications sent to users.'),
      '#open' => FALSE,
    ];

    $form['notification_options']['passkey_add_notification_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Passkey Added Notification Subject'),
      '#default_value' => $notificationSubject,
      '#description' => $this->t('Subject of the email sent when a new passkey is added.'),
    ];

    $form['notification_options']['passkey_add_notification_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Passkey Added Notification Body'),
      '#default_value' => $notificationBody,
      '#description' => $this->t('Body of the email sent when a new passkey is added. Supports tokens like [user:name] and [wa:key:authenticator].'),
    ];

    if ($this->moduleHandler->moduleExists('token')) {
      $form['notification_options']['token_tree'] = [
        '#theme' => 'token_tree_link',
        '#token_types' => ['user', 'wa'],
        '#global_types' => TRUE,
        '#click_insert' => TRUE,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   *
   * @param array<mixed> $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $customRaw = $form_state->getValue('allowed_custom') ?? '';
    $customLines = array_map('trim', preg_split('/\r\n|\r|\n/', $customRaw));
    $customLines = array_filter($customLines, static function ($value) {
      return $value !== '';
    });

    $invalid = [];
    $parsedCustom = [];
    foreach ($customLines as $line) {
      [$aaguid, $label] = array_pad(explode('|', $line, 2), 2, '');
      $aaguid = strtolower(trim($aaguid));
      $label = trim($label);

      if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $aaguid)) {
        $invalid[] = $line;
        continue;
      }

      $parsedCustom[] = ['aaguid' => $aaguid, 'label' => $label];
    }

    if (!empty($invalid)) {
      $form_state->setErrorByName('allowed_custom', $this->t('Invalid AAGUID(s): @list', ['@list' => implode(', ', $invalid)]));
    }
    else {
      $form_state->setValue('parsed_custom', $parsedCustom);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $allowedKnown = array_filter($form_state->getValue('allowed_known') ?? []);
    $allowedRoles = array_filter($form_state->getValue('allowed_roles') ?? []);
    $enablePasskeyLogin = $form_state->getValue('enable_passkey_login');
    $enforceUserHandle = $form_state->getValue('enforce_user_handle');
    $userVerification = $form_state->getValue('user_verification');
    $residentKey = $form_state->getValue('resident_key');
    $notificationSubject = $form_state->getValue('passkey_add_notification_subject');
    $notificationBody = $form_state->getValue('passkey_add_notification_body');
    $loginFieldsetTitle = $form_state->getValue('login_fieldset_title');
    $loginFieldsetDescription = $form_state->getValue('login_fieldset_description');

    $parsedCustom = $form_state->getValue('parsed_custom') ?? [];

    $customAaguids = array_map(static function ($item) {
      return $item['aaguid'];
    }, $parsedCustom);

    $merged = array_merge($allowedKnown, $customAaguids);
    $merged = array_map(static function ($value) {
      return strtolower($value);
    }, $merged);
    $merged = array_values(array_unique(array_filter($merged, static function ($value) {
      return $value !== '';
    })));

    $customLabels = [];
    foreach ($parsedCustom as $pair) {
      if ($pair['label'] !== '') {
        $customLabels[$pair['aaguid']] = $pair['label'];
      }
    }

    $this->configFactory->getEditable('wa.settings')
      ->set('allowed_aaguids', $merged)
      ->set('custom_aaguid_labels', $customLabels)
      ->set('enable_passkey_login', $enablePasskeyLogin)
      ->set('enforce_user_handle', $enforceUserHandle)
      ->set('user_verification', $userVerification)
      ->set('resident_key', $residentKey)
      ->set('allowed_roles', $allowedRoles)
      ->set('passkey_add_notification_subject', $notificationSubject)
      ->set('passkey_add_notification_body', $notificationBody)
      ->set('login_fieldset_title', $loginFieldsetTitle)
      ->set('login_fieldset_description', $loginFieldsetDescription)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
