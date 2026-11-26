<?php

namespace Drupal\wa\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\User;
use Drupal\wa\WaPermissions;

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
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection|null
   */
  protected ?Connection $database;

  /**
   * Constructs a new PasskeyManageAccessCheck object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Database\Connection|null $database
   *   The database connection.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ?Connection $database = NULL) {
    $this->configFactory = $config_factory;
    $this->database = $database;
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

    // Administrators can always view and delete any user's passkeys.
    if ($account->hasPermission(WaPermissions::ADMINISTER_ALL_USER_PASSKEY)) {
      return AccessResult::allowed()
        ->cachePerPermissions();
    }

    // Global kill switch (fail-closed: anything other than truthy denies).
    if (!(bool) $config->get('enable_passkey_login')) {
      return AccessResult::forbidden()
        ->addCacheableDependency($config)
        ->cachePerPermissions();
    }

    // Blocked users cannot manage passkeys.
    if ($target_user->isBlocked()) {
      return AccessResult::forbidden()
        ->addCacheableDependency($config)
        ->addCacheableDependency($target_user)
        ->cachePerPermissions();
    }

    // Non-owners without admin permission are forbidden.
    if ((int) $account->id() !== $target_uid) {
      return AccessResult::forbidden()
        ->addCacheableDependency($config)
        ->addCacheableDependency($target_user)
        ->cachePerUser()
        ->cachePerPermissions();
    }

    // Check allowed roles for the owner.
    $allowed_roles = $config->get('allowed_roles') ?? [];
    $allowed_roles = array_filter(is_array($allowed_roles) ? $allowed_roles : []);
    $target_user_roles = $target_user->getRoles();
    $target_role_allowed = !empty($allowed_roles)
      && !empty(array_intersect($target_user_roles, $allowed_roles));

    if ($target_role_allowed) {
      return AccessResult::allowed()
        ->addCacheableDependency($config)
        ->addCacheableDependency($target_user)
        ->cachePerUser()
        ->cachePerPermissions();
    }

    // Allow owners whose role left the allow-list to still view and delete
    // existing passkeys so credentials are never stranded on their account.
    $hasStoredPasskeys = $this->userHasStoredPasskeys($target_uid);
    $result = $hasStoredPasskeys
      ? AccessResult::allowed()
      : AccessResult::forbidden();

    return $result
      ->addCacheableDependency($config)
      ->addCacheableDependency($target_user)
      ->addCacheTags(['wa_user_passkeys:' . $target_uid])
      ->cachePerUser()
      ->cachePerPermissions();
  }

  /**
   * Checks whether a user has any stored passkeys in the wa table.
   *
   * @param int $uid
   *   The user ID.
   *
   * @return bool
   *   TRUE if the user owns at least one stored passkey, FALSE otherwise.
   */
  protected function userHasStoredPasskeys(int $uid): bool {
    if ($this->database === NULL || $uid <= 0) {
      return FALSE;
    }

    $exists = $this->database->select('wa', 'c')
      ->fields('c', ['id'])
      ->condition('uid', $uid)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return $exists !== FALSE;
  }

}
