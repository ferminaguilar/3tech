<?php

namespace Drupal\mercury_editor\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\mercury_editor\MercuryEditorTempstore;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\content_translation\ContentTranslationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\content_translation\ContentTranslationManagerInterface;
use Drupal\content_translation\Controller\ContentTranslationController;

/**
 * Launches Mercury Editor for relevant entity types.
 */
class MercuryEditorContentTranslationController extends ContentTranslationController {

  /**
   * The Mercury Editor Edit Tray tempstore service.
   *
   * @var \Drupal\mercury_editor\MercuryEditorTempstore
   */
  protected $tempstore;

  /**
   * Initializes a content translation controller.
   *
   * @param \Drupal\content_translation\ContentTranslationManagerInterface $manager
   *   A content translation manager instance.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   *   The time service.
   * @param \Drupal\mercury_editor\MercuryEditorTempstore $tempstore
   *   The Mercury Editor tempstore service.
   */
  public function __construct(
    ContentTranslationManagerInterface $manager,
    EntityFieldManagerInterface $entity_field_manager,
    TimeInterface $time,
    MercuryEditorTempstore $tempstore,
  ) {
    parent::__construct(
      $manager,
      $entity_field_manager,
      $time
    );
    $this->tempstore = $tempstore;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('content_translation.manager'),
      $container->get('entity_field.manager'),
      $container->get('datetime.time'),
      $container->get('mercury_editor.tempstore_repository')
    );
  }

  /**
   * {@inheritDoc}
   */
  public function add(LanguageInterface $source, LanguageInterface $target, RouteMatchInterface $route_match, $entity_type_id = NULL) {
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $route_match->getParameter($entity_type_id);

    $bundles = $this->config('mercury_editor.settings')->get('bundles');
    if (empty($bundles[$entity->getEntityTypeId()][$entity->bundle()])) {
      return parent::add($source, $target, $route_match, $entity_type_id);
    }

    // In case of a pending revision, make sure we load the latest
    // translation-affecting revision for the source language, otherwise the
    // initial form values may not be up-to-date.
    if (!$entity->isDefaultRevision() && ContentTranslationManager::isPendingRevisionSupportEnabled($entity_type_id, $entity->bundle())) {
      /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
      $storage = $this->entityTypeManager()->getStorage($entity->getEntityTypeId());
      $revision_id = $storage->getLatestTranslationAffectedRevisionId($entity->id(), $source->getId());
      if ($revision_id != $entity->getRevisionId()) {
        $entity = $storage->loadRevision($revision_id);
      }
    }

    // @todo Exploit the upcoming hook_entity_prepare() when available.
    // See https://www.drupal.org/node/1810394.
    $this->prepareTranslation($entity, $source, $target);

    $get_params = [
      'translation_mode' => TRUE,
      'langcode' => $target->getId(),
      'source' => $source->getId(),
      'target' => $target->getId(),
      'translation_form' => !$entity->access('update'),
    ];

    $entity->in_preview = TRUE;
    $entity->preview_view_mode = 'full';
    $this->tempstore->set($entity);

    $route_name = 'mercury_editor.editor';
    $route_parameters = [
      'mercury_editor_entity' => $entity->uuid(),
    ];
    return $this->redirect($route_name, $route_parameters, ['query' => $get_params]);
  }

}
