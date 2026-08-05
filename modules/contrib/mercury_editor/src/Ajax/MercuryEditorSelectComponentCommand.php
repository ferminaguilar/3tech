<?php

namespace Drupal\mercury_editor\Ajax;

use Drupal\Core\Ajax\BaseCommand;

/**
 * Class MercuryEditorSelectComponentCommand.
 *
 * Provides an AJAX command to select a component in the Mercury Editor.
 */
class MercuryEditorSelectComponentCommand extends BaseCommand {

  /**
   * Constructs a new MercuryEditorSelectComponentCommand.
   *
   * @param string $componentUuid
   *   The UUID of the component to select.
   * @param bool $forceReload
   *   (optional) Whether to force reload the component. Defaults to FALSE.
   */
  public function __construct(
    protected string $componentUuid,
    protected bool $forceReload = FALSE,
  ) {

  }

  /**
   * Executes the command to select a component in the Mercury Editor.
   *
   * @return array
   *   An array containing the updated state index and count.
   */
  public function render() {
    return [
      'command' => 'mercuryEditorSelectComponent',
      'uuid' => $this->componentUuid,
      'forceReload' => $this->forceReload,
    ];
  }

}
