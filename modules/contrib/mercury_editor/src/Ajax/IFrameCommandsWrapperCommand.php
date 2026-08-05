<?php

namespace Drupal\mercury_editor\Ajax;

use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\Ajax\CommandInterface;
use Drupal\Core\Ajax\CommandWithAttachedAssetsInterface;

/**
 * Ajax command to dispatch a Mercury Editor Layout  Event.
 */
class IFrameCommandsWrapperCommand implements CommandInterface, CommandWithAttachedAssetsInterface {

  /**
   * An array of commands.
   *
   * @var array
   */
  protected $commands;

  /**
   * Constructor.
   *
   * @param array $commands
   *   An array rendered commands.
   */
  public function __construct(array $commands) {
    $this->commands = $commands;
  }

  /**
   * Render custom ajax command.
   *
   * @return array
   *   The command array.
   */
  public function render() {
    return [
      'command' => 'mercuryEditorEditIframeCommandsWrapper',
      'commands' => $this->commands,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getAttachedAssets() {
    $assets = new AttachedAssets();
    $assets->setLibraries(['mercury_editor/edit_screen.ajax']);
    return $assets;
  }

}
