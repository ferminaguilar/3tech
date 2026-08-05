<?php

namespace Drupal\mercury_editor\Routing;

use Symfony\Component\Routing\RouteCollection;
use Drupal\Core\Routing\RouteSubscriberBase;

/**
 * Alters the reorder route for Layout Paragraphs.
 */
class ReorderRouteSubscriber extends RouteSubscriberBase {

  /**
   * Alters the Layout Paragraphs reorder route to use the ME controller.
   *
   * @param \Symfony\Component\Routing\RouteCollection $collection
   *   The collection of routes to alter.
   */
  protected function alterRoutes(RouteCollection $collection) {
    if ($route = $collection->get('layout_paragraphs.builder.reorder')) {
      $route->setDefault('_controller', '\Drupal\mercury_editor\Controller\ReorderController::build');
    }
  }

}
