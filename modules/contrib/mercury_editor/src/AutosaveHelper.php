<?php

namespace Drupal\mercury_editor;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Helper service for checking autosave settings.
 */
class AutosaveHelper {

  /**
   * Constructs an AutosaveHelper object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Checks if autosave is enabled for a given paragraph bundle.
   *
   * @param string $bundle
   *   The paragraph bundle ID.
   *
   * @return bool
   *   TRUE if autosave is enabled for the bundle, FALSE otherwise.
   */
  public function isEnabled(string $bundle): bool {
    $settings = $this->configFactory->get('mercury_editor.settings')->get('auto_update') ?? [];
    $enabled = ($settings['enabled'] ?? TRUE) !== FALSE;
    $paragraph_overrides = $settings['paragraphs'] ?? [];
    return $enabled && empty($paragraph_overrides[$bundle]);
  }

}
