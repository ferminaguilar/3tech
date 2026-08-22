<?php

namespace Drupal\mercury_editor\Ajax;

use Drupal\Core\Ajax\InsertCommand;

/**
 * Prepends markup to the first element matching a selector.
 */
class MercuryPrependCommand extends InsertCommand {

  /**
   * Constructs a MercuryPrependCommand object.
   *
   * @param string|null $selector
   *   A CSS selector.
   * @param string|array $content
   *   The content to insert, either a render array or an HTML string.
   * @param array|null $settings
   *   JavaScript settings passed to attached behaviors.
   */
  public function __construct($selector, $content, ?array $settings = NULL) {
    if (is_array($content)) {
      $content['#attached']['library'][] = 'mercury_editor/mercury_insert';
    }
    parent::__construct($selector, $content, $settings);
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'command' => 'mercuryPrepend',
      'selector' => $this->selector,
      'data' => $this->getRenderedContent(),
      'settings' => $this->settings,
    ];
  }

}
