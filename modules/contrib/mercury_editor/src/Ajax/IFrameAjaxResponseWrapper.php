<?php

namespace Drupal\mercury_editor\Ajax;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CommandInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Drupal\Core\Render\AttachmentsResponseProcessorInterface;
use Drupal\mercury_editor\MercuryEditorContextService;

/**
 * Ajax response wrapper for IFrame.
 */
class IFrameAjaxResponseWrapper extends AjaxResponse {

  /**
   * The theme manager.
   *
   * @var \Drupal\Core\Theme\ThemeManagerInterface
   */
  protected $themeManager;

  /**
   * The site default theme.
   *
   * @var \Drupal\Core\Theme\ActiveTheme
   */
  protected $siteDefaultTheme;

  /**
   * The current active theme.
   *
   * @var \Drupal\Core\Theme\ActiveTheme
   */
  protected $currentActiveTheme;

  /**
   * The ajax response attachments processor.
   *
   * @var \Drupal\Core\Render\AttachmentsResponseProcessorInterface
   */
  protected $ajaxResponseAttachmentsProcessor;

  /**
   * IFrameAjaxResponseWrapper constructor.
   *
   * @param \Drupal\Core\Theme\ThemeManagerInterface $theme_manager
   *   The theme manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Theme\ThemeInitializationInterface $theme_initializer
   *   The theme initializer.
   * @param \Drupal\Core\Render\AttachmentsResponseProcessorInterface $ajax_response_attachments_processor
   *   The ajax response attachments processor.
   * @param \Drupal\mercury_editor\MercuryEditorContextService $mercuryEditorContext
   *   The mercury editor context service.
   */
  public function __construct(
    ThemeManagerInterface $theme_manager,
    ConfigFactoryInterface $config_factory,
    ThemeInitializationInterface $theme_initializer,
    AttachmentsResponseProcessorInterface $ajax_response_attachments_processor,
    protected MercuryEditorContextService $mercuryEditorContext,
  ) {
    parent::__construct(NULL, 200, [], FALSE);
    $default_theme_name = $config_factory->get('system.theme')->get('default');
    $this->siteDefaultTheme = $theme_initializer->getActiveThemeByName($default_theme_name);
    $this->themeManager = $theme_manager;
    $this->currentActiveTheme = $theme_manager->getActiveTheme();
    $this->ajaxResponseAttachmentsProcessor = $ajax_response_attachments_processor;
  }

  /**
   * Add a command to the response.
   *
   * @param \Drupal\Core\Ajax\CommandInterface $command
   *   The command to add.
   * @param bool $prepend
   *   Whether to prepend the command to the list of commands (optional).
   *
   * @return \Drupal\mercury_editor\IFrameAjaxResponseWrapper
   *   The current object.
   */
  public function addCommand(CommandInterface $command, $prepend = FALSE) {
    $this->themeManager->setActiveTheme($this->siteDefaultTheme);
    $this->mercuryEditorContext->setPreview(TRUE);
    parent::addCommand($command, $prepend);
    $this->mercuryEditorContext->setPreview(FALSE);
    $this->themeManager->setActiveTheme($this->currentActiveTheme);
    return $this;
  }

  /**
   * Return a wrapper command for all the commands in the response.
   *
   * @return \Drupal\mercury_editor\Ajax\IFrameCommandsWrapperCommand
   *   The wrapper command.
   */
  public function getWrapperCommand() {
    $this->themeManager->setActiveTheme($this->siteDefaultTheme);
    $this->setContent('{}');
    $this->ajaxResponseAttachmentsProcessor->processAttachments($this);
    $command = new IFrameCommandsWrapperCommand($this->getCommands());
    $this->themeManager->setActiveTheme($this->currentActiveTheme);
    return $command;
  }

  /**
   * Delete all commands from the response.
   *
   * @return iFrameAjaxResponseWrapper
   *   The current object.
   */
  public function deleteCommands() {
    $this->commands = [];
    return $this;
  }

}
