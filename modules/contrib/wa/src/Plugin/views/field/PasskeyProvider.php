<?php

namespace Drupal\wa\Plugin\views\field;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\wa\Service\PasskeyLabeler;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays a human-friendly passkey provider label.
 *
 * @ViewsField("wa_passkey_provider")
 */
class PasskeyProvider extends FieldPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Additional fields required from the query.
   *
   * @var string[]
   */
  public $additionalFields = ['aaguid', 'transports'];

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
   * Constructs a passkey provider field plugin.
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
  }

  /**
   * {@inheritdoc}
   *
   * @return string|\Drupal\Component\Render\MarkupInterface
   *   The rendered output.
   */
  public function render($values) {
    $record = (object) [
      'aaguid' => $this->getFieldValueSafe($values, 'aaguid') ?? '',
      'transports' => $this->getFieldValueSafe($values, 'transports'),
    ];

    return $this->passkeyLabeler->getKeyTypeFromRecord($record);
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    // Only load the supporting fields we need; no real column for provider.
    $this->addAdditionalFields($this->additionalFields);
  }

  /**
   * Safely fetches an additional field value from the row.
   *
   * @param \Drupal\views\ResultRow $values
   *   The result row.
   * @param string $field
   *   The field alias.
   *
   * @return mixed
   *   The field value or NULL if not found.
   */
  protected function getFieldValueSafe($values, string $field) {
    $alias = $this->aliases[$field] ?? NULL;
    if ($alias && isset($values->{$alias})) {
      return $values->{$alias};
    }

    $fallback = $this->tableAlias . '_' . $field;
    if (isset($values->{$fallback})) {
      return $values->{$fallback};
    }

    return $values->{$field} ?? NULL;
  }

}
