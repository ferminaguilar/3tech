<?php

namespace Drupal\wa\Routing;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\wa\WaPermissions;
use Symfony\Component\Routing\RouteCollection;

/**
 * Forces the administrative permission onto Views routes over the wa table.
 *
 * View-level access settings are editable configuration and therefore one
 * human mistake away from exposing the passkey listing — including its
 * exposed filter widgets, whose user autocomplete would let unprivileged
 * visitors enumerate user names. Every route that renders a view based on
 * the {wa} table therefore additionally requires the
 * 'administer all user passkey' permission, no matter what access plugin
 * the view itself is configured with.
 */
class RouteSubscriber extends RouteSubscriberBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a RouteSubscriber object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    if (!$this->entityTypeManager->hasDefinition('view')) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('view');
    foreach ($collection->all() as $route) {
      $view_id = $route->getDefault('view_id');
      if (!is_string($view_id)) {
        continue;
      }
      $view = $storage->load($view_id);
      if (!$view instanceof ConfigEntityInterface || $view->get('base_table') !== 'wa') {
        continue;
      }
      $permission = WaPermissions::ADMINISTER_ALL_USER_PASSKEY;
      $existing = $route->getRequirement('_permission');
      if ($existing !== NULL && $existing !== $permission) {
        // Core's permission access checker treats comma-separated
        // permissions as AND, so a view-configured permission stays in
        // effect alongside the enforced one.
        $permission = $existing . ',' . $permission;
      }
      $route->setRequirement('_permission', $permission);
    }
  }

}
