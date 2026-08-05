<?php

namespace Drupal\mercury_editor_startup_demo\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Rejects entities with "Invalid" in the title.
 */
class InvalidTitleConstraintValidator extends ConstraintValidator {

  /**
   * {@inheritdoc}
   */
  public function validate($entity, Constraint $constraint) {
    if ($entity->hasField('title')) {
      $title = $entity->get('title')->value;
      if (!empty($title) && is_string($title) && stripos($title, 'Invalid') !== FALSE) {
        // Case insensitive check for the word "Invalid" in the title.
        $this->context->addViolation($constraint->message);
      }
    }
  }

}
