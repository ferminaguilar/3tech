<?php

namespace Drupal\mercury_editor\Controller;

/**
 * Provides a controller for editing block content in Mercury Editor.
 */
class MercuryEditorBlockContentController extends MercuryEditorController {

  /**
   * Sets the Mercury Editor preview context to true.
   *
   * This controller returns an empty array and expects the actual block to be
   * rendered by the block layout system.
   *
   * @return array
   *   An empty array.
   */
  public function preview() {
    $this->mercuryEditorContext->setPreview(TRUE);
    return [];
  }

}
