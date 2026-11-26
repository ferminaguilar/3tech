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
 * and is about to be logged in (and hard vetoes are re-evaluated after step-up
 * challenges such as Email OTP complete).
 *
 * Both a single associative array (`['allowed_login' => FALSE, ...]`) and a
 * list of associative arrays (`[['allowed_login' => FALSE, ...]]`) are
 * accepted. Any non-array or malformed entry fails closed (denies login).
 *
 * Hard policy vetoes (`allowed_login !== TRUE` without `step_up => TRUE`)
 * always take precedence over step-up challenges (`step_up => TRUE`)
 * regardless of module weight or execution order.
 *
 * @param \Drupal\user\UserInterface $user
 *   The user account that is being logged in.
 *
 * @return array<string, mixed>|array<int, array<string, mixed>>
 *   An associative array (or list of associative arrays) with keys:
 *   - allowed_login: (bool) TRUE if login may proceed, FALSE to block or
 *     redirect to a step-up challenge.
 *   - redirect_url: (string|\Drupal\Core\Url|null) Local redirect URL.
 *   - step_up: (bool, optional) Set TRUE for interactive step-up flows (such
 *     as Email OTP) so hard policy vetoes from other modules always take
 *     precedence.
 *   - step_up_callback: (callable, optional) When `step_up` is TRUE, a
 *     `callable(UserInterface): ?(Url|string)` invoked only if no module
 *     hard-vetoed the login, avoiding side effects (like emailing OTP codes)
 *     on denied logins.
 */
function hook_wa_login(\Drupal\user\UserInterface $user) {
  // Example 1: Hard policy veto during maintenance mode.
  if (!$user->hasPermission('administer site configuration') && \Drupal::state()->get('system.maintenance_mode')) {
    \Drupal::messenger()->addError(t('Site is in maintenance mode.'));
    return [
      'allowed_login' => FALSE,
      'redirect_url' => \Drupal\Core\Url::fromRoute('<front>'),
    ];
  }

  // Example 2: Redirect to a specific page after login.
  if ($user->hasRole('premium_user')) {
    return [
      'allowed_login' => TRUE,
      'redirect_url' => \Drupal\Core\Url::fromRoute('entity.node.canonical', ['node' => 123]),
    ];
  }

  return [];
}

/**
 * @} End of "addtogroup hooks".
 */
