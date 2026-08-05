<?php

namespace Drupal\mercury_editor\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures auto-update (autosave) settings for Mercury Editor.
 */
class AutoUpdateSettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'mercury_editor_auto_update_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['mercury_editor.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('mercury_editor.settings');
    $auto_update = $config->get('auto_update') ?? [];
    $enabled = $auto_update['enabled'] ?? TRUE;
    $paragraph_overrides = $auto_update['paragraphs'] ?? [];

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable auto-update'),
      '#default_value' => $enabled,
      '#description' => $this->t('When enabled, Mercury Editor automatically saves paragraph changes. Disable to require manual saves.'),
    ];

    $form['paragraphs'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Paragraph bundle overrides'),
      '#description' => $this->t('Disable auto-update for specific paragraph types.'),
      '#tree' => TRUE,
    ];

    $paragraph_types = $this->entityTypeManager->getStorage('paragraphs_type')->loadMultiple();
    foreach ($paragraph_types as $id => $type) {
      $form['paragraphs'][$id] = [
        '#type' => 'checkbox',
        '#title' => $type->label(),
        '#default_value' => $paragraph_overrides[$id] ?? FALSE,
        '#description' => $this->t('Disable auto-update for @label.', ['@label' => $type->label()]),
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('mercury_editor.settings');
    $config->set('auto_update', [
      'enabled' => (bool) $form_state->getValue('enabled'),
      'paragraphs' => array_filter($form_state->getValue('paragraphs') ?? []),
    ]);
    $config->save();
    $this->messenger()->addMessage($this->t('Mercury Editor auto-update settings have been saved.'));
  }

}
