<?php

declare(strict_types=1);

namespace Drupal\style_options\Plugin;

use Drupal\Core\Render\Renderer;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\Ajax\AjaxHelperTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\style_options\Contracts\StyleOptionPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Define the attribute option plugin base.
 */
abstract class StyleOptionPluginBase extends PluginBase implements StyleOptionPluginInterface, ContainerFactoryPluginInterface {

  use AjaxHelperTrait;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\Renderer
   */
  protected $renderer;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('renderer'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Class constructor.
   *
   * @param array $configuration
   *   The configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param array $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Render\Renderer $renderer
   *   The renderer service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(array $configuration, string $plugin_id, array $plugin_definition, Renderer $renderer, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->renderer = $renderer;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Stores submitted value.
   *
   * @var array
   */
  protected $values = [];

  /**
   * {@inheritDoc}
   */
  public function setValue($key, $value) {
    $this->values[$key] = $value;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function setValues($values) {
    $this->values = $values;
    return $this;
  }

  /**
   * {@inheritDoc}
   */
  public function getValue($key) {
    return $this->values[$key] ?? NULL;
  }

  /**
   * {@inheritDoc}
   */
  public function getValues() {
    return $this->values;
  }

  /**
   * Formats a single value.
   *
   * @param string $value
   *   The value to format.
   *
   * @return mixed
   *   The formatted value.
   */
  public function formatValue($value) {
    return $value;
  }

  /**
   * {@inheritDoc}
   */
  public function defaultConfiguration(): array {
    return [];
  }

  /**
   * {@inheritDoc}
   */
  public function getLabel() {
    return $this->getConfiguration()['label'] ?? $this->getPluginDefinition()['label'];
  }

  /**
   * {@inheritDoc}
   */
  public function validateConfigurationForm(
    array &$form,
    FormStateInterface $form_state
  ): void {}

  /**
   * {@inheritDoc}
   */
  public function submitConfigurationForm(
    array &$form,
    FormStateInterface $form_state
  ): void {
    $values = $form_state->cleanValues()->getValues();
    $this->setValues($values);
  }

  /**
   * {@inheritDoc}
   */
  public function setConfiguration(array $configuration): void {
    $this->configuration = NestedArray::mergeDeep(
      $this->getConfiguration(),
      $this->defaultConfiguration(),
      $configuration
    );
  }

  /**
   * {@inheritDoc}
   */
  public function getConfiguration($key = '') {
    $configuration = $this->configuration + $this->defaultConfiguration();
    if (empty($key)) {
      return $configuration;
    }
    else {
      return $configuration[$key] ?? NULL;
    }
  }

  /**
   * Check if a configuration exist.
   *
   * @param string $name
   *   The configuration property name.
   *
   * @return bool
   *   Return TRUE if configuration exist; otherwise FALSE.
   */
  public function hasConfiguration(string $name): bool {
    return isset($this->configuration[$name]) && !empty($this->configuration[$name]);
  }

  /**
   * Get the attribute option default value.
   *
   * @return mixed
   *   The attribute option default value.
   */
  protected function getDefaultValue() {
    return $this->getConfiguration('default');
  }

  /**
   * {@inheritDoc}
   */
  public function getOptionId() {
    return $this->getConfiguration('option_id');
  }

}
