<?php

namespace Drupal\mercury_editor\Ajax;

use Drupal\Core\Ajax\BaseCommand;

/**
 * Class MercuryEditorUpdateStateCommand.
 *
 * Provides an AJAX command to update the state of the Mercury Editor.
 */
class MercuryEditorSaveSuccessCommand extends BaseCommand {

  /**
   * Constructs a new MercuryEditorUpdateStateCommand.
   */
  public function __construct() {}

  /**
   * {@inheritDoc}
   */
  public function render() {
    return [
      'command' => 'mercuryEditorSaveSuccess',
    ];
  }

}
