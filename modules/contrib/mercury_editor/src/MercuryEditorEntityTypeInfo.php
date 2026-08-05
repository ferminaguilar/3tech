<?php

namespace Drupal\mercury_editor;

use Drupal\Core\Form\FormInterface;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\content_moderation\EntityTypeInfo;

/**
 * Provides entity type information for content moderation.
 *
 * Note that this class extends an @internal class to provide
 * additional functionality specific to the Mercury Editor context.
 *
 * @phpstan-ignore-next-line
 */
class MercuryEditorEntityTypeInfo extends EntityTypeInfo {

  /**
   * {@inheritDoc}
   */
  protected function isModeratedEntityEditForm(FormInterface $form_object) {
    return parent::isModeratedEntityEditForm($form_object) ||
      (
        $form_object instanceof ContentEntityFormInterface &&
        $form_object->getOperation() == 'mercury_editor' &&
        $this->moderationInfo->isModeratedEntity($form_object->getEntity())
      );
  }

}
