<?php

declare(strict_types=1);

namespace Drupal\style_options\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Define the attribute option plugin.
 *
 * @Annotation
 */
class StyleOptionPlugin extends Plugin {

  /**
   * The plugin ID.
   *
   * @var string
   */
  public $id;

  /**
   * The plugin label.
   *
   * @var string
   */
  public $label;

}
