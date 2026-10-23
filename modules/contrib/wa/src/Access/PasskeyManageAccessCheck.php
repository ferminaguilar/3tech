<?php

namespace Drupal\wa\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\User;
use Drupal\wa\WaPermissions;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Checks access for passkey management.
 */
class PasskeyManageAccessCheck implements AccessInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new PasskeyManageAccessCheck object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function access(RouteMatchInterface $route_match, AccountInterface $account): AccessResult {
    $user_param = $route_match->getParameter('user');

    // Only accept User objects loaded via the routing system.
    // Numeric user parameters indicate a routing misconfiguration.
    if (!$user_param instanceof User) {
      return AccessResult::forbidden()->cachePerUser();
    }

    $target_user = $user_param;
    $target_uid = (int) $target_user->id();
    $config = $this->configFactory->get('wa.settings');

    // Anonymous users cannot manage passkeys.
    if (!$account->isAuthenticated()) {
      return AccessResult::forbidden()->cachePerUser();
    }

    // Administrators can always manage passkeys.
    $canAdminister = $account->hasPermission(WaPermissions::ADMINISTER_ALL_USER_PASSKEY);
    if ($canAdminister) {
      return AccessResult::allowed()
        ->addCacheableDependency($config)
        ->addCacheableDependency($target_user)
        ->cachePerPermissions();
    }
    // Global switch.
    if ($config->get('enable_passkey_login') === FALSE) {
      return AccessResult::forbidden()->addCacheableDependency($config);
    }

    // Blocked users cannot view or add passkeys.
    if ($target_user->isBlocked()) {
      return AccessResult::forbidden()
        ->addCacheableDependency($target_user)
        ->cachePerUser();
    }

    // Check allowed roles for the target user.
    $allowed_roles = $config->get('allowed_roles') ?? [];
    // Filter out empty values just in case.
    $allowed_roles = array_filter($allowed_roles);

    // If no roles are configured, passkey management is disabled for regular
    // users.
    if (empty($allowed_roles)) {
      return AccessResult::forbidden()
        ->addCacheableDependency($config)
        ->cachePerUser();
    }

    $target_user_roles = $target_user->getRoles();
    $target_role_allowed = !empty(array_intersect($target_user_roles, $allowed_roles));

    // Owners can manage their own passkeys if their role is allowed.
    if ((int) $account->id() === $target_uid) {
      if (!$target_role_allowed) {
        return AccessResult::forbidden()
          ->addCacheableDependency($config)
          ->cachePerUser()
          ->cachePerPermissions();
      }
      return AccessResult::allowed()
        ->addCacheableDependency($config)
        ->cachePerUser()
        ->cachePerPermissions();
    }
    // All other users are forbidden.
    return AccessResult::forbidden()
      ->addCacheableDependency($config)
      ->cachePerUser()
      ->cachePerPermissions();
  }

}
