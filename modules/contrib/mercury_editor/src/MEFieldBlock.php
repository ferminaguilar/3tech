<?php

namespace Drupal\mercury_editor;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FormatterPluginManager;
use Drupal\Core\Session\AccountInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\layout_builder\Plugin\Block\FieldBlock;

/**
 * Overrides the FieldBlock plugin to support previewing with Mercury Editor.
 *
 * Note that this class extends an @internal class to provide
 * additional functionality specific to the Mercury Editor context.
 *
 * @phpstan-ignore-next-line
 */
class MEFieldBlock extends FieldBlock {

  /**
   * The Mercury Editor context service.
   *
   * @var \Drupal\mercury_editor\MercuryEditorContextService
   */
  protected $mercuryEditorContext;

  /**
   * Constructs a new FieldBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Field\FormatterPluginManager $formatter_manager
   *   The formatter manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   * @param \Drupal\mercury_editor\MercuryEditorContextService $mercury_editor_context
   *   The Mercury Editor context service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityFieldManagerInterface $entity_field_manager, FormatterPluginManager $formatter_manager, ModuleHandlerInterface $module_handler, LoggerInterface $logger, MercuryEditorContextService $mercury_editor_context) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_field_manager, $formatter_manager, $module_handler, $logger);
    $this->mercuryEditorContext = $mercury_editor_context;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_field.manager'),
      $container->get('plugin.manager.field.formatter'),
      $container->get('module_handler'),
      $container->get('logger.channel.layout_builder'),
      $container->get('mercury_editor.context')
    );
  }

  /**
   * {@inheritdoc}
   *
   * Clone of the parent method, but with a check for the Mercury Editor
   * preview route.
   */
  protected function blockAccess(AccountInterface $account) {

    // If the formatter is not layout_paragraphs, defer to the parent method.
    if ('layout_paragraphs' !== $this->getConfiguration()['formatter']['type'] ?? NULL) {
      return parent::blockAccess($account);
    }

    $entity = $this->getEntity();

    // First consult the entity.
    $access = $entity->access('view', $account, TRUE);
    if (!$access->isAllowed()) {
      return $access;
    }

    // Check that the entity in question has this field.
    if (!$entity instanceof FieldableEntityInterface || !$entity->hasField($this->fieldName)) {
      return $access->andIf(AccessResult::forbidden());
    }

    // Check field access.
    $field = $entity->get($this->fieldName);
    $access = $access->andIf($field->access('view', $account, TRUE));
    if (!$access->isAllowed()) {
      return $access;
    }

    // Check to see if the field has any values or a default value.
    // Adds a check for if we are using Mercury Editor.
    $isMercuryEditorContext = $this->mercuryEditorContext->isPreview() || $this->mercuryEditorContext->isEditor();
    if (!$isMercuryEditorContext && $field->isEmpty() && !$field->getFieldDefinition()->getDefaultValue($entity)) {
      // @todo Remove special handling of image fields after
      //   https://www.drupal.org/project/drupal/issues/3005528.
      if ($field->getFieldDefinition()->getType() === 'image' && $field->getFieldDefinition()->getSetting('default_image')) {
        return $access;
      }

      return $access->andIf(AccessResult::forbidden());
    }
    return $access;
  }

}
