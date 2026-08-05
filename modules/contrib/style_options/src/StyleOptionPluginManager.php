<?php

declare(strict_types=1);

namespace Drupal\style_options;

use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\style_options\Contracts\StyleOptionPluginInterface;
use Drupal\style_options\Contracts\StyleOptionPluginManagerInterface;
use Drupal\style_options\Annotation\StyleOption;

/**
 * Define the attribute option manager.
 */
class StyleOptionPluginManager extends DefaultPluginManager implements StyleOptionPluginManagerInterface {

  /**
   * Module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Define the attribute option manager constructor.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   The cache backend.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler
  ) {
    parent::__construct(
      'Plugin/StyleOption',
      $namespaces,
      $module_handler,
      StyleOptionPluginInterface::class,
      StyleOption::class
    );
    $this->alterInfo('style_options');
    $this->setCacheBackend($cache_backend, 'style_options');
  }

}
