<?php

namespace Drupal\mercury_editor\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Mercury Editor Edit Tray settings form.
 */
class MenuSettingsForm extends ConfigFormBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

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
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $theme_handler
   *   The theme handler.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    EntityTypeManagerInterface $entity_type_manager,
    ThemeHandlerInterface $theme_handler,
  ) {
    parent::__construct(
      $config_factory,
      $typedConfigManager
    );
    $this->entityTypeManager = $entity_type_manager;
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
      $container->get('theme_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'mercury_editor_menu_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'mercury_editor.menu.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->configFactory()->getEditable('mercury_editor.menu.settings');
    $form['groups'] = [
      '#type' => 'textarea',
      '#rows' => 10,
      '#title' => $this->t('Groups'),
      '#default_value' => $config->get('groups'),
      '#description' => $this->t('Ordered groups and components in YML format.'),
    ];
    if (class_exists('Drupal\codemirror_editor\Element\CodeMirror')) {
      $form['groups']['#type'] = 'codemirror';
      $form['groups']['#codemirror'] = [
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
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->configFactory()->getEditable('mercury_editor.menu.settings');
    $config->set('groups', $form_state->getValue('groups'));
    $config->save();
    // Confirmation on form submission.
    $this->messenger()->addMessage($this->t('Mercury Editor Menu settings have been saved.'));
  }

}
