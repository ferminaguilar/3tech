<?php

namespace Drupal\mercury_editor\Routing;

use Symfony\Component\Routing\Route;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Provides routes for Mercury Editor preview.
 */
class MercuryEditorPreviewRoutes {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new MercuryEditorPreviewRoutes.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Provides the preview routes.
   */
  public function routes() {
    $routes = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type => $definition) {
      if (!$definition->getFormClass('mercury_editor')) {
        continue;
      }
      if ($entity_type == 'block_content') {
        $route = '/block-content/{block_content}/mercury-editor-preview';
        $controller = '\Drupal\mercury_editor\Controller\MercuryEditorBlockContentController::preview';
      }
      else {
        $route = $definition->getLinkTemplate('canonical') . '/mercury-editor-preview';
        $controller = '\Drupal\mercury_editor\Controller\MercuryEditorController::preview';
      }
      $routes['entity.' . $entity_type . '.mercury_editor_preview'] = new Route(
        $route,
        [
          '_controller' => $controller,
        ],
        [
          '_custom_access' => '\Drupal\mercury_editor\Controller\MercuryEditorController::access',
        ],
        [
          'parameters' => [
            $entity_type => [
              'type' => 'entity:' . $entity_type,
              'mercury_editor_entity' => TRUE,
            ],
          ],
          '_hide_admin_toolbar' => 'TRUE',
          'no_cache' => 'TRUE',
        ]
      );
    }
    return $routes;
  }

}
