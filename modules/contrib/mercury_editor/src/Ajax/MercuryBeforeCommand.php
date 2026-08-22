<?php

namespace Drupal\mercury_editor\Ajax;

use Drupal\Core\Ajax\InsertCommand;

/**
 * Inserts markup before the first element matching a selector.
 */
class MercuryBeforeCommand extends InsertCommand {

  /**
   * Constructs a MercuryBeforeCommand object.
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
      'command' => 'mercuryBefore',
      'selector' => $this->selector,
      'data' => $this->getRenderedContent(),
      'settings' => $this->settings,
    ];
  }

}
