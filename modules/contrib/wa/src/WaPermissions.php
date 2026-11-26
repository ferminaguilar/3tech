<?php

namespace Drupal\wa;

/**
 * Permission names used by the wa module.
 */
final class WaPermissions {

  /**
   * Grants administrative access to view and delete any user's passkeys.
   *
   * The machine name is defined in wa.permissions.yml and referenced
   * literally in the bundled wa_passkeys view; those YAML usages must stay
   * in sync with this constant.
   */
  const ADMINISTER_ALL_USER_PASSKEY = 'administer all user passkey';

  /**
   * Grants access to configure site-wide passkey and Email OTP settings.
   *
   * The machine name is defined in wa.permissions.yml and referenced
   * literally in wa.routing.yml and wa_email_otp.routing.yml; those YAML
   * usages must stay in sync with this constant.
   */
  const ADMINISTER_PASSKEY_SETTINGS = 'administer passkey settings';

}
