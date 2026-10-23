<?php

/**
 * @file
 * Hooks provided by the WA module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * React to passkey login right before finalization.
 *
 * This hook is invoked when a user has successfully verified their passkey
 * and is about to be logged in. Modules can implement this hook to perform
 * additional checks or actions.
 *
 * @param \Drupal\user\UserInterface $user
 *   The user account that is being logged in.
 *
 * @return array[]
 *   An array of associative arrays, each with the following keys:
 *   - allowed_login: (bool) TRUE if the login process should proceed, FALSE
 *     otherwise.
 *   - redirect_url: (string|\Drupal\Core\Url|null) The URL to redirect to.
 *     If allowed_login is FALSE, this URL will be used for redirection.
 *     If allowed_login is TRUE, this URL will still be used to redirect the
 *     user after login if provided.
 */
function hook_wa_login(\Drupal\user\UserInterface $user) {
  $result = [[
    'allowed_login' => TRUE,
    'redirect_url' => NULL,
  ],
  ];

  // Example: Prevent login for non-admin users during maintenance.
  if (!$user->hasPermission('administer site configuration') && \Drupal::state()->get('system.maintenance_mode')) {
    \Drupal::messenger()->addError(t('Site is in maintenance mode.'));
    $result[0]['allowed_login'] = FALSE;
    $result[0]['redirect_url'] = \Drupal\Core\Url::fromRoute('<front>');
  }

  // Example: Redirect to a specific page after login.
  if ($user->hasRole('premium_user')) {
    $result[0]['redirect_url'] = \Drupal\Core\Url::fromRoute('entity.node.canonical', ['node' => 123]);
  }

  return $result;
}

/**
 * @} End of "addtogroup hooks".
 */
