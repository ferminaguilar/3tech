<?php

namespace Drupal\mercury_editor;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\layout_paragraphs\LayoutParagraphsComponent;

/**
 * Service for managing Mercury Editor component icons.
 *
 * This service centralizes logic for retrieving and managing component icons
 * throughout the Mercury Editor application.
 */
class MercuryEditorComponentIcons {

  use StringTranslationTrait;

  private static $defaultComponentIconImage = '/images/component-icons/component-icon--component.svg';
  private static $defaultLayoutIconImage = '/images/component-icons/component-icon--section.svg';

  /**
   * Cached outline component icons settings.
   *
   * @var array
   */
  protected $outlineComponentIconsSettings = [];

  /**
   * Constructs a MercuryEditorComponentIcon service.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entityTypeBundleInfo
   *   The entity type bundle info service.
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleExtensionList
   *   The module extension list service.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    protected ModuleExtensionList $moduleExtensionList,
  ) {}

  /**
   * Get outline component icons settings.
   *
   * @return array
   *   The outline component icons configuration.
   */
  public function getOutlineComponentIconsSettings() {
    if (empty($this->outlineComponentIconsSettings)) {
      $config = $this->configFactory->get('mercury_editor.settings');
      $configured_icons = $config->get('outline_component_icons') ?? [];

      // Get all paragraph bundles and ensure each has a value.
      $paragraph_bundles = $this->entityTypeBundleInfo->getBundleInfo('paragraph');

      foreach ($paragraph_bundles as $bundle => $bundle_info) {
        // If this bundle doesn't have a configured icon, set a default.
        if (!isset($configured_icons[$bundle])) {
          $configured_icons[$bundle] = $this->getDefaultIconForBundle($bundle);
        }
      }

      $this->outlineComponentIconsSettings = $configured_icons;
    }
    return $this->outlineComponentIconsSettings;
  }

  /**
   * Get the default icon for a specific bundle.
   *
   * @param string $bundle
   *   The paragraph bundle machine name.
   *
   * @return string
   *   The default icon name.
   */
  protected function getDefaultIconForBundle($bundle) {
    $is_layout = FALSE;
    $has_custom_icon = FALSE;

    try {
      $paragraph_type = $this->entityTypeManager->getStorage('paragraphs_type')->load($bundle);
      if ($paragraph_type && $paragraph_type instanceof \Drupal\paragraphs\ParagraphsTypeInterface) {
        $is_layout = $paragraph_type->hasEnabledBehaviorPlugin('layout_paragraphs');
        $has_custom_icon = !empty($paragraph_type->getIconUrl());
      }
    }
    catch (\Exception $e) {
      // Silently handle any errors loading the paragraph type.
    }

    // If the paragraph has a custom icon set, use '_default'.
    // Otherwise, use 'section' for layouts or 'component' for regular paragraphs.
    if ($has_custom_icon) {
      return '_default';
    }

    return $is_layout ? 'section' : 'component';
  }

  /**
   * Get the outline icon for the bundle of the given component.
   *
   * @param \Drupal\layout_paragraphs\LayoutParagraphsComponent $component
   *   The component to get the bundle icon for.
   *
   * @return string
   *   The icon name.
   */
  public function getBundleOutlineIcon(LayoutParagraphsComponent $component) {
    $entity = $component->getEntity();
    $entity_type_id = $entity->getEntityTypeId();
    $bundle = $entity->bundle();

    return $this->getBundleOutlineIconByBundle($bundle, $entity_type_id);
  }

  /**
   * Get the outline icon for a bundle by bundle name.
   *
   * @param string $bundle
   *   The bundle machine name.
   * @param string $entity_type_id
   *   The entity type ID (defaults to 'paragraph').
   *
   * @return string
   *   The icon name.
   */
  public function getBundleOutlineIconByBundle($bundle, $entity_type_id = 'paragraph') {
    // Get configured icons from Mercury Editor settings.
    $outline_component_icons = $this->getOutlineComponentIconsSettings();

    // If there's a configured icon for this bundle, use it.
    if (isset($outline_component_icons[$bundle])) {
      $icon_value = $outline_component_icons[$bundle];
      // Convert _default to default for HTML attribute consistency
      return $icon_value;
    }

    // Check bundle info for outline_icon setting.
    $bundle_info = $this->entityTypeBundleInfo->getBundleInfo($entity_type_id);
    if (isset($bundle_info[$bundle]['outline_icon'])) {
      return $bundle_info[$bundle]['outline_icon'];
    }

    // Default icons based on component type.
    // Check if this paragraph type has layout_paragraphs behavior enabled.
    $is_layout = FALSE;
    if ($entity_type_id === 'paragraph') {
      try {
        $paragraph_type = $this->entityTypeManager->getStorage('paragraphs_type')->load($bundle);
        if ($paragraph_type && $paragraph_type instanceof \Drupal\paragraphs\ParagraphsTypeInterface) {
          $is_layout = $paragraph_type->hasEnabledBehaviorPlugin('layout_paragraphs');
        }
      }
      catch (\Exception $e) {
        // Silently handle any errors loading the paragraph type.
      }
    }

    if ($is_layout) {
      return 'layout';
    }

    return 'component';
  }

  /**
   * Get available icon options for configuration forms.
   *
   * @return array
   *   An array of icon options keyed by machine name with translated labels.
   */
  public function getIconOptions() {
    return [
      '_default' => $this->t('Default'),
      'button' => $this->t('Button'),
      'card' => $this->t('Card'),
      'component' => $this->t('Component'),
      'divider' => $this->t('Divider'),
      'gallery' => $this->t('Gallery'),
      'grid-6' => $this->t('Grid 6'),
      'grid-9' => $this->t('Grid 9'),
      'heading' => $this->t('Heading'),
      'image' => $this->t('Image'),
      'image-with-download' => $this->t('Image with Download'),
      'section' => $this->t('Section'),
      'location' => $this->t('Location'),
      'outline' => $this->t('Outline'),
      'outline-stagger' => $this->t('Outline Stagger'),
      'quote' => $this->t('Quote'),
      'region' => $this->t('Region'),
      'star' => $this->t('Star'),
      'text' => $this->t('Text'),
      'user' => $this->t('User'),
      'video' => $this->t('Video'),
    ];
  }

  /**
   * Get the default icon URL for a paragraph type.
   *
   * @param string $bundle_id
   *   The paragraph bundle ID.
   *
   * @return string|null
   *   The default icon URL, or NULL if no icon is configured.
   */
  public function getParagraphDefaultIconUrl($bundle_id) {
    // Load the paragraph type entity to get its icon.
    try {
      $paragraph_type = $this->entityTypeManager->getStorage('paragraphs_type')->load($bundle_id);
      if ($paragraph_type && $paragraph_type instanceof \Drupal\paragraphs\ParagraphsTypeInterface) {
        $icon_url = $paragraph_type->getIconUrl();
        if ($icon_url) {
          return $icon_url;
        }

        // If no icon is set, return the default icon based on whether it's a layout or component.
        $is_layout = $paragraph_type->hasEnabledBehaviorPlugin('layout_paragraphs');
        $module_path = $this->moduleExtensionList->getPath('mercury_editor');
        $icon_path = $is_layout ? self::$defaultLayoutIconImage : self::$defaultComponentIconImage;
        return '/' . $module_path . $icon_path;
      }
    }
    catch (\Exception $e) {
      // Silently handle any errors loading the paragraph type.
    }

    return NULL;
  }

  /**
   * Get paragraph bundle info with default icon added.
   *
   * @param string $bundle_id
   *   The paragraph bundle ID.
   * @param array $bundle_info
   *   The paragraph bundle info.
   *
   * @return array
   *   The paragraph bundle info with default icon added.
   */
  public function getParagraphBundleWithDefaultIcon($bundle_id, array $bundle_info) {
    $bundle_info['default_icon'] = $this->getParagraphDefaultIconUrl($bundle_id);
    return $bundle_info;
  }

  /**
   * Clear the cached outline component icons settings.
   *
   * This method can be called when configuration changes to ensure fresh data.
   */
  public function clearCache() {
    $this->outlineComponentIconsSettings = [];
  }

  /**
   * Generate dynamic CSS for default icon custom properties.
   *
   * Creates CSS rules for paragraph types that have '_default' selected
   * in the outline component icons configuration and have a configured
   * default icon URL.
   *
   * @return string
   *   The CSS string for default icons, or empty string if none needed.
   */
  public function generateDefaultIconStyles() {
    $outline_component_icons = $this->getOutlineComponentIconsSettings();
    $css_rules = [];

    // Only process bundles that have '_default' selected
    foreach ($outline_component_icons as $bundle => $icon_setting) {
      $default_icon_url = $this->getParagraphDefaultIconUrl($bundle);
      if ($default_icon_url) {
        $css_rules[] = sprintf(
          '[data-type="%s"][data-me-icon="_default"] { --me-component-icon-image: url(%s); }',
          $bundle,
          $default_icon_url
        );
      }
    }

    return empty($css_rules) ? '' : implode("\n", $css_rules);
  }

}
