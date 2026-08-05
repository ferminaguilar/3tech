<?php

namespace Drupal\mercury_editor_templates;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface defining a Mercury Editor template entity type.
 */
interface MeTemplateInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

}
