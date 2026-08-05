<?php

namespace Drupal\mercury_editor_templates\Entity;

use Drupal\mercury_editor\Entity\MercuryEditorEntityFormTrait;

/**
 * Defines a Mercury Editor form controller for ME templates.
 */
class MercuryEditorMeTemplateForm extends MeTemplateForm {

  use MercuryEditorEntityFormTrait;

  /**
   * {@inheritDoc}
   */
  public function setDefaultEntityValues() {
    $this->entity->label = $this->t('New template');
  }

}
