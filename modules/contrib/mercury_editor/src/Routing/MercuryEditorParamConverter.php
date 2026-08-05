<?php

namespace Drupal\mercury_editor\Routing;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Symfony\Component\Routing\Route;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\ParamConverter\ParamConverterInterface;
use Drupal\mercury_editor\MercuryEditorTempstore;

/**
 * Mercury editor param converter service.
 *
 * @internal
 *   Tagged services are internal.
 */
class MercuryEditorParamConverter implements ParamConverterInterface {

  /**
   * Constructs a new LayoutParagraphsEditorTempstoreParamConverter.
   *
   * @param \Drupal\layout_paragraphs\MercuryEditorTempstore $tempstore
   *   The layout tempstore repository.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager service.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   The entity repository service.
   */
  public function __construct(
    protected MercuryEditorTempstore $tempstore,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected EntityRepositoryInterface $entityRepository,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults) {
    if (empty($defaults[$name])) {
      return NULL;
    }

    $uuid = $defaults[$name];

    // If the entity doesn't exist, attempt to load it from tempstore.
    // This is necessary for entities that are being created and not yet saved.
    if ($entity = $this->tempstore->get($uuid)) {
      return $entity;
    }

    // Attempt to load the entity from the entity repository.
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type => $definition) {
      if (!method_exists($definition->getOriginalClass(), 'hasField')) {
        continue;
      }
      $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type, $entity_type);
      if (isset($field_definitions['uuid'])) {
        if ($entity = $this->entityRepository->loadEntityByUuid($entity_type, $uuid)) {
          $this->tempstore->set($entity);
          return $entity;
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route) {
    if (!empty($definition['type']) && $definition['type'] == 'mercury_editor_entity') {
      return TRUE;
    }
    if (isset($definition['mercury_editor_entity'])) {
      return TRUE;
    }
    return FALSE;
  }

}
