<?php

namespace Drupal\mercury_editor\Controller;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Provides a controller for editing block content in Mercury Editor.
 */
class MercuryEditorBlockContentController extends MercuryEditorController {

  /**
   * {@inheritdoc}
   */
  public function view(EntityInterface $_entity, $view_mode = 'full') {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function initializePreviewState(ContentEntityInterface $entity): void {
    if (!$this->tempstore->getStatesCount($entity)) {
      $this->tempstore->saveState($entity);
    }
  }

}
