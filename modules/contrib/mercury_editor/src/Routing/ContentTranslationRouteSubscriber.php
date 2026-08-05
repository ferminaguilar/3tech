<?php

namespace Drupal\mercury_editor\Routing;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RoutingEvents;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Alters content translation routes to use alternate controller.
 */
class ContentTranslationRouteSubscriber extends RouteSubscriberBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a ContentTranslationRouteSubscriber object.
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
  public static function getSubscribedEvents(): array {
    // Needs to run after content translation route subscriber.
    $events[RoutingEvents::ALTER] = ['onAlterRoutes', -220];
    return $events;
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {

    // Alter translation routes.
    $entity_type_bundles = $this->configFactory->get('mercury_editor.settings')->get('bundles') ?? [];
    foreach (array_keys($entity_type_bundles) as $entity_type_id) {
      if ($route = $collection->get("entity.$entity_type_id.content_translation_add")) {
        $defaults = $route->getDefaults();
        $defaults['_controller'] = '\Drupal\mercury_editor\Controller\MercuryEditorContentTranslationController::add';
        $route->setDefaults($defaults);
      }
    }
  }

}
