<?php

namespace Drupal\mercury_editor\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfo;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Mercury Editor Edit Tray settings form.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfo
   */
  protected EntityTypeBundleInfo $entityTypeBundleInfo;

  /**
   * The theme handler service.
   *
   * @var \Drupal\Core\Extension\ThemeHandlerInterface
   */
  protected ThemeHandlerInterface $themeHandler;

  /**
   * SettingsForm constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed config service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfo $entity_type_bundle_info
   *   The entity type bundle info service.
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $theme_handler
   *   The theme handler.
   * @param \Drupal\Core\Extension\ThemeExtensionList $themeExtensionList
   *   The theme extension list service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    EntityTypeManagerInterface $entity_type_manager,
    EntityTypeBundleInfo $entity_type_bundle_info,
    ThemeHandlerInterface $theme_handler,
    protected ThemeExtensionList $themeExtensionList,
  ) {
    parent::__construct(
      $config_factory,
      $typedConfigManager
    );
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->themeHandler = $theme_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('theme_handler'),
      $container->get('extension.list.theme'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'mercury_editor_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'mercury_editor.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->configFactory()->getEditable('mercury_editor.settings');
    $config_schema = $this->typedConfigManager->getDefinition('mercury_editor.settings') + ['mapping' => []];
    $config_schema = $config_schema['mapping'];

    $themes = $this->themeExtensionList->getList();

    // Remove obsolete themes.
    $themes = array_filter($themes, function ($theme) {
      return !$theme->isObsolete();
    });
    uasort($themes, [ThemeExtensionList::class, 'sortByName']);
    $mercury_theme_options = [];

    foreach ($themes as &$theme) {
      if (!empty($theme->status)) {
        $mercury_theme_options[$theme->getName()] = $theme->info['name'] . ($theme->isExperimental() ? ' (' . $this->t('Experimental') . ')' : '');
      }
    }

    $form['theme'] = [
      '#type' => 'select',
      '#options' => ['' => $this->t('Default theme')] + $mercury_theme_options,
      '#title' => $this->t('Mercury Editor Edit Tray theme'),
      '#description' => $this->t('Choose "Default theme" to always use the same theme as the admin theme.'),
      '#default_value' => $config->get('edit_screen_theme'),
    ];

    $form['bundles'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Bundles'),
      '#tree' => TRUE,
      '#description' => $this->t('Choose which bundles should use the Mercury Editor Edit Tray.'),
    ];
    $default_bundle_values = $config->get('bundles');
    // @todo Expand the list of supported entity types.
    $supported_entity_types = array_filter($this->entityTypeManager->getDefinitions(), function ($definition) {
      return $definition->getFormClass('mercury_editor') !== NULL;
    });
    foreach ($supported_entity_types as $entity_type => $definition) {
      // Get all bundles for this entity type.
      $options = [];
      foreach ($this->entityTypeBundleInfo->getBundleInfo($entity_type) as $bundle_id => $bundle) {
        $options[$bundle_id] = $bundle['label'];
      }
      $form['bundles'][$entity_type] = [
        '#type' => 'checkboxes',
        '#tree' => TRUE,
        '#title' => $definition->getLabel(),
        '#options' => $options,
        '#default_value' => $default_bundle_values[$entity_type] ?? [],
      ];
    }

    $mobile_presets = $config->get('mobile_presets') ?? [];
    $mobile_presets_default_value = '';
    foreach ($mobile_presets as $preset) {
      $mobile_presets_default_value .= $preset['name'] . '|' . $preset['width'] . '|' . $preset['height'] . "\n";
    }
    $form['mobile_presets'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Mobile presets'),
      '#description' => $this->t('Enter a list of mobile presets, one per line, in the format "name|width|height".'),
      '#default_value' => $mobile_presets_default_value,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->configFactory()->getEditable('mercury_editor.settings');
    $config->set('edit_screen_theme', $form_state->getValue('theme'));
    $bundles = $form_state->getValue('bundles');

    $bundle_values = [];
    foreach ($bundles as $entity_type => $bundles) {
      $bundle_values[$entity_type] = array_filter($bundles);
    }
    $config->set('bundles', $bundle_values);

    $mobile_presets = [];
    foreach (explode("\n", $form_state->getValue('mobile_presets')) as $preset) {
      $preset = explode('|', $preset);
      if (count($preset) === 3) {
        $mobile_presets[] = [
          'name' => trim($preset[0]),
          'width' => intval($preset[1]),
          'height' => intval($preset[2]),
        ];
      }
    }
    $config->set('mobile_presets', $mobile_presets);
    $config->save();
    // Confirmation on form submission.
    $this->messenger()->addMessage($this->t('Mercury Editor Edit Tray settings have been saved.'));
  }

}
