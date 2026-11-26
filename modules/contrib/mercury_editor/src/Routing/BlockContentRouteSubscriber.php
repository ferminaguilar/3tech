<?php

namespace Drupal\mercury_editor\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Integrates the content block creation controller with Mercury Editor.
 */
class BlockContentRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    if ($route = $collection->get('block_content.add_form')) {
      $route->setDefault('_controller', '\Drupal\mercury_editor\Controller\MercuryEditorBlockContentFormController::addForm');
    }
  }

}
