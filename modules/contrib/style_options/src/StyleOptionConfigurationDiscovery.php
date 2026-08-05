<?php

declare(strict_types=1);

namespace Drupal\style_options;

use Drupal\Core\Discovery\YamlDiscovery;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Define option plugin configuration factory.
 */
class StyleOptionConfigurationDiscovery {

  /**
   * Define the YAML configuration name.
   */
  protected const CONFIG_NAME = 'style_options';

  /**
   * Cached configuration items.
   *
   * @var array
   *   An array of cached configurations.
   */
  protected $items = [];

  /**
   * Module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Theme handler service.
   *
   * @var \Drupal\Core\Extension\ThemeHandlerInterface
   */
  protected $themeHandler;

  /**
   * Define the option plugin configuration factory constructor.
   */
  public function __construct(
    ThemeHandlerInterface $theme_handler,
    ModuleHandlerInterface $module_handler
  ) {
    $this->themeHandler = $theme_handler;
    $this->moduleHandler = $module_handler;
  }

  /**
   * Get option plugin configuration instances.
   *
   * @return \Drupal\style_options\OptionPluginConfiguration[]
   *   An array of the plugin configuration objects.
   */
  public function getInstances(): array {
    $instances = [];

    foreach ($this->load() as $provider => $definition) {
      $instances[$provider] = $definition;
    }

    return $instances;
  }

  /**
   * Returns the merged configuration definitions.
   *
   * @return array
   *   An array of plugin configuration definitions.
   */
  public function getDefinitions(): array {
    $definitions = [];

    foreach ($this->getInstances() as $instance) {
      $definitions = NestedArray::mergeDeep($definitions, $instance);
    }

    return $definitions;
  }

  /**
   * Returns an array of all options.
   *
   * @return array
   *   An array of option definitions.
   */
  public function getOptionDefinitions() {
    return $this->getDefinitions()['options'];
  }

  /**
   * Returns a single option definition.
   *
   * @param string $id
   *   The option id.
   * @param mixed $default_value
   *   The default value.
   *
   * @return array
   *   The option definition array.
   */
  public function getOptionDefinition($id, $default_value = '') {
    $definition = $this->getOptionDefinitions()[$id] ?? [];
    if (empty($definition)) {
      return [];
    }
    if ($default_value) {
      $definition['default'] = $default_value;
    }
    $definition['option_id'] = $id;
    return $definition;
  }

  /**
   * Returns an array of all context definitions.
   *
   * @return array
   *   The array of context definitions.
   */
  public function getContextDefinitions() {
    return $this->getDefinitions()['contexts'] ?? [];
  }

  /**
   * Returns the default settings for a provided context.
   *
   * @param string $context
   *   The context (i.e. layout or paragraph)
   *
   * @return array
   *   The default settings.
   */
  public function getContextDefaults(string $context) {
    return $this->getContextDefinitions()[$context]['_defaults'] ?? [];
  }

  /**
   * Returns a single context definition.
   *
   * @param string $context
   *   The context (i.e. layout or paragraph)
   * @param string $context_id
   *   The context id (i.e. layout id or paragraph bundle id)
   *
   * @return array
   *   The context definition.
   */
  public function getContextDefinition(string $context, string $context_id) {
    return $this->getContextDefinitions()[$context][$context_id] ?? [];
  }

  /**
   * Returns a processed context definition.
   *
   * @todo Allow other modules to alter the definition.
   *
   * @param string $context
   *   The context.
   * @param string $context_id
   *   The context id.
   *
   * @return array
   *   The processed definition.
   */
  public function getProcessedContextDefinition(string $context, string $context_id) {
    $defaults = $this->getContextDefaults($context);
    $definitions = $this->getContextDefinition($context, $context_id);
    $merged_settings = NestedArray::mergeDeep($defaults, $definitions);
    foreach ($merged_settings['_disable'] ?? [] as $disable_option) {
      if (isset($merged_settings['options'][$disable_option])) {
        unset($merged_settings['options'][$disable_option]);
      }
    }
    return $merged_settings;
  }

  /**
   * Returns the options settings for a given context.
   *
   * @param string $context
   *   The context.
   * @param string $context_id
   *   The context id.
   *
   * @return array
   *   The options settings.
   */
  public function getContextOptions(string $context, string $context_id) {
    return $this->getProcessedContextDefinition($context, $context_id)['options'] ?? [];
  }

  /**
   * Load the option plugin configuration.
   *
   * @return array
   *   An array of the plugin configuration keyed by provider.
   */
  protected function load(): array {
    if (!$this->items) {
      $this->items = $this->discovery()->findAll();
    }

    return $this->items;
  }

  /**
   * Get the configuration discovery instance.
   *
   * @return \Drupal\Core\Discovery\YamlDiscovery
   *   The discovery object.
   */
  protected function discovery(): YamlDiscovery {
    return new YamlDiscovery(static::CONFIG_NAME, NestedArray::mergeDeep(
      $this->moduleHandler->getModuleDirectories(),
      $this->themeHandler->getThemeDirectories())
    );
  }

}
