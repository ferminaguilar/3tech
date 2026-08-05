<?php

namespace Drupal\mercury_editor\Form;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\mercury_editor\MercuryEditorComponentIcons;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Mercury Editor Edit Tray settings form.
 */
class DialogSettingsForm extends ConfigFormBase {

  /**
   * The YAML parser service.
   *
   * @var \Drupal\Component\Serialization\Yaml
   */
  protected $yamlParser;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The Mercury Editor component icon service.
   *
   * @var \Drupal\mercury_editor\Service\MercuryEditorComponentIcon
   */
  protected $componentIcons;

  /**
   * Default values for dialog settings.
   */
  const DEFAULT_TRAY_WIDTH = 400;
  const DEFAULT_TRAY_HEIGHT = 400;
  const DEFAULT_ROLLOVER_PADDING_BLOCK = 10;
  const DEFAULT_ROLLOVER_PADDING_INLINE = 0;

  /**
   * SettingsForm constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed config service.
   * @param \Drupal\Core\Serialization\Yaml $yaml_parser
   *   The YAML parser service.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager service.
   * @param \Drupal\mercury_editor\Service\MercuryEditorComponentIcon $component_icons
   *   The Mercury Editor component icon service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    Yaml $yaml_parser,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    MercuryEditorComponentIcons $component_icons,
  ) {
    parent::__construct(
      $config_factory,
      $typedConfigManager
    );
    $this->yamlParser = $yaml_parser;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->componentIcons = $component_icons;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('serialization.yaml'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('mercury_editor.component_icons'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'mercury_editor_dialog_settings_form';
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
    $settings_obj = $config->get('dialog_settings');

    $form['dialog_settings'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Modal dialog settings'),
      '#rows' => 20,
      '#default_value' => $this->yamlParser->encode($settings_obj),
      '#description' => $this->t('Enter the settings for any modal dialog (YAML format). For detailed configuration help, <br />refer to the <a href="@tips_url">dialog settings tips</a>.', [
        '@tips_url' => Url::fromRoute('mercury_editor.dialog_tips')->toString(),
      ]),
    ];

    $form['dialog_tray_width'] = [
      '#type' => 'number',
      '#title' => $this->t('Initial dialog tray width'),
      '#required' => TRUE,
      '#min' => 0,
      '#description' => $this->t('Enter the width of the dialog tray (pixels).'),
      '#default_value' => $config->get('dialog_tray_width') ?? self::DEFAULT_TRAY_WIDTH,
    ];

    if (class_exists('Drupal\codemirror_editor\Element\CodeMirror')) {
      $form['dialog_settings']['#type'] = 'codemirror';
      $form['dialog_settings']['#codemirror'] = [
        'mode' => 'yaml',
        'lineNumbers' => TRUE,
        'lineWrapping' => TRUE,
        'indentUnit' => 2,
        'indentWithTabs' => FALSE,
        'matchBrackets' => TRUE,
        'autoCloseBrackets' => TRUE,
        'autoCloseTags' => TRUE,
        'styleActiveLine' => TRUE,
        'continueComments' => TRUE,
        'toolbar' => FALSE,
        'extraKeys' => [
          'Ctrl-Space' => 'autocomplete',
        ],
      ];
    }

    $form['rollover_padding_block'] = [
      '#type' => 'number',
      '#title' => $this->t('Rollover block padding'),
      '#required' => TRUE,
      '#min' => 0,
      '#description' => $this->t('Enter block padding (pixels) when rolling over elements. This is used to prevent vertically-adjacent Mercury Editor buttons overlapping.'),
      '#default_value' => $config->get('rollover_padding_block') ?? self::DEFAULT_ROLLOVER_PADDING_BLOCK,
    ];

    $form['rollover_padding_inline'] = [
      '#type' => 'number',
      '#title' => $this->t('Rollover inline padding'),
      '#required' => TRUE,
      '#min' => 0,
      '#description' => $this->t('Enter inline padding (pixels) when rolling over elements. This is used to prevent horizontally-adjacent Mercury Editor buttons overlapping, but will likely cause content reflowing. Most sites can set this to 0.'),
      '#default_value' => $config->get('rollover_padding_inline') ?? self::DEFAULT_ROLLOVER_PADDING_INLINE,
    ];

    // Add outline component icons settings.
    $this->addOutlineComponentIconsSettings($form, $form_state, $config);

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->configFactory()->getEditable('mercury_editor.settings');
    $settings_yml_string = $form_state->getValue('dialog_settings');
    $config->set('dialog_settings', $this->yamlParser->decode($settings_yml_string));
    $config->set('dialog_tray_width', $form_state->getValue('dialog_tray_width'));
    $config->set('rollover_padding_block', $form_state->getValue('rollover_padding_block'));
    $config->set('rollover_padding_inline', $form_state->getValue('rollover_padding_inline'));

    // Save outline component icons.
    $this->saveOutlineComponentIconsSettings($form_state, $config);

    $config->save();
    // Confirmation on form submission.
    $this->messenger()->addMessage($this->t('Mercury Editor dialog settings have been saved.'));
  }

  /**
   * Adds outline component icons settings to the form.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\Core\Config\Config $config
   *   The config object.
   */
  protected function addOutlineComponentIconsSettings(array &$form, FormStateInterface $form_state, $config) {
    $paragraph_bundles = $this->getMercuryEditorParagraphBundles();
    $current_settings = $this->componentIcons->getOutlineComponentIconsSettings();

    if (empty($paragraph_bundles)) {
      return;
    }

    $form['component_icons'] = [
      '#type' => 'details',
      '#title' => $this->t('Component Outline Icons'),
      '#description' => $this->t('Configure icons for paragraph bundles shown in the Mercury Editor interface. The default icon will use the icon image set on the paragraph bundle settings page, or a generic icon if none is set.'),
      '#open' => FALSE,
    ];

    $form['component_icons']['icons'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Paragraph Type'),
        $this->t('Icon'),
      ],
    ];

    $icon_options = $this->componentIcons->getIconOptions();

    // Group layout paragraphs first, then regular paragraphs.
    $layout_bundles = [];
    $regular_bundles = [];

    foreach ($paragraph_bundles as $bundle => $bundle_info) {
      if ($this->isLayoutParagraphBundle($bundle)) {
        $layout_bundles[$bundle] = $bundle_info['label'];
      }
      else {
        $regular_bundles[$bundle] = $bundle_info['label'];
      }
    }

    // Sort layout bundles alphabetically.
    ksort($layout_bundles);

    if (!empty($layout_bundles)) {
      // Add a separator row between layout and regular paragraphs.
      $form['component_icons']['icons']['layout_header'] = [
        'label' => [
          '#markup' => $this->t('Layouts'),
          '#wrapper_attributes' => [
            'colspan' => 2,
            'header' => TRUE,
          ],
        ],
      ];
    }

    foreach ($layout_bundles as $bundle => $label) {
      $default_icon = $current_settings[$bundle];
      $form['component_icons']['icons'][$bundle] = [
        'label' => [
          '#markup' => $label,
        ],
        'icon' => [
          '#type' => 'mercury_editor_icon_select',
          '#options' => $icon_options,
          '#default_value' => $default_icon,
          '#parents' => ['outline_component_icons', $bundle],
          '#component_id' => $bundle,
        ],
      ];
    }

    // Sort regular bundles alphabetically.
    ksort($regular_bundles);

    if (!empty($layout_bundles) && !empty($regular_bundles)) {
      // Add a separator row between layout and regular paragraphs.
      $form['component_icons']['icons']['component_header'] = [
        'label' => [
          '#markup' => $this->t('Components'),
          '#wrapper_attributes' => [
            'colspan' => 2,
            'header' => TRUE,
          ],
        ],
      ];
    }

    // Add regular paragraphs.
    foreach ($regular_bundles as $bundle => $label) {
      $default_icon = $current_settings[$bundle];
      $form['component_icons']['icons'][$bundle] = [
        'label' => [
          '#markup' => $label,
        ],
        'icon' => [
          '#type' => 'mercury_editor_icon_select',
          '#options' => $icon_options,
          '#default_value' => $default_icon,
          '#parents' => ['outline_component_icons', $bundle],
          '#component_id' => $bundle,
        ],
      ];
    }
  }

  /**
   * Saves outline component icons settings.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param \Drupal\Core\Config\Config $config
   *   The config object.
   */
  protected function saveOutlineComponentIconsSettings(FormStateInterface $form_state, $config) {
    $outline_component_icons = $form_state->getValue('outline_component_icons', []);
    $config->set('outline_component_icons', $outline_component_icons);
  }

  /**
   * Gets paragraph bundles configured for use with Mercury Editor.
   *
   * @return array
   *   An array of paragraph bundle information keyed by id.
   */
  protected function getMercuryEditorParagraphBundles() {
    $bundles = [];

    // Get all paragraph bundles.
    $paragraph_bundles = $this->entityTypeBundleInfo->getBundleInfo('paragraph');

    if (empty($paragraph_bundles)) {
      return [];
    }

    // Add default icon information to each bundle.
    foreach ($paragraph_bundles as $bundle_id => $bundle_info) {
      $paragraph_bundles[$bundle_id] = $this->componentIcons->getParagraphBundleWithDefaultIcon($bundle_id, $bundle_info);
    }

    // Get content types where Mercury Editor is enabled.
    $config = $this->configFactory()->get('mercury_editor.settings');
    $me_enabled_entity_types = $config->get('bundles') ?? [];

    if (empty($me_enabled_entity_types)) {
      return [];
    }

    $use_all_paragraph_bundles = FALSE;

    foreach ($me_enabled_entity_types as $entity_type => $entity_bundles) {
      if ($use_all_paragraph_bundles) {
        break;
      }
      foreach ($entity_bundles as $entity_bundle) {
        if ($use_all_paragraph_bundles) {
          break;
        }

        $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type, $entity_bundle);

        if (empty($field_definitions)) {
          continue;
        }

        // Is the field an Entity Reference Revisions field targeting paragraphs?
        foreach ($field_definitions as $definition) {
          if ($definition->getType() === 'entity_reference_revisions' &&
              $definition->getSetting('target_type') === 'paragraph') {

            // Which paragraphs are enabled for this field?
            $handler_settings = $definition->getSetting('handler_settings');
            $target_bundles = $handler_settings['target_bundles'] ?? [];
            if (empty($target_bundles)) {
              // If no specific bundles are configured, all paragraph bundles are allowed.
              $bundles = $paragraph_bundles;
              $use_all_paragraph_bundles = TRUE;
              break;
            }
            // Add these bundles to our list.
            $bundles = array_merge($bundles, array_intersect_key($paragraph_bundles, array_flip($target_bundles)));
          }
        }
      }
    }

    return $bundles;
  }

  /**
   * Checks if a paragraph bundle is a layout paragraph.
   *
   * @param string $bundle
   *   The paragraph bundle machine name.
   *
   * @return bool
   *   TRUE if the bundle is a layout paragraph.
   */
  protected function isLayoutParagraphBundle($bundle) {
    // Load the paragraph type configuration entity.
    $paragraph_type = $this->entityTypeManager->getStorage('paragraphs_type')->load($bundle);

    if ($paragraph_type) {
      // Check if this paragraph type has the layout_paragraphs behavior enabled.
      // The behavior plugins are stored in the 'behavior_plugins' property.
      $behavior_plugins = $paragraph_type->behavior_plugins ?? [];

      if (isset($behavior_plugins['layout_paragraphs']) &&
          !empty($behavior_plugins['layout_paragraphs']['enabled'])) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
