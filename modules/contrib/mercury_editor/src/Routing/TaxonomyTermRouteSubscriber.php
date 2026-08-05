<?php

namespace Drupal\mercury_editor\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Adds dynamic taxonomy_vocabulary parameter to Taxonomy Term add route.
 */
class TaxonomyTermRouteSubscriber extends RouteSubscriberBase {

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {

    // Alter taxonomy terms routes.
    if ($route = $collection->get('entity.taxonomy_term.add_form')) {
      $route->setDefaults([
        '_entity_form' => 'taxonomy_term.default',
        '_title' => 'Add term',
      ]);
      $route->setOptions([
        'parameters' => [
          'taxonomy_vocabulary' => [
            'type' => 'entity:taxonomy_vocabulary',
            'with_config_overrides' => TRUE,
          ],
        ],
      ]);
    }
  }

}
