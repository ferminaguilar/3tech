<?php

namespace Drupal\mercury_editor_startup_demo\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Rejects entities with "Invalid" in the title.
 *
 * @Constraint(
 *   id = "InvalidTitle",
 *   label = @Translation("Invalid title constraint", context = "Validation"),
 *   type = "string"
 * )
 */
class InvalidTitleConstraint extends Constraint {

  /**
   * The default violation message.
   *
   * @var string
   */
  public $message = 'The title cannot contain the word "Invalid".';

}
