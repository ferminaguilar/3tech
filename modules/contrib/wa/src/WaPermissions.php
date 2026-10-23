<?php

namespace Drupal\wa;

/**
 * Permission names used by the wa module.
 */
final class WaPermissions {

  /**
   * Grants full administrative access to every user's passkeys.
   *
   * The machine name is defined in wa.permissions.yml and referenced
   * literally in wa.routing.yml, wa_email_otp.routing.yml and the bundled
   * wa_passkeys view; those YAML usages must stay in sync with this
   * constant.
   */
  const ADMINISTER_ALL_USER_PASSKEY = 'administer all user passkey';

}
