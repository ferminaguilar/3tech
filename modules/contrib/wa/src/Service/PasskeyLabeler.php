<?php

namespace Drupal\wa\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Provides human-friendly labels for passkey authenticators.
 */
class PasskeyLabeler {

  use StringTranslationTrait;

  /**
   * Known providers keyed by machine name.
   *
   * @var array<string, array<string, array<int, string>|string>>
   */
  protected $providers;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Cached allowed AAGUIDs.
   *
   * @var string[]|null
   */
  protected $allowedAaguids;

  /**
   * Constructs the labeler service.
   */
  public function __construct(TranslationInterface $translation, ConfigFactoryInterface $config_factory) {
    $this->stringTranslation = $translation;
    $this->configFactory = $config_factory;
    $this->providers = $this->buildProviderMap();
  }

  /**
   * Returns a provider label based on a stored credential record.
   *
   * @param object $record
   *   Database record with at least the aaguid and transports fields.
   *
   * @return string
   *   The human-friendly provider label.
   */
  public function getKeyTypeFromRecord($record): string {
    $aaguid = strtolower(trim((string) ($record->aaguid ?? '')));

    foreach ($this->providers as $provider) {
      if (in_array($aaguid, $provider['aaguids'], TRUE)) {
        return (string) $provider['label'];
      }
    }

    $transports = [];
    if (!empty($record->transports)) {
      $decoded = json_decode($record->transports, TRUE);
      if (is_array($decoded)) {
        $transports = $decoded;
      }
    }

    if (in_array('internal', $transports, TRUE)) {
      return (string) $this->t('Password Manager');
    }

    if ($aaguid && $aaguid !== '00000000-0000-0000-0000-000000000000') {
      return (string) $this->t('Security Key');
    }

    return (string) $this->t('Unknown');
  }

  /**
   * Builds the known provider map.
   *
   * @return array<string, array<string, mixed>>
   *   An array of provider definitions keyed by machine name.
   */
  protected function buildProviderMap(): array {
    $providers = [
      'generic' => [
        'aaguids' => ['00000000-0000-0000-0000-000000000000'],
        'label' => $this->t('Generic / Software Authenticator (e.g. Firefox, Chrome, iCloud)'),
      ],
      'google_password_manager' => [
        'aaguids' => ['ea9b8d66-4d01-1d21-3ce4-b6b48cb575d4'],
        'label' => $this->t('Google Password Manager'),
      ],
      'apple_passkeys' => [
        'aaguids' => [
          'fbfc3007-154e-4ecc-8c0b-6e020557d7bd',
          'dd4ec289-e01d-41c9-bb89-70fa845d4bf2',
        ],
        'label' => $this->t('iCloud Keychain (Apple Passkeys)'),
      ],
      'windows_hello_tpm' => [
        'aaguids' => ['08987058-cadc-4b81-b6e1-30de50dcbe96'],
        'label' => $this->t('Windows Hello (TPM-backed)'),
      ],
      'windows_hello_software' => [
        'aaguids' => ['6028b017-b1d4-4c02-b4b3-afcdafc96bb2'],
        'label' => $this->t('Windows Hello (Software-backed)'),
      ],
      'microsoft_authenticator_android' => [
        'aaguids' => ['de1e552d-db1d-4423-a619-566b625cdc84'],
        'label' => $this->t('Microsoft Authenticator for Android'),
      ],
      'microsoft_authenticator_ios' => [
        'aaguids' => ['90a3ccdf-635c-4729-a248-9b709135078f'],
        'label' => $this->t('Microsoft Authenticator for iOS'),
      ],
      'chrome_mac' => [
        'aaguids' => ['adce0002-35bc-c60a-648b-0b25f1f05503'],
        'label' => $this->t('Chrome on Mac'),
      ],
      'onepassword' => [
        'aaguids' => ['bada5566-a7aa-401f-bd96-45619a55120d'],
        'label' => $this->t('1Password'),
      ],
      'bitwarden' => [
        'aaguids' => ['d548826e-79b4-db40-a3d8-11116f7e8349'],
        'label' => $this->t('Bitwarden'),
      ],
      'samsung_pass' => [
        'aaguids' => ['53414d53-554e-4700-0000-000000000000'],
        'label' => $this->t('Samsung Pass'),
      ],
      'proton_pass' => [
        'aaguids' => ['50726f74-6f6e-5061-7373-50726f746f6e'],
        'label' => $this->t('Proton Pass'),
      ],
      'yubikey_5_nfc' => [
        'aaguids' => ['2fc0579f-8113-47ea-b116-bb5a8db9202a'],
        'label' => $this->t('YubiKey 5 Series with NFC (Firmware 5.2+)'),
      ],
      'yubikey_5_usb' => [
        'aaguids' => ['ee882879-721c-4913-9775-3dfcce97072a'],
        'label' => $this->t('YubiKey 5 Series (USB-A, C, Nano - Firmware 5.2+)'),
      ],
      'yubico_security_key_nfc' => [
        'aaguids' => ['149a2021-8ef6-4133-96b8-81f8d5b7f1f5'],
        'label' => $this->t('Security Key by Yubico with NFC (Firmware 5.2+)'),
      ],
      'feitian_biopass' => [
        'aaguids' => ['77010bd7-212a-4fc9-b236-d2ca5e9d4084'],
        'label' => $this->t('Feitian BioPass FIDO2 Authenticator'),
      ],
      'feitian_epass' => [
        'aaguids' => ['833b721a-ff5f-4d00-bb2e-bdda3ec01e29'],
        'label' => $this->t('Feitian ePass FIDO Authenticator'),
      ],
    ];

    $customLabels = $this->configFactory->get('wa.settings')->get('custom_aaguid_labels') ?? [];
    if (is_array($customLabels)) {
      foreach ($customLabels as $aaguid => $label) {
        $aaguidKey = strtolower(trim((string) $aaguid));
        if ($aaguidKey === '') {
          continue;
        }
        $providers['custom_' . preg_replace('/[^a-z0-9]+/', '_', $aaguidKey)] = [
          'aaguids' => [$aaguidKey],
          'label' => Html::escape((string) $label),
        ];
      }
    }

    return $providers;
  }

  /**
   * Returns provider options keyed by AAGUID for filtering.
   *
   * @return array<string, string>
   *   Map of aaguid => label.
   */
  public function getProviderOptions(): array {
    $options = [];
    foreach ($this->providers as $provider) {
      foreach ($provider['aaguids'] as $aaguid) {
        $options[$aaguid] = (string) $provider['label'];
      }
    }
    ksort($options);
    return $options;
  }

  /**
   * Returns the configured, lowercased list of allowed AAGUIDs.
   *
   * @return array<string>
   *   An array of allowed AAGUIDs.
   */
  public function getAllowedAaguids(): array {
    if ($this->allowedAaguids !== NULL) {
      return $this->allowedAaguids;
    }

    $config = $this->configFactory->get('wa.settings');
    $aaguids = $config->get('allowed_aaguids') ?? [];
    $aaguids = array_map(static function ($value) {
      return strtolower(trim((string) $value));
    }, is_array($aaguids) ? $aaguids : []);
    $aaguids = array_values(array_unique(array_filter($aaguids, static function ($value) {
      return $value !== '';
    })));

    $this->allowedAaguids = $aaguids;
    return $this->allowedAaguids;
  }

  /**
   * Checks whether a given AAGUID is allowed.
   *
   * @param string $aaguid
   *   The AAGUID to check.
   *
   * @return bool
   *   TRUE if allowed, FALSE otherwise.
   */
  public function isAaguidAllowed(string $aaguid): bool {
    return in_array(strtolower($aaguid), $this->getAllowedAaguids(), TRUE);
  }

  /**
   * Returns the label for a given AAGUID.
   *
   * @param string $aaguid
   *   The AAGUID to look up.
   *
   * @return string
   *   The label, or 'Unknown' if not found.
   */
  public function getLabel(string $aaguid): string {
    $aaguid = strtolower(trim($aaguid));
    foreach ($this->providers as $provider) {
      if (in_array($aaguid, $provider['aaguids'], TRUE)) {
        return (string) $provider['label'];
      }
    }
    return (string) $this->t('Unknown');
  }

}
