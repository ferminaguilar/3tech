<?php

namespace Drupal\mercury_editor;

use Drupal\layout_paragraphs\Utility\Dialog;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Provides dialog options for Mercury Editor Ajax commands.
 *
 * @package Drupal\mercury_editor
 */
class DialogService {

  /**
   * The dialog configuration.
   *
   * @var array
   */
  protected $config;

  /**
   * Service constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->config = $config_factory->get('mercury_editor.settings')->get('dialog_settings');
  }

  /**
   * Returns an array of dialog settings for modal edit forms.
   *
   * @param array $context
   *   The context array.
   *
   * @return array
   *   The modal settings.
   */
  public function dialogSettings(array $context = []) {
    // Global defaults.
    $modal_settings = $this->config['_defaults'] ?? [];
    // Dialog or dock defaults depending on context.
    $modal_settings = empty($context['dock'])
      ? array_merge($modal_settings, $this->config['_dialog_defaults'] ?? [])
      : array_merge($modal_settings, $this->config['_dock_defaults'] ?? []);
    // Specific dialog settings (e.g. 'text_form', 'component_menu').
    if (!empty($context['dialog'])) {
      $modal_settings = array_merge(
        $modal_settings,
        $this->config[$context['dialog']] ?? [],
      );
    }
    // Specific dock settings (e.g. 'dock_text_form', 'dock_section_form').
    if (!empty($context['dock'])) {
      $modal_settings['dock'] = $context['dock'];
      $modal_settings = array_merge(
        $modal_settings,
        $this->config['dock_' . $context['dialog']] ?? [],
      );
    }
    // If not a dock, merge settings for "dialog_" + dialog name.
    elseif (!empty($context['dialog'])) {
      $modal_settings = array_merge(
        $modal_settings,
        $this->config['dialog_' . $context['dialog']] ?? [],
      );
    }
    // If a layout is provided in the context, set the target to the layout
    // dialog ID.
    if (!empty($context['layout'])) {
      $modal_settings['target'] = Dialog::dialogId($context['layout']);
    }
    // Component menu dialog special case.
    // If no width is set, default to 'fit-content' to avoid overly wide dialog.
    if (!empty($context['dialog']) && $context['dialog'] === 'component_menu' && empty($this->config['component_menu']['width'])) {
      $modal_settings['width'] = 'fit-content';
    }
    return $modal_settings ?? [];
  }

}
