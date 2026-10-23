<?php

namespace Drupal\wa\Plugin\views\filter;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\wa\Service\PasskeyLabeler;
use Drupal\views\Plugin\views\filter\InOperator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Filters passkeys by provider label (mapped from AAGUID).
 *
 * @ViewsFilter("wa_passkey_provider")
 */
class PasskeyProvider extends InOperator implements ContainerFactoryPluginInterface {

  /**
   * The value options.
   *
   * @var array<string, string>
   */
  protected $valueOptions;

  /**
   * Passkey labeler service.
   *
   * @var \Drupal\wa\Service\PasskeyLabeler
   */
  protected $passkeyLabeler;

  /**
   * {@inheritdoc}
   */

  /**
   * {@inheritdoc}
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('wa.passkey_labeler')
    );
  }

  /**
   * Constructs the filter plugin.
   *
   * @param array<string, mixed> $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\wa\Service\PasskeyLabeler $passkey_labeler
   *   The passkey labeler service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, PasskeyLabeler $passkey_labeler) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->passkeyLabeler = $passkey_labeler;
    $this->valueTitle = $this->t('Provider');
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The default options for this filter.
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['exposed'] = ['default' => TRUE];
    $options['expose'] = [
      'default' => [
        'operator_id' => 'provider_op',
        'label' => $this->t('Provider'),
        'identifier' => 'provider',
        'required' => FALSE,
        'multiple' => TRUE,
        'reduce' => FALSE,
        'use_operator' => FALSE,
      ],
    ];
    return $options;
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, string>
   *   An array of value options.
   */
  public function getValueOptions() {
    if ($this->valueOptions === NULL) {
      $this->valueOptions = $this->passkeyLabeler->getProviderOptions();
    }
    return $this->valueOptions;
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    $selected = array_filter((array) $this->value, static function ($v) {
      return (string) $v !== '';
    });
    if (!$selected) {
      return;
    }

    $this->ensureMyTable();
    /** @var \Drupal\views\Plugin\views\query\Sql $query */
    $query = $this->query;
    $query->addWhere(
      $this->options['group'],
      "$this->tableAlias.aaguid",
      $selected,
      'IN'
    );
  }

}
