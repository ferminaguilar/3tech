<?php

declare(strict_types=1);

namespace Drupal\mercury_editor\Ajax;

use Drupal\Core\Ajax\InsertCommand;

/**
 * Morphs existing content using server-rendered markup.
 */
final class MercuryMorphCommand extends InsertCommand {

  /**
   * Constructs a MercuryMorphCommand object.
   *
   * @param string|null $selector
   *   A CSS selector.
   * @param string|array $content
   *   The content that will be inserted in the matched element(s), either a
   *   render array or an HTML string.
   * @param array $settings
   *   An array of JavaScript settings to be passed to any attached behaviors.
   */
  public function __construct($selector, $content, ?array $settings = NULL) {
    if (is_array($content)) {
      $content['#attached']['library'][] = 'mercury_editor/mercury_morph';
    }
    parent::__construct($selector, $content, $settings);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'command' => 'mercuryMorph',
      'selector' => $this->selector,
      'data' => $this->getRenderedContent(),
      'settings' => $this->settings,
    ];
  }

}
