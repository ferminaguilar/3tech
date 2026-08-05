<?php

namespace Drupal\mercury_editor\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a form for modifying Layout Paragraphs global settings.
 */
class SkipFormSettingsForm extends ConfigFormBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * SettingsForm constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed config service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct(
      $config_factory,
      $typedConfigManager
    );
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'mercury_editor_skip_form_settings_form';
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
    $config = $this->config('mercury_editor.settings');
    $paragraph_types = $this->entityTypeManager->getStorage('paragraphs_type')->loadMultiple();
    $defaults = $config->get('skip_create_form') ?? [];
    $form['skip_create_form'] = [
      '#tree' => TRUE,
      '#type' => 'fieldset',
      '#title' => $this->t('Paragraph Types'),
      '#description' => $this->t('Choose which types to skip content creation forms when using Layout Paragraphs.'),
    ];
    foreach ($paragraph_types as $id => $type) {
      $form['skip_create_form'][$id] = [
        '#type' => 'checkbox',
        '#title' => $type->label(),
        '#default_value' => $defaults[$id] ?? FALSE,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('mercury_editor.settings');
    $config->set('skip_create_form', array_filter($form_state->getValue('skip_create_form')));
    $config->save();
    // Confirmation on form submission.
    $this->messenger()->addMessage($this->t('The Layout Paragraphs Skip Form settings have been saved.'));
  }

}
