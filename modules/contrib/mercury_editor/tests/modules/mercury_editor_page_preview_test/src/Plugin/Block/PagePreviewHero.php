<?php

namespace Drupal\mercury_editor_page_preview_test\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a hero outside the article, using the current route's node.
 *
 * @Block(
 *   id = "mercury_editor_page_preview_hero",
 *   admin_label = @Translation("Mercury Editor preview hero"),
 * )
 */
class PagePreviewHero extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the test block.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected RouteMatchInterface $routeMatch) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('current_route_match'));
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $node = $this->routeMatch->getParameter('node');
    if (!$node instanceof ContentEntityInterface) {
      return [];
    }
    return [
      '#type' => 'html_tag',
      '#tag' => 'section',
      '#attributes' => ['id' => 'me-page-preview-hero'],
      'title' => ['#plain_text' => $node->label()],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return ['route'];
  }

}
