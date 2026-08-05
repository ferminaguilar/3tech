<?php

namespace Drupal\mercury_editor\Ajax;

use Drupal\Core\Ajax\BaseCommand;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\mercury_editor\MercuryEditorTempstore;

/**
 * Class MercuryEditorUpdateStateCommand.
 *
 * Provides an AJAX command to update the state of the Mercury Editor.
 */
class MercuryEditorUpdateStateCommand extends BaseCommand {

  /**
   * Constructs a new MercuryEditorUpdateStateCommand.
   *
   * @param \Drupal\mercury_editor\MercuryEditorTempstore $mercuryEditorTempstore
   *   The Mercury Editor tempstore service.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity for which the state is being updated.
   */
  public function __construct(
    protected MercuryEditorTempstore $mercuryEditorTempstore,
    protected ContentEntityInterface $entity,
  ) {

  }

  /**
   * Executes the command to update the Mercury Editor state.
   *
   * @return array
   *   An array containing the updated state index and count.
   */
  public function render() {
    return [
      'command' => 'mercuryEditorUpdateState',
      'stateIndex' => $this->mercuryEditorTempstore->getStateIndex($this->entity),
      'stateCount' => $this->mercuryEditorTempstore->getStatesCount($this->entity),
    ];
  }

}
