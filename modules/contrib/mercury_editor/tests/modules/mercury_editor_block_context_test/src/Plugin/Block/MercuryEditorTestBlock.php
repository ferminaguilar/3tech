<?php

namespace Drupal\mercury_editor_block_context_test\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a 'Hello' Block.
 *
 * @Block(
 *   id = "mercury_editor_test_block",
 *   admin_label = @Translation("Mercury Editor Test Block"),
 *   category = @Translation("Mercury Editor"),
 * )
 */
class MercuryEditorTestBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      '#markup' => $this->t('Test block for Mercury Editor.'),
    ];
  }

}
